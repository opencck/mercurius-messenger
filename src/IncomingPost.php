<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

use DateTimeImmutable;

/**
 * A post of a channel where the bot is an admin (`channel_post`), or its edit (`edited_channel_post`). The text comes
 * from the outside world.
 */
final readonly class IncomingPost
{
    public function __construct(
        public string $channel,
        public string $chatId,
        public ?string $chatTitle,
        public string $messageId,
        /** the text or the caption of the post, '' for a post without text */
        public string $text,
        public DateTimeImmutable $postedAt,
        public bool $isEdited,
        public string $updateId,
    ) {
    }

    public function dedupeKey(): string
    {
        return $this->channel . ':post:' . $this->updateId;
    }
}
