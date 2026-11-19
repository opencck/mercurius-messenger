<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * Bounded LRU set of recently seen delivery ids (Telegram `update_id`).
 *
 * Protects against processing an update twice when the platform redelivers it (an unacknowledged `getUpdates`
 * batch, a webhook retry). With `$firstSeen` the keys also live in a store (a database), so an update redelivered
 * after a restart is dropped too; a store that fails lets the update through. Not exactly once: two processes may
 * both see a key as new.
 */
final class Deduplicator
{
    /** @var array<string, true> insertion-ordered, the oldest key first */
    private array $seen = [];

    /**
     * @param (\Closure(string): bool)|null $firstSeen remembers a key in a store; true when the store did not have it
     */
    public function __construct(
        private readonly int $capacity = 4096,
        private readonly ?\Closure $firstSeen = null,
    ) {
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
        if ($this->firstSeen !== null) {
            try {
                return !($this->firstSeen)($key);
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    public function count(): int
    {
        return \count($this->seen);
    }
}
