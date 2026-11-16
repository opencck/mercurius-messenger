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
}
