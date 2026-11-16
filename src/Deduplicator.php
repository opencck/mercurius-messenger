<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * Bounded LRU set of recently seen delivery ids (Telegram `update_id`).
 *
 * Protects against processing an update twice when the platform redelivers it (an unacknowledged `getUpdates`
 * batch, a webhook retry). Memory only: no exactly-once guarantee across restarts.
 */
final class Deduplicator
{
    /** @var array<string, true> insertion-ordered, the oldest key first */
    private array $seen = [];

    public function __construct(private readonly int $capacity = 4096)
    {
        if ($capacity < 1) {
            throw new \InvalidArgumentException('Capacity must be positive');
        }
    }

    /**
     * Returns true when the key was seen before; otherwise remembers it and returns false.
     */
    public function isDuplicate(string $key): bool
    {
        if (isset($this->seen[$key])) {
            // Refresh: move the key to the most recent position.
            unset($this->seen[$key]);
            $this->seen[$key] = true;

            return true;
        }

        $this->seen[$key] = true;
        if (\count($this->seen) > $this->capacity) {
            unset($this->seen[\array_key_first($this->seen)]);
        }

        return false;
    }

    public function count(): int
    {
        return \count($this->seen);
    }
}
