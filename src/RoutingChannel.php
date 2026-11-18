<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * Several channels behind one: a chat, a press or a file goes to the channel whose prefix its id starts with
 * (`ws:` — the WebSocket channel), everything else to the default channel. The application keeps one channel object
 * and gets a second channel without changes in its code.
 */
final class RoutingChannel implements Channel
{
    /** @param array<string, Channel> $byPrefix id prefix => channel */
    public function __construct(
        private readonly Channel $default,
        private readonly array $byPrefix,
    ) {
    }

    private function of(string $id): Channel
    {
        foreach ($this->byPrefix as $prefix => $channel) {
            if ($prefix !== '' && str_starts_with($id, $prefix)) {
                return $channel;
            }
        }
        return $this->default;
    }

    public function name(): string
    {
        return $this->default->name();
    }

    public function send(OutgoingMessage $message): SendResult
    {
        return $this->of($message->chatId)->send($message);
    }

    public function edit(string $chatId, string $messageId, string $text, ParseMode $parseMode = ParseMode::Plain, array $buttons = []): SendResult
    {
        return $this->of($chatId)->edit($chatId, $messageId, $text, $parseMode, $buttons);
    }

    public function answerCallback(string $callbackId, ?string $text = null, bool $alert = false): void
    {
        $this->of($callbackId)->answerCallback($callbackId, $text, $alert);
    }

    public function delete(string $chatId, string $messageId): bool
    {
        return $this->of($chatId)->delete($chatId, $messageId);
    }

    public function leave(string $chatId): bool
    {
        return $this->of($chatId)->leave($chatId);
    }

    public function createTopic(string $chatId, string $name): ?string
    {
        return $this->of($chatId)->createTopic($chatId, $name);
    }

    public function editTopic(string $chatId, string $threadId, string $name): bool
    {
        return $this->of($chatId)->editTopic($chatId, $threadId, $name);
    }

    public function closeTopic(string $chatId, string $threadId): bool
    {
        return $this->of($chatId)->closeTopic($chatId, $threadId);
    }

    public function reopenTopic(string $chatId, string $threadId): bool
    {
        return $this->of($chatId)->reopenTopic($chatId, $threadId);
    }

    public function deleteTopic(string $chatId, string $threadId): bool
    {
        return $this->of($chatId)->deleteTopic($chatId, $threadId);
    }

    public function sendFile(string $chatId, string $fileName, string $content, ?string $caption = null, ?string $threadId = null): SendResult
    {
        return $this->of($chatId)->sendFile($chatId, $fileName, $content, $caption, $threadId);
    }

    public function sendMedia(string $chatId, MediaKind $kind, string $fileName, string $content, ?string $caption = null, ?string $threadId = null): SendResult
    {
        return $this->of($chatId)->sendMedia($chatId, $kind, $fileName, $content, $caption, $threadId);
    }

    public function sendLocation(string $chatId, float $latitude, float $longitude, ?string $threadId = null): SendResult
    {
        return $this->of($chatId)->sendLocation($chatId, $latitude, $longitude, $threadId);
    }

    public function download(string $fileId, int $maxBytes): ?string
    {
        return $this->of($fileId)->download($fileId, $maxBytes);
    }

    public function sendContact(string $chatId, string $phone, string $firstName, ?string $lastName = null, ?string $threadId = null): SendResult
    {
        return $this->of($chatId)->sendContact($chatId, $phone, $firstName, $lastName, $threadId);
    }

    public function sendPoll(string $chatId, string $question, array $options, bool $anonymous = true, bool $multiple = false, ?string $threadId = null): SendResult
    {
        return $this->of($chatId)->sendPoll($chatId, $question, $options, $anonymous, $multiple, $threadId);
    }

    public function typing(string $chatId): void
    {
        $this->of($chatId)->typing($chatId);
    }

    /** What the default channel can do: callers that ask without a chat get the main channel's limits. */
    public function capabilities(): Capabilities
    {
        return $this->default->capabilities();
    }
}
