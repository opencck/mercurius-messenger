<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit;

use Mercurius\Messenger\Deduplicator;
use PHPUnit\Framework\TestCase;

final class DeduplicatorTest extends TestCase
{
    public function testDetectsRepeatedKey(): void
    {
        $dedup = new Deduplicator();

        self::assertFalse($dedup->isDuplicate('telegram:1'));
        self::assertTrue($dedup->isDuplicate('telegram:1'));
        self::assertFalse($dedup->isDuplicate('telegram:2'));
    }

    public function testEvictsLeastRecentlyUsed(): void
    {
        $dedup = new Deduplicator(3);
        $dedup->isDuplicate('a');
        $dedup->isDuplicate('b');
        $dedup->isDuplicate('c');
        // Touch "a": now "b" is the oldest.
        self::assertTrue($dedup->isDuplicate('a'));
        $dedup->isDuplicate('d');

        self::assertSame(3, $dedup->count());
        self::assertFalse($dedup->isDuplicate('b'), '"b" was evicted');
        self::assertTrue($dedup->isDuplicate('a'));
    }

    public function testRejectsZeroCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Deduplicator(0);
    }

    public function testAStoreRemembersKeysAcrossRestarts(): void
    {
        $stored = [];
        $store = static function (string $key) use (&$stored): bool {
            if (isset($stored[$key])) {
                return false;
            }
            $stored[$key] = true;
            return true;
        };

        self::assertFalse((new Deduplicator(firstSeen: $store))->isDuplicate('u:1'));
        $afterRestart = new Deduplicator(firstSeen: $store);
        self::assertTrue($afterRestart->isDuplicate('u:1'), 'the store saw it before the restart');
        self::assertFalse($afterRestart->isDuplicate('u:2'));

        $broken = new Deduplicator(firstSeen: static function (string $key): bool {
            if ($key !== '') {
                throw new \RuntimeException('database down');
            }
            return true;
        });
        self::assertFalse($broken->isDuplicate('u:3'), 'a store that fails lets the update through');
    }
}
