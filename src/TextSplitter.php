<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

use Closure;

/**
 * Splits a long text into chunks that fit a channel's message limit.
 *
 * Preference order for a cut: a paragraph break, a line break outside fenced ``` code blocks, a line break inside
 * a code block (the block is closed in this chunk and reopened with the same language in the next one), a space,
 * and finally a hard cut on a character boundary. Length is measured by a pluggable function; the default counts
 * UTF-16 code units, as Telegram does.
 */
final class TextSplitter
{
    private const FENCE = '```';
    private const FENCE_CLOSE = "\n```";

    /** @var Closure(string): int */
    private readonly Closure $length;

    /**
     * @param int $maxLength maximum chunk length in units of `$length`
     * @param (Closure(string): int)|null $length length function; UTF-16 code units by default
     */
    public function __construct(private readonly int $maxLength, ?Closure $length = null)
    {
        if ($maxLength < 16) {
            throw new \InvalidArgumentException('Maximum length must be at least 16');
        }
        $this->length = $length ?? self::utf16Length(...);
    }

    /**
     * Length of a UTF-8 string in UTF-16 code units (characters outside the BMP, e.g. most emoji, count as two).
     */
    public static function utf16Length(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        // Every code point is one unit; those outside the BMP are a surrogate pair, one more unit each.
        $astral = \preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);

        return \mb_strlen($text, 'UTF-8') + ($astral === false ? 0 : $astral);
    }

    /**
     * @return list<string> chunks without leading/trailing blank lines; empty for a blank text
     */
    public function split(string $text): array
    {
        $rest = \ltrim(\rtrim(self::scrub($text)), "\r\n");
        $chunks = [];

        while ($rest !== '' && $this->measure($rest) > $this->maxLength) {
            [$chunk, $rest] = $this->cut($rest);
            $chunk = \rtrim($chunk);
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }

        $rest = \rtrim($rest);
        if ($rest !== '') {
            $chunks[] = $rest;
        }

        return $chunks;
    }

    /**
     * Cuts the text to the limit, appending `$ellipsis` when something was removed.
     */
    public function truncate(string $text, string $ellipsis = '…'): string
    {
        $text = self::scrub($text);
        if ($this->measure($text) <= $this->maxLength) {
            return $text;
        }
        $end = $this->prefixBytes($text, $this->maxLength - $this->measure($ellipsis));

        return \rtrim(\substr($text, 0, $end)) . $ellipsis;
    }

    private static function scrub(string $text): string
    {
        return \mb_check_encoding($text, 'UTF-8') ? $text : \mb_scrub($text, 'UTF-8');
    }

    private function measure(string $text): int
    {
        return ($this->length)($text);
    }

    /**
     * @return array{string, string} the chunk and the remaining text
     */
    private function cut(string $text): array
    {
        $fences = self::fenceRanges($text);

        $prefixEnd = $this->prefixBytes($text, $this->maxLength);
        $prefix = \substr($text, 0, $prefixEnd);

        // 1. Paragraph break outside code blocks, not too close to the start.
        $pos = self::lastBreak($prefix, "\n\n", $fences, \max(1, \intdiv($prefixEnd * 3, 10)), true);
        // 2. Any line break outside code blocks.
        $pos ??= self::lastBreak($prefix, "\n", $fences, 1, true);
        if ($pos !== null) {
            return [\substr($text, 0, $pos), \ltrim(\substr($text, $pos), "\r\n")];
        }

        // 3. Line break (inside a code block), 4. a space, 5. a hard cut.
        [$pos, $separatorLength] = self::fallbackCut($prefix, $fences);
        if (self::fenceAt($fences, $pos) !== null) {
            // The cut breaks a code block: leave room for the closing fence.
            $reservedEnd = $this->prefixBytes($text, $this->maxLength - $this->measure(self::FENCE_CLOSE));
            if ($pos > $reservedEnd) {
                [$pos, $separatorLength] = self::fallbackCut(\substr($text, 0, $reservedEnd), $fences);
            }
        }

        $chunk = \substr($text, 0, $pos);
        $rest = \substr($text, $pos + $separatorLength);

        $fence = self::fenceAt($fences, $pos);
        if ($fence !== null && $this->measure($fence[2]) < \intdiv($this->maxLength, 4)) {
            $chunk = \rtrim($chunk) . self::FENCE_CLOSE;
            $rest = $fence[2] . "\n" . $rest;
        }

        return [$chunk, $rest];
    }

    /**
     * Last line break, else last space, else the end of the prefix (a hard cut).
     *
     * @param list<array{int, int, string}> $fences
     * @return array{int, int} cut position and the length of the separator dropped at the cut
     */
    private static function fallbackCut(string $prefix, array $fences): array
    {
        $pos = self::lastBreak($prefix, "\n", $fences, 1, false) ?? self::lastBreak($prefix, ' ', $fences, 1, false);

        return $pos === null ? [\max(1, \strlen($prefix)), 0] : [$pos, 1];
    }

    /**
     * Byte length of the longest prefix (on a character boundary) whose measured length fits the budget.
     */
    private function prefixBytes(string $text, int $budget): int
    {
        if ($budget <= 0) {
            return 0;
        }
        \preg_match_all('/./su', $text, $matches, \PREG_OFFSET_CAPTURE);
        $offsets = \array_column($matches[0], 1);
        $offsets[] = \strlen($text);

        // Largest index i such that the prefix up to $offsets[i] fits.
        $low = 0;
        $high = \count($offsets) - 1;
        while ($low < $high) {
            $mid = \intdiv($low + $high + 1, 2);
            if ($this->measure(\substr($text, 0, $offsets[$mid])) <= $budget) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $offsets[$low];
    }

    /**
     * Fenced code blocks as [start, end, opener]: start of the opening line, end of the closing line
     * (or the end of the text for an unclosed block) and the opening line itself, e.g. "```php".
     *
     * @return list<array{int, int, string}>
     */
    private static function fenceRanges(string $text): array
    {
        $ranges = [];
        $open = null;
        $offset = 0;
        $length = \strlen($text);

        while ($offset < $length) {
            $newline = \strpos($text, "\n", $offset);
            $lineEnd = $newline === false ? $length : $newline;
            $line = \trim(\substr($text, $offset, $lineEnd - $offset));

            if (\str_starts_with($line, self::FENCE)) {
                if ($open === null) {
                    $open = [$offset, $line];
                } else {
                    $ranges[] = [$open[0], $lineEnd, $open[1]];
                    $open = null;
                }
            }
            $offset = $lineEnd + 1;
        }

        if ($open !== null) {
            $ranges[] = [$open[0], $length, $open[1]];
        }

        return $ranges;
    }

    /**
     * The code block that a cut before byte `$pos` would break, if any.
     *
     * @param list<array{int, int, string}> $fences
     * @return array{int, int, string}|null
     */
    private static function fenceAt(array $fences, int $pos): ?array
    {
        foreach ($fences as $fence) {
            if ($fence[0] < $pos && $pos < $fence[1]) {
                return $fence;
            }
        }

        return null;
    }

    /**
     * Position of the last `$needle` in `$prefix` at or after `$min`.
     *
     * @param list<array{int, int, string}> $fences
     */
    private static function lastBreak(string $prefix, string $needle, array $fences, int $min, bool $outsideFences): ?int
    {
        $search = $prefix;
        while (true) {
            $pos = \strrpos($search, $needle);
            if ($pos === false || $pos < $min) {
                return null;
            }
            if (!$outsideFences || self::fenceAt($fences, $pos) === null) {
                return $pos;
            }
            $search = \substr($search, 0, $pos);
        }
    }
}
