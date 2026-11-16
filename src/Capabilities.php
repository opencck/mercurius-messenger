<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * What a channel can do. Callers fall back to plain behaviour when a capability is missing
 * (no edit: send a new message instead; no buttons: a numbered list).
 */
final readonly class Capabilities
{
    /**
     * @param int $maxMessageLength limit of one message in the channel's own units (Telegram: UTF-16 code units)
     */
    public function __construct(
        public bool $canEdit,
        public int $maxMessageLength,
        public bool $supportsButtons,
    ) {
    }
}
