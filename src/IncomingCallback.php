<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * A press on an inline button. Every field comes from the outside world: check who pressed before acting,
 * and always answer it (`Channel::answerCallback`), or the client keeps showing a spinner.
 */
final readonly class IncomingCallback
{
    public function __construct(
        public string $channel,
        public string $callbackId,
        public string $chatId,
        public string $userId,
        public ?string $displayName,
        public ?string $messageId,
        public string $data,
        public string $updateId,
    ) {
    }

    public function dedupeKey(): string
    {
        return $this->channel . ':callback:' . $this->updateId;
    }
}
