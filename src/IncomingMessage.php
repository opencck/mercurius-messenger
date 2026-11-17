<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

use DateTimeImmutable;

/**
 * A normalized incoming message, independent of the platform.
 *
 * Every field comes from the outside world: treat `text`, `userName` and `displayName` as untrusted data.
 */
final readonly class IncomingMessage
{
    /**
     * @param string $channel name of the channel that received the message (`Channel::name()`)
     * @param string $text message text or media caption; empty for media without a caption and service messages
     * @param string $updateId platform delivery id used for deduplication (Telegram `update_id`)
     * @param string|null $threadId topic/thread inside the chat, if any
     * @param bool $isEdited the message is an edit of an earlier one
     * @param array<array-key, mixed> $raw the original platform payload (for media and other details)
     * @param list<Attachment> $attachments files that came with the message (voice, photo, video, document…)
     * @param list<Attachment> $repliedAttachments files of the message it replies to
     */
    public function __construct(
        public string $channel,
        public string $chatId,
        public string $userId,
        public ?string $userName,
        public ?string $displayName,
        public string $text,
        public string $messageId,
        public ?string $replyToMessageId,
        public bool $isPrivateChat,
        public DateTimeImmutable $receivedAt,
        public string $updateId,
        public ?string $threadId = null,
        public bool $isEdited = false,
        public array $raw = [],
        /** @var list<Attachment> files that came with the message */
        public array $attachments = [],
        /** @var list<Attachment> files of the message this one replies to (a question to a photo sent earlier) */
        public array $repliedAttachments = [],
    ) {
    }

    /**
     * Key for deduplication across channels: `<channel>:<updateId>`.
     */
    public function dedupeKey(): string
    {
        return $this->channel . ':' . $this->updateId;
    }
}
