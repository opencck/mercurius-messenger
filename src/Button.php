<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * An inline button under a message. Pressing it sends `data` back as an `IncomingCallback`.
 * Telegram limits callback data to 64 bytes, so it carries a short id, not a payload.
 */
final readonly class Button
{
    public const MAX_DATA_BYTES = 64;

    public function __construct(
        public string $text,
        public string $data,
    ) {
        if ($text === '' || $data === '' || \strlen($data) > self::MAX_DATA_BYTES) {
            throw new \InvalidArgumentException('A button needs text and 1-64 bytes of data');
        }
    }
}
