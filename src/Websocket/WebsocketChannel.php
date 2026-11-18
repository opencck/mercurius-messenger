<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Websocket;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Mercurius\Messenger\Attachment;
use Mercurius\Messenger\AttachmentKind;
use Mercurius\Messenger\Button;
use Mercurius\Messenger\Capabilities;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\MediaKind;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\SendResult;

/**
 * A channel of JSON frames over WebSocket: one private chat per client (`ws:<name>`), frames both ways. The
 * application authenticates the client, attaches its connection to the chat and passes the frames it receives to
 * `receive()`. What is sent while no client of the chat is connected waits in a bounded outbox.
 *
 * To the client: `message` (id, text, parse_mode, buttons, reply_to), `edit`, `delete`, `answer` (to a press),
 * `file`, `location`, `contact`, `poll`, `typing`, `ack` (the id of the client's message).
 * From the client: `message` (text, reply_to, files: [{name, mime, content_base64}]) and `callback` (data, message_id).
 */
final class WebsocketChannel implements Channel
{
    public const NAME = 'ws';
    /** chats of this channel start with it, so a router can tell them from others */
    public const PREFIX = 'ws:';
    public const TEXT_MAX = 65536;

    /** @var array<string, array<int, Connection>> */
    private array $connections = [];
    /** @var array<string, list<string>> frames waiting for a client of the chat */
    private array $outbox = [];
    /** @var array<string, string> files the clients sent, by id, the oldest first */
    private array $files = [];
    private int $fileBytes = 0;
    /** @var array<string, string> the chat of a press, by its id */
    private array $presses = [];
    private int $next = 1;

    public function __construct(
        private readonly int $outboxMax = 200,
        /** bytes of incoming files kept for download, the oldest forgotten first */
        private readonly int $filesMax = 64_000_000,
    ) {
    }

    /** Attaches a client to its chat and hands it what waited; the key detaches it. */
    public function attach(string $chatId, Connection $connection): int
    {
        $key = $this->next++;
        $this->connections[$chatId][$key] = $connection;
        $waiting = $this->outbox[$chatId] ?? [];
        unset($this->outbox[$chatId]);
        foreach ($waiting as $frame) {
            $connection->send($frame);
        }
        return $key;
    }

    public function detach(string $chatId, int $key): void
    {
        unset($this->connections[$chatId][$key]);
        if (($this->connections[$chatId] ?? []) === []) {
            unset($this->connections[$chatId]);
        }
    }

