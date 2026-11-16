<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Telegram;

use DateTimeImmutable;
use DateTimeZone;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\IncomingMessage;

/**
 * Converts a decoded Telegram `Update` into an `IncomingMessage`.
 *
 * Only `message` (and `edited_message` when enabled) produce a message. Channel posts, callbacks, messages from
 * bots (including the bot itself) and malformed updates give null. The input is untrusted: every field is
 * type-checked.
 */
final class UpdateParser
{
    /**
     * @param int|null $botId id of this bot (from `getMe`); its own messages are ignored
     * @param bool $includeEdited return edited messages with `isEdited = true` instead of ignoring them
     */
    public function __construct(
        private readonly ?int $botId = null,
        private readonly bool $includeEdited = false,
        private readonly string $channelName = TelegramChannel::NAME,
    ) {
    }

    /**
     * @param array<array-key, mixed> $update
     */
    public function parse(array $update): ?IncomingMessage
    {
        $updateId = self::updateId($update);
        if ($updateId === null) {
            return null;
        }

        $isEdited = !isset($update['message']) && $this->includeEdited && isset($update['edited_message']);
        $message = self::arrayOrNull($update[$isEdited ? 'edited_message' : 'message'] ?? null);
        if ($message === null) {
            return null;
        }

        $chat = self::arrayOrNull($message['chat'] ?? null);
        $from = self::arrayOrNull($message['from'] ?? null);
        if ($chat === null || $from === null) {
            return null;
        }

        $chatId = self::id($chat['id'] ?? null);
        $userId = self::id($from['id'] ?? null);
        $messageId = self::id($message['message_id'] ?? null);
        if ($chatId === null || $userId === null || $messageId === null) {
            return null;
        }
        if (($from['is_bot'] ?? false) === true || ($this->botId !== null && $userId === (string) $this->botId)) {
            return null;
        }

        $reply = self::arrayOrNull($message['reply_to_message'] ?? null);

        return new IncomingMessage(
            channel: $this->channelName,
            chatId: $chatId,
            userId: $userId,
            userName: self::nonEmptyString($from['username'] ?? null),
            displayName: self::displayName($from),
            text: self::nonEmptyString($message['text'] ?? null) ?? self::nonEmptyString($message['caption'] ?? null) ?? '',
            messageId: $messageId,
            replyToMessageId: $reply === null ? null : self::id($reply['message_id'] ?? null),
            isPrivateChat: ($chat['type'] ?? null) === 'private',
            receivedAt: self::date($message['date'] ?? null),
            updateId: (string) $updateId,
            threadId: self::id($message['message_thread_id'] ?? null),
            isEdited: $isEdited,
            raw: $message,
        );
    }

    /**
     * A button press (`callback_query`), or null when the update is something else. Presses from bots are dropped.
     *
     * @param array<array-key, mixed> $update
     */
    public function parseCallback(array $update): ?IncomingCallback
    {
        $updateId = self::updateId($update);
        $query = self::arrayOrNull($update['callback_query'] ?? null);
        if ($updateId === null || $query === null) {
            return null;
        }
        $from = self::arrayOrNull($query['from'] ?? null);
        $message = self::arrayOrNull($query['message'] ?? null);
        $chat = $message === null ? null : self::arrayOrNull($message['chat'] ?? null);
        $callbackId = self::nonEmptyString($query['id'] ?? null);
        $data = self::nonEmptyString($query['data'] ?? null);
        $userId = $from === null ? null : self::id($from['id'] ?? null);
        $chatId = $chat === null ? null : self::id($chat['id'] ?? null);
        if ($from === null || $callbackId === null || $data === null || $userId === null || $chatId === null) {
            return null;
        }
        if (($from['is_bot'] ?? false) === true) {
            return null;
        }

        return new IncomingCallback(
            channel: $this->channelName,
            callbackId: $callbackId,
            chatId: $chatId,
            userId: $userId,
            displayName: self::displayName($from),
            messageId: self::id($message['message_id'] ?? null),
            data: $data,
            updateId: (string) $updateId,
        );
    }

    /**
     * The `update_id` of a raw update, or null when it is missing or not an integer.
     *
     * @param array<array-key, mixed> $update
     */
    public static function updateId(array $update): ?int
    {
        return self::intOrNull($update['update_id'] ?? null);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function arrayOrNull(mixed $value): ?array
    {
        return \is_array($value) ? $value : null;
    }

    private static function id(mixed $value): ?string
    {
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $from
     */
    private static function displayName(array $from): ?string
    {
        $parts = [];
        foreach (['first_name', 'last_name'] as $key) {
            $part = self::nonEmptyString($from[$key] ?? null);
            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : \implode(' ', $parts);
    }

    private static function date(mixed $timestamp): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        if (\is_int($timestamp) && $timestamp > 0) {
            return (new DateTimeImmutable('@' . $timestamp))->setTimezone($utc);
        }

        return new DateTimeImmutable('now', $utc);
    }
}
