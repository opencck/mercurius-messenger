<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Support;

use Mercurius\Messenger\Websocket\Connection;

/** Keeps the frames sent to it, decoded; a closed one refuses them. */
final class RecordingConnection implements Connection
{
    /** @var list<array<array-key, mixed>> */
    public array $frames = [];
    public bool $open = true;

    public function send(string $frame): bool
    {
        if (!$this->open) {
            return false;
        }
        /** @var mixed $decoded */
        $decoded = json_decode($frame, true, 32, JSON_THROW_ON_ERROR);
        $this->frames[] = \is_array($decoded) ? $decoded : [];
        return true;
    }
}