    /**
     * A frame of a client: a message or a press of a button.
     *
     * @throws InvalidArgumentException when the frame is not one of them
     */
    public function receive(string $chatId, string $userId, ?string $displayName, string $frame): IncomingMessage|IncomingCallback
    {
        try {
            $data = json_decode($frame, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('a frame is JSON');
        }
        if (!\is_array($data)) {
            throw new InvalidArgumentException('a frame is an object');
        }
        $type = $data['type'] ?? null;
        if ($type === 'callback') {
            $payload = $data['data'] ?? null;
            if (!\is_string($payload) || $payload === '' || \strlen($payload) > 256) {
                throw new InvalidArgumentException('callback: data');
            }
            $id = self::PREFIX . 'cb' . $this->next++;
            $this->presses[$id] = $chatId;
            if (\count($this->presses) > 1000) {
                unset($this->presses[array_key_first($this->presses)]);
            }
            return new IncomingCallback(self::NAME, $id, $chatId, $userId, $displayName, self::str($data, 'message_id'), $payload, $id);
        }
        if ($type !== 'message') {
            throw new InvalidArgumentException('type: message or callback');
        }
        $text = $data['text'] ?? '';
        if (!\is_string($text) || mb_strlen($text) > self::TEXT_MAX) {
            throw new InvalidArgumentException('message: text');
        }
        $attachments = [];
        $files = $data['files'] ?? [];
        if (!\is_array($files) || \count($files) > 10) {
            throw new InvalidArgumentException('message: files, at most 10');
        }
        foreach (array_keys($files) as $index) {
            $file = $files[$index];
            if (!\is_array($file)) {
                throw new InvalidArgumentException('file: an object');
            }
            $attachments[] = $this->keep($file);
        }
        if ($text === '' && $attachments === []) {
            throw new InvalidArgumentException('message: text or files');
        }
        $replyTo = self::str($data, 'reply_to');
        $id = 'c' . $this->next++;
        $this->deliver($chatId, ['type' => 'ack', 'id' => $id], false);
        return new IncomingMessage(
            self::NAME,
            $chatId,
            $userId,
            null,
            $displayName,
            $text,
            $id,
            $replyTo,
            true,
            new DateTimeImmutable(),
            self::PREFIX . $id . ':' . bin2hex(random_bytes(4)),
            attachments: $attachments,
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function send(OutgoingMessage $message): SendResult
    {
        $id = 'm' . $this->next++;
        $this->deliver($message->chatId, [
            'type' => 'message',
            'id' => $id,
            'text' => $message->text,
            'parse_mode' => $message->parseMode->value,
            'buttons' => self::buttons($message->buttons),
            'reply_to' => $message->replyToMessageId,
        ]);
        return SendResult::ok($id);
    }

    public function edit(string $chatId, string $messageId, string $text, \Mercurius\Messenger\ParseMode $parseMode = \Mercurius\Messenger\ParseMode::Plain, array $buttons = []): SendResult
    {
        $this->deliver($chatId, ['type' => 'edit', 'id' => $messageId, 'text' => $text, 'buttons' => self::buttons($buttons)]);
        return SendResult::ok($messageId);
    }

    public function answerCallback(string $callbackId, ?string $text = null, bool $alert = false): void
    {
        $chatId = $this->presses[$callbackId] ?? null;
        unset($this->presses[$callbackId]);
        if ($chatId !== null) {
            $this->deliver($chatId, ['type' => 'answer', 'callback_id' => $callbackId, 'text' => $text, 'alert' => $alert], false);
        }
    }

    public function delete(string $chatId, string $messageId): bool
    {
        $this->deliver($chatId, ['type' => 'delete', 'id' => $messageId]);
        return true;
    }

    public function leave(string $chatId): bool
    {
        return false;
    }

    public function createTopic(string $chatId, string $name): ?string
    {
        return null;
    }

    public function editTopic(string $chatId, string $threadId, string $name): bool
    {
        return false;
    }

    public function closeTopic(string $chatId, string $threadId): bool
    {
        return false;
    }

    public function reopenTopic(string $chatId, string $threadId): bool
    {
        return false;
    }

    public function deleteTopic(string $chatId, string $threadId): bool
    {
        return false;
    }

    public function sendFile(string $chatId, string $fileName, string $content, ?string $caption = null, ?string $threadId = null): SendResult
    {
        return $this->sendMedia($chatId, MediaKind::Document, $fileName, $content, $caption, $threadId);
    }

    public function sendMedia(string $chatId, MediaKind $kind, string $fileName, string $content, ?string $caption = null, ?string $threadId = null): SendResult
    {
        $id = 'm' . $this->next++;
        $this->deliver($chatId, ['type' => 'file', 'id' => $id, 'kind' => $kind->value, 'name' => $fileName, 'caption' => $caption, 'content_base64' => base64_encode($content)]);
        return SendResult::ok($id);
    }

    public function sendLocation(string $chatId, float $latitude, float $longitude, ?string $threadId = null): SendResult
    {
        $id = 'm' . $this->next++;
        $this->deliver($chatId, ['type' => 'location', 'id' => $id, 'latitude' => $latitude, 'longitude' => $longitude]);
        return SendResult::ok($id);
    }

    public function download(string $fileId, int $maxBytes): ?string
    {
        $bytes = $this->files[$fileId] ?? null;
        return $bytes === null || \strlen($bytes) > $maxBytes ? null : $bytes;
    }

    public function sendContact(string $chatId, string $phone, string $firstName, ?string $lastName = null, ?string $threadId = null): SendResult
    {
        $id = 'm' . $this->next++;
        $this->deliver($chatId, ['type' => 'contact', 'id' => $id, 'phone' => $phone, 'first_name' => $firstName, 'last_name' => $lastName]);
        return SendResult::ok($id);
    }

    public function sendPoll(string $chatId, string $question, array $options, bool $anonymous = true, bool $multiple = false, ?string $threadId = null): SendResult
    {
        $id = 'm' . $this->next++;
        $this->deliver($chatId, ['type' => 'poll', 'id' => $id, 'question' => $question, 'options' => $options, 'multiple' => $multiple]);
        return SendResult::ok($id);
    }

    public function typing(string $chatId): void
    {
        $this->deliver($chatId, ['type' => 'typing'], false);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(true, self::TEXT_MAX, true);
    }

    /** @param array<array-key, mixed> $file */
    private function keep(array $file): Attachment
    {
        $given = self::str($file, 'name');
        $content = self::str($file, 'content_base64');
        if ($given === null || $content === null) {
            throw new InvalidArgumentException('file: name and content_base64');
        }
        $bytes = base64_decode($content, true);
        if ($bytes === false) {
            throw new InvalidArgumentException('file: content_base64');
        }
        $name = basename(str_replace('\\', '/', $given));
        $mime = self::str($file, 'mime');
        $id = self::PREFIX . 'f' . $this->next++;
        $this->files[$id] = $bytes;
        $this->fileBytes += \strlen($bytes);
        while ($this->fileBytes > $this->filesMax && \count($this->files) > 1) {
            $oldest = array_key_first($this->files);
            $this->fileBytes -= \strlen($this->files[$oldest]);
            unset($this->files[$oldest]);
        }
        $kind = match (true) {
            $mime !== null && str_starts_with($mime, 'image/') => AttachmentKind::Photo,
            $mime !== null && str_starts_with($mime, 'audio/') => AttachmentKind::Audio,
            $mime !== null && str_starts_with($mime, 'video/') => AttachmentKind::Video,
            default => AttachmentKind::Document,
        };
        return new Attachment($kind, $id, $name === '' ? 'file' : $name, $mime, \strlen($bytes));
    }

    /** @param array<array-key, mixed> $data */
    private static function str(array $data, string $key): ?string
    {
        return isset($data[$key]) && \is_string($data[$key]) ? $data[$key] : null;
    }

    /**
     * @param array<string, mixed> $frame
     * @param bool $keep whether the frame waits for a client when none is connected
     */
    private function deliver(string $chatId, array $frame, bool $keep = true): void
    {
        $text = json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $sent = false;
        foreach ($this->connections[$chatId] ?? [] as $key => $connection) {
            if ($connection->send($text)) {
                $sent = true;
            } else {
                $this->detach($chatId, $key);
            }
        }
        if ($sent || !$keep) {
            return;
        }
        $this->outbox[$chatId][] = $text;
        if (\count($this->outbox[$chatId]) > $this->outboxMax) {
            array_shift($this->outbox[$chatId]);
        }
    }

    /**
     * @param list<list<Button>> $rows
     * @return list<list<array{text: string, data: string}>>
     */
    private static function buttons(array $rows): array
    {
        return array_map(static fn(array $row): array => array_map(static fn(Button $b): array => ['text' => $b->text, 'data' => $b->data], $row), $rows);
    }
}
