<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Telegram;

use DateTimeImmutable;
use DateTimeZone;
use Mercurius\Messenger\Attachment;
use Mercurius\Messenger\AttachmentKind;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\IncomingMembership;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\IncomingPost;

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
        /** messages of other bots in groups come, flagged `fromBot`; a bot's private message and its own never do */
        private readonly bool $allowBots = false,
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
        $fromBot = ($from['is_bot'] ?? false) === true;
        if ($this->botId !== null && $userId === (string) $this->botId) {
            return null;
        }
        if ($fromBot && (!$this->allowBots || ($chat['type'] ?? null) === 'private')) {
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
            attachments: self::attachments($message, $messageId),
            repliedAttachments: $reply === null ? [] : self::attachments($reply, self::id($reply['message_id'] ?? null) ?? 'reply'),
            fromBot: $fromBot,
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
     * A change of the bot's own membership (`my_chat_member`), or null when the update is something else.
     *
     * @param array<array-key, mixed> $update
     */
    public function parseMembership(array $update): ?IncomingMembership
    {
        $updateId = self::updateId($update);
        $member = self::arrayOrNull($update['my_chat_member'] ?? null);
        if ($updateId === null || $member === null) {
            return null;
        }
        $chat = self::arrayOrNull($member['chat'] ?? null);
        $from = self::arrayOrNull($member['from'] ?? null);
        $new = self::arrayOrNull($member['new_chat_member'] ?? null);
        $old = self::arrayOrNull($member['old_chat_member'] ?? null);
        $chatId = $chat === null ? null : self::id($chat['id'] ?? null);
        $userId = $from === null ? null : self::id($from['id'] ?? null);
        $status = $new === null ? null : self::nonEmptyString($new['status'] ?? null);
        $type = $chat === null ? null : self::nonEmptyString($chat['type'] ?? null);
        if ($chat === null || $from === null || $chatId === null || $userId === null || $status === null || $type === null) {
            return null;
        }

        return new IncomingMembership(
            channel: $this->channelName,
            chatId: $chatId,
            chatTitle: self::nonEmptyString($chat['title'] ?? null),
            chatType: $type,
            userId: $userId,
            displayName: self::displayName($from),
            status: $status,
            oldStatus: $old === null ? null : self::nonEmptyString($old['status'] ?? null),
            updateId: (string) $updateId,
            rights: self::rights($new),
        );
    }

    /**
     * A channel post or its edit, or null when the update is something else.
     *
     * @param array<array-key, mixed> $update
     */
    public function parsePost(array $update): ?IncomingPost
    {
        $updateId = self::updateId($update);
        $edited = !isset($update['channel_post']) && isset($update['edited_channel_post']);
        $post = self::arrayOrNull($update[$edited ? 'edited_channel_post' : 'channel_post'] ?? null);
        $chat = $post === null ? null : self::arrayOrNull($post['chat'] ?? null);
        $chatId = $chat === null ? null : self::id($chat['id'] ?? null);
        $messageId = $post === null ? null : self::id($post['message_id'] ?? null);
        if ($updateId === null || $post === null || $chat === null || $chatId === null || $messageId === null) {
            return null;
        }

        return new IncomingPost(
            channel: $this->channelName,
            chatId: $chatId,
            chatTitle: self::nonEmptyString($chat['title'] ?? null),
            messageId: $messageId,
            text: self::nonEmptyString($post['text'] ?? null) ?? self::nonEmptyString($post['caption'] ?? null) ?? '',
            postedAt: self::date($post['edit_date'] ?? $post['date'] ?? null),
            isEdited: $edited,
            updateId: (string) $updateId,
        );
    }

    /**
     * The `can_*` flags of a chat member.
     *
     * @param array<array-key, mixed>|null $member
     * @return array<string, bool>
     */
    private static function rights(?array $member): array
    {
        $rights = [];
        if ($member === null) {
            return $rights;
        }
        /** @var mixed $value */
        foreach ($member as $key => $value) {
            if (\is_string($key) && \str_starts_with($key, 'can_') && \is_bool($value)) {
                $rights[$key] = $value;
            }
        }

        return $rights;
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
     * The files of a message. A photo comes in several sizes: the largest is taken. An animation comes with a
     * `document` field too: the animation wins. Stickers are skipped.
     *
     * @param array<array-key, mixed> $message
     * @return list<Attachment>
     */
    private static function attachments(array $message, string $messageId): array
    {
        $forwarded = isset($message['forward_origin']) || isset($message['forward_from']) || isset($message['forward_date']);
        /** @var mixed $photo */
        $photo = $message['photo'] ?? null;
        if (\is_array($photo) && $photo !== []) {
            $largest = self::arrayOrNull(\end($photo));
            $fileId = $largest === null ? null : self::nonEmptyString($largest['file_id'] ?? null);
            if ($largest !== null && $fileId !== null) {
                return [new Attachment(AttachmentKind::Photo, $fileId, 'photo-' . $messageId . '.jpg', 'image/jpeg', self::int($largest['file_size'] ?? null), null, self::int($largest['width'] ?? null), self::int($largest['height'] ?? null), $forwarded)];
            }
        }
        $kinds = [
            'animation' => [AttachmentKind::Animation, 'mp4'],
            'voice' => [AttachmentKind::Voice, 'ogg'],
            'audio' => [AttachmentKind::Audio, 'mp3'],
            'video_note' => [AttachmentKind::VideoNote, 'mp4'],
            'video' => [AttachmentKind::Video, 'mp4'],
            'document' => [AttachmentKind::Document, 'bin'],
        ];
        foreach ($kinds as $field => [$kind, $extension]) {
            $file = self::arrayOrNull($message[$field] ?? null);
            $fileId = $file === null ? null : self::nonEmptyString($file['file_id'] ?? null);
            if ($file === null || $fileId === null) {
                continue;
            }
            $size = self::int($file['length'] ?? null);
            return [new Attachment(
                $kind,
                $fileId,
                self::nonEmptyString($file['file_name'] ?? null) ?? $kind->value . '-' . $messageId . '.' . $extension,
                self::nonEmptyString($file['mime_type'] ?? null),
                self::int($file['file_size'] ?? null),
                self::int($file['duration'] ?? null),
                self::int($file['width'] ?? null) ?? $size,
                self::int($file['height'] ?? null) ?? $size,
                $forwarded,
            )];
        }

        return [];
    }

    private static function int(mixed $value): ?int
    {
        return \is_int($value) && $value >= 0 ? $value : null;
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
