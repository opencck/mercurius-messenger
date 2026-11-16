<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit;

use Mercurius\Messenger\TextSplitter;
use PHPUnit\Framework\TestCase;

final class TextSplitterTest extends TestCase
{
    public function testUtf16LengthCountsCodeUnits(): void
    {
        self::assertSame(5, TextSplitter::utf16Length('hello'));
        self::assertSame(6, TextSplitter::utf16Length('Привет'));
        // U+1F600 is outside the BMP: a surrogate pair, two units.
        self::assertSame(2, TextSplitter::utf16Length('😀'));
        self::assertSame(4, TextSplitter::utf16Length('a😀b'));
        self::assertSame(0, TextSplitter::utf16Length(''));
    }

    public function testShortTextIsNotSplit(): void
    {
        self::assertSame(['short text'], (new TextSplitter(4096))->split('short text'));
    }

    public function testEmptyTextGivesNoChunks(): void
    {
        self::assertSame([], (new TextSplitter(100))->split(''));
    }

    public function testExactLimitIsOneChunk(): void
    {
        $text = \str_repeat('я', 4096);
        self::assertSame([$text], (new TextSplitter(4096))->split($text));
    }

    public function testSplitsOnParagraphBoundary(): void
    {
        $p1 = \str_repeat('a', 60);
        $p2 = \str_repeat('b', 60);
        $chunks = (new TextSplitter(100))->split($p1 . "\n\n" . $p2);

        self::assertSame([$p1, $p2], $chunks);
    }

    public function testPrefersLineBoundaryOverSpace(): void
    {
        $line1 = \str_repeat('word ', 10) . 'end';
        $line2 = \str_repeat('next ', 10) . 'end';
        $chunks = (new TextSplitter(70))->split($line1 . "\n" . $line2);

        self::assertSame([$line1, $line2], $chunks);
    }

    public function testFallsBackToSpaceThenHardCut(): void
    {
        $words = \trim(\str_repeat('lorem ', 40));
        foreach ((new TextSplitter(50))->split($words) as $chunk) {
            self::assertLessThanOrEqual(50, TextSplitter::utf16Length($chunk));
            self::assertStringNotContainsString('  ', $chunk);
            self::assertDoesNotMatchRegularExpression('/^\s|\s$/', $chunk);
        }

        $noSpaces = \str_repeat('x', 250);
        $chunks = (new TextSplitter(100))->split($noSpaces);
        self::assertSame([100, 100, 50], \array_map('strlen', $chunks));
        self::assertSame($noSpaces, \implode('', $chunks));
    }

    public function testLimitIsMeasuredInUtf16UnitsForEmoji(): void
    {
        // 100 emoji = 200 UTF-16 units: with a limit of 50 units it needs 4 chunks of 25 emoji.
        $text = \str_repeat('😀', 100);
        $chunks = (new TextSplitter(50))->split($text);

        self::assertCount(4, $chunks);
        foreach ($chunks as $chunk) {
            self::assertSame(50, TextSplitter::utf16Length($chunk));
            self::assertTrue(\mb_check_encoding($chunk, 'UTF-8'), 'a surrogate pair/codepoint must not be cut');
        }
        self::assertSame($text, \implode('', $chunks));
    }

    public function testCyrillicTextRespectsLimit(): void
    {
        $sentence = 'Съешь же ещё этих мягких французских булок, да выпей чаю. ';
        $text = \str_repeat($sentence, 200);
        $chunks = (new TextSplitter(4096))->split($text);

        self::assertGreaterThan(1, \count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(4096, TextSplitter::utf16Length($chunk));
            self::assertTrue(\mb_check_encoding($chunk, 'UTF-8'));
        }
        self::assertSame(
            \preg_replace('/\s+/u', '', $text),
            \preg_replace('/\s+/u', '', \implode('', $chunks)),
        );
    }

    public function testKeepsCodeFenceIntactWhenItFits(): void
    {
        $intro = \str_repeat('intro line text', 3);
        $code = "```php\n" . \str_repeat("echo 1;\n", 8) . '```';
        $text = $intro . "\n" . $code . "\nafter";

        $chunks = (new TextSplitter(100))->split($text);

        self::assertSame($intro, $chunks[0]);
        self::assertStringStartsWith('```php', $chunks[1]);
        self::assertStringContainsString($code, $chunks[1]);
    }

    public function testClosesAndReopensFenceWhenBlockIsTooLong(): void
    {
        $code = "```python\n" . \str_repeat("print('hello world')\n", 20) . '```';
        $chunks = (new TextSplitter(120))->split($code);

        self::assertGreaterThan(1, \count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(120, TextSplitter::utf16Length($chunk));
            self::assertStringStartsWith('```python', $chunk);
            self::assertStringEndsWith('```', $chunk);
            self::assertSame(0, \substr_count($chunk, '```') % 2, 'every chunk has balanced fences');
        }
    }

    public function testTruncate(): void
    {
        $splitter = new TextSplitter(16);
        self::assertSame('short', $splitter->truncate('short'));
        self::assertSame(\str_repeat('а', 15) . '…', $splitter->truncate(\str_repeat('а', 30)));
        // 7 emoji (14 units) + the ellipsis: an eighth emoji would not fit into 16 units.
        self::assertSame(\str_repeat('😀', 7) . '…', $splitter->truncate(\str_repeat('😀', 30)));
    }

    public function testRejectsTooSmallLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TextSplitter(8);
    }
}
