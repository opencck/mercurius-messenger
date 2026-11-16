<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * A message to deliver through a channel.
 */
final readonly class OutgoingMessage
{
    /**
     * @param string|null $threadId topic/thread inside the chat (Telegram forum topic), if any
     * @param list<list<Button>> $buttons rows of inline buttons under the message (under the last part of a split one)
     */
    public function __construct(
        public string $chatId,
        public string $text,
        public ?string $replyToMessageId = null,
        public ParseMode $parseMode = ParseMode::Plain,
        public ?string $threadId = null,
        public bool $disableLinkPreview = false,
        public array $buttons = [],
    ) {
    }
}
