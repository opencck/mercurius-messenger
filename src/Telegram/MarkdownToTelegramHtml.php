<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Telegram;

/**
 * Converts the Markdown that language models write into the HTML subset of the Telegram Bot API.
 *
 * Works on unfinished text as well (a streamed answer): unclosed markers stay as they are, an unclosed code fence
 * runs to the end. Only matched pairs become tags, so the output is always balanced; italic and strikethrough
 * never wrap other tags, which keeps crossing markers (`**a *b** c*`) from producing broken nesting.
 * Formatting does not span lines. Headings become bold, list markers become bullets, GFM tables become groups of
 * bullets (Telegram has no tables).
 *
 * ref: references/hermes-agent/plugins/platforms/telegram/telegram_markdown.py@e96221fa:L327-L439 (to_markdown_v2)
 * — the same order of steps: code and links are stashed behind placeholders first, then headings and emphasis,
 * then everything else is escaped. Hermes targets MarkdownV2 and pairs emphasis with a CommonMark-style delimiter
 * stack (L69-L296); we target HTML, where escaping is three characters, and pair with per-line regular expressions
 * that cannot cross: simpler, at the cost of rare nestings (`*a **b** c*`) staying literal.
 */
final class MarkdownToTelegramHtml
{
    private const RULE = '──────────';

    // ref: references/hermes-agent/plugins/platforms/telegram/telegram_markdown.py@e96221fa:L303 (_MD_LINK_RE)
    // — one level of balanced parentheses in the target, so `[x](a(b))` is one link.
    private const LINK = '/\[([^\[\]\n]+)\]\(([^()\s]*(?:\([^()\s]*\)[^()\s]*)*)\)/';

    // ref: references/hermes-agent/plugins/platforms/telegram/telegram_markdown.py@e96221fa:L452-L453 (_BARE_URL_RE)
    private const BARE_URL = '/\b[a-z][a-z0-9+.-]*:\/\/[^\s<>\x01\x02]+/i';
    private const URL_TAIL = '.,;:!?\'"';

    // ref: references/hermes-agent/gateway/platforms/helpers.py@e96221fa:L558 (TABLE_SEPARATOR_RE)
    // — the delimiter row needs an inner `|`, so a lone `---` stays a rule.
    private const TABLE_SEPARATOR = '/^\s*\|?\s*:?-+:?\s*(?:\|\s*:?-+:?\s*)+\|?\s*$/';

    public static function convert(string $markdown): string
    {
        $lines = \explode("\n", self::tablesToBullets(\str_replace(["\r\n", "\r"], "\n", $markdown)));
        $out = [];
        $quote = [];
        $count = \count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // ref: references/hermes-agent/plugins/platforms/telegram/telegram_markdown.py@e96221fa:L352-L380
            // — a fence may follow lead-in prose on its line ("Here is the code: ```php") or be indented.
            if (\preg_match('/^([^`]*)```\s*([\w+#.-]*)\s*$/', $line, $fence) === 1) {
                self::flushQuote($quote, $out);
                $code = [];
                for ($i++; $i < $count && \preg_match('/^\s*```\s*$/', $lines[$i]) !== 1; $i++) {
                    $code[] = $lines[$i];
                }
                $lead = \trim($fence[1]) === '' ? '' : self::block(\rtrim($fence[1])) . "\n";
                $out[] = $lead . self::codeBlock(\implode("\n", $code), $fence[2]);
                continue;
            }

            if (\preg_match('/^\s{0,3}>\s?(.*)$/', $line, $m) === 1) {
                $quote[] = self::inline($m[1]);
                continue;
            }
            self::flushQuote($quote, $out);
            $out[] = self::block($line);
        }
        self::flushQuote($quote, $out);

        return \implode("\n", $out);
    }

    /**
     * Plain text for the fallback when Telegram rejects the markup: emphasis markers and link syntax are removed,
     * code fences are kept.
     *
     * ref: references/hermes-agent/plugins/platforms/telegram/telegram_split.py@e96221fa:L89-L97 (_strip_mdv2)
     * — the same marker order (`**` before `*`, word-bounded `_`), applied to the source Markdown.
     */
    public static function toPlain(string $markdown): string
    {
        $text = self::tablesToBullets(\str_replace(["\r\n", "\r"], "\n", $markdown));
        $text = (string) \preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text);
        $text = (string) \preg_replace_callback(
            self::LINK,
            static fn (array $m): string => self::linkTarget($m[2]) === null ? $m[1] : $m[1] . ' (' . $m[2] . ')',
            $text,
        );
        $text = (string) \preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '$1', $text);
        $text = (string) \preg_replace('/(?<!\w)__(?=\S)(.+?)(?<=\S)__(?!\w)/', '$1', $text);
        $text = (string) \preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/', '$1', $text);
        $text = (string) \preg_replace('/(?<![\w*])\*(?![\s*])([^*\n]+?)(?<![\s*])\*(?![\w*])/', '$1', $text);
        $text = (string) \preg_replace('/(?<![\w_])_(?![\s_])([^_\n]+?)(?<![\s_])_(?![\w_])/', '$1', $text);

        return (string) \preg_replace('/(?<!`)`([^`\n]+)`(?!`)/', '$1', $text);
    }

    private static function block(string $line): string
    {
        if (\preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
            return self::RULE;
        }
        if (\preg_match('/^\s{0,3}#{1,6}\s+(.+?)(\s+#+)?\s*$/', $line, $m) === 1) {
            // ref: references/hermes-agent/plugins/platforms/telegram/telegram_markdown.py@e96221fa:L397-L402
            // — a heading is already bold: its own `**` would only nest.
            $title = (string) \preg_replace('/\*\*(.+?)\*\*/', '$1', $m[1]);

            return '<b>' . self::inline($title) . '</b>';
        }
        if (\preg_match('/^(\s*)[-*+]\s+(.*)$/', $line, $m) === 1) {
            return $m[1] . '• ' . self::inline($m[2]);
        }

        return self::inline($line);
    }

    /**
     * @param list<string> $quote
     * @param list<string> $out
     */
    private static function flushQuote(array &$quote, array &$out): void
    {
        if ($quote !== []) {
            $out[] = '<blockquote>' . \implode("\n", $quote) . '</blockquote>';
            $quote = [];
        }
    }

    private static function codeBlock(string $code, string $language): string
    {
        $body = self::escape($code);

        return $language === ''
            ? '<pre>' . $body . '</pre>'
            : '<pre><code class="language-' . self::escape($language) . '">' . $body . '</code></pre>';
    }

    private static function inline(string $text, bool $links = true): string
    {
        /** @var list<string> $slots */
        $slots = [];
        $hold = static function (string $html) use (&$slots): string {
            $slots[] = $html;

            return "\x01" . (\count($slots) - 1) . "\x02";
        };

        $text = (string) \preg_replace_callback(
            '/`([^`\n]+)`/',
            static fn (array $m): string => $hold('<code>' . self::escape($m[1]) . '</code>'),
            $text,
        );
        if ($links) {
            $text = (string) \preg_replace_callback(
                self::LINK,
                static function (array $m) use ($hold): string {
                    $href = self::linkTarget($m[2]);
                    $label = self::inline($m[1], false);

                    // ref: references/hermes-agent/plugins/platforms/telegram/telegram_markdown.py@e96221fa:L390-L391
                    // — a target Telegram cannot open shows the label, not the raw `[label](target)` syntax.
                    return $hold($href === null ? $label : '<a href="' . self::attribute($href) . '">' . $label . '</a>');
                },
                $text,
            );
            // ref: references/hermes-agent/plugins/platforms/telegram/telegram_markdown.py@e96221fa:L666-L679
            // (_stash_bare_urls) — a bare URL is stashed whole, so `*` or `_` inside it never pairs into emphasis;
            // trailing prose punctuation stays outside (_url_end, L462-L476).
            $text = (string) \preg_replace_callback(
                self::BARE_URL,
                static function (array $m) use ($hold): string {
                    $url = $m[0];
                    $end = self::urlEnd($url);

                    return $hold(self::escape(\substr($url, 0, $end))) . \substr($url, $end);
                },
                $text,
            );
        }

        $html = self::escape($text);
        $html = (string) \preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<b>$1</b>', $html);
        $html = (string) \preg_replace('/(?<!\w)__(?=\S)([^<>]+?)(?<=\S)__(?!\w)/', '<b>$1</b>', $html);
        $html = (string) \preg_replace('/~~(?=\S)([^~<>]+?)(?<=\S)~~/', '<s>$1</s>', $html);
        $html = (string) \preg_replace('/(?<![\w*])\*(?![\s*])([^*<>]+?)(?<![\s*])\*(?![\w*])/', '<i>$1</i>', $html);
        $html = (string) \preg_replace('/(?<![\w_])_(?![\s_])([^_<>]+?)(?<![\s_])_(?![\w_])/', '<i>$1</i>', $html);

        return (string) \preg_replace_callback(
            "/\x01(\d+)\x02/",
            static fn (array $m): string => $slots[(int) $m[1]] ?? '',
            $html,
        );
    }

    /**
     * The link address Telegram can open, or null.
     *
     * ref: references/hermes-agent/plugins/platforms/telegram/telegram_markdown.py@e96221fa:L299-L307
     * (link_target_renders) — a scheme URL or a bare domain. We allow only http(s), tg and mailto schemes, so a
     * model cannot put `javascript:` or `file:` behind a label; a bare domain gets https://.
     */
    private static function linkTarget(string $target): ?string
    {
        $target = \trim($target);
        if (\preg_match('/^(?:https?:\/\/|tg:\/\/|mailto:)[^\s@]*\S*$/i', $target) === 1) {
            return $target;
        }
        if (\preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)+(?:[\/?#:]\S*)?$/i', $target) === 1) {
            return 'https://' . $target;
        }

        return null;
    }

    private static function urlEnd(string $url): int
    {
        $end = \strlen($url);
        $unclosed = \substr_count($url, ')') - \substr_count($url, '(');
        while ($end > 0) {
            $ch = $url[$end - 1];
            if ($ch === ')' && $unclosed > 0) {
                $unclosed--;
            } elseif ($ch === '(' || !\str_contains(self::URL_TAIL, $ch)) {
                break;
            }
            $end--;
        }

        return $end;
    }

    /**
     * GFM pipe tables become a bold row heading and one bullet per column; code fences are left alone.
     *
     * ref: references/hermes-agent/gateway/platforms/helpers.py@e96221fa:L572-L617
     * (_render_table_block, convert_table_to_bullets) — ported as is, including the row-label column rule.
     */
    private static function tablesToBullets(string $text): string
    {
        if (!\str_contains($text, '|') || !\str_contains($text, '-')) {
            return $text;
        }
        $lines = \explode("\n", $text);
        $out = [];
        $inFence = false;
        $count = \count($lines);
        for ($i = 0; $i < $count;) {
            $line = $lines[$i];
            $isFence = \str_starts_with(\ltrim($line), '```');
            $inFence = $inFence !== $isFence;
            if (!$inFence && !$isFence && \str_contains($line, '|') && $i + 1 < $count
                && \preg_match(self::TABLE_SEPARATOR, $lines[$i + 1]) === 1) {
                $j = $i + 2;
                while ($j < $count && \str_contains(\trim($lines[$j]), '|')) {
                    $j++;
                }
                $out[] = self::renderTable(\array_slice($lines, $i, $j - $i));
                $i = $j;
                continue;
            }
            $out[] = $line;
            $i++;
        }

        return \implode("\n", $out);
    }

    /**
     * @param list<string> $block header, delimiter and data rows
     */
    private static function renderTable(array $block): string
    {
        $headers = isset($block[0], $block[2]) ? self::tableCells($block[0]) : [];
        if (!isset($block[2]) || \count($headers) < 2) {
            return \implode("\n", $block);
        }
        $rowLabel = \count(self::tableCells($block[2])) === \count($headers) + 1;
        $groups = [];
        foreach (\array_slice($block, 2) as $index => $row) {
            $cells = self::tableCells($row);
            $fallback = 'Row ' . ($index + 1);
            if ($rowLabel) {
                $heading = ($cells[0] ?? '') !== '' ? $cells[0] : $fallback;
                $data = \array_slice($cells, 1);
            } else {
                $heading = $fallback;
                foreach ($cells as $cell) {
                    if ($cell !== '') {
                        $heading = $cell;
                        break;
                    }
                }
                $data = $cells;
            }
            $data = \array_slice(\array_pad($data, \count($headers), ''), 0, \count($headers));
            $bullets = ['**' . $heading . '**'];
            foreach ($headers as $k => $header) {
                if ($rowLabel || $data[$k] !== $heading) {
                    $bullets[] = '• ' . $header . ': ' . $data[$k];
                }
            }
            $groups[] = \implode("\n", $bullets);
        }

        return \implode("\n\n", $groups);
    }

    /**
     * Cells of a GFM row: split on pipes that are neither escaped nor inside inline code.
     *
     * @return list<string>
     */
    private static function tableCells(string $row): array
    {
        $row = \trim($row);
        if (\str_starts_with($row, '|')) {
            $row = \substr($row, 1);
        }
        if (\str_ends_with($row, '|') && !\str_ends_with($row, '\\|')) {
            $row = \substr($row, 0, -1);
        }
        $cells = [];
        $cell = '';
        $inCode = false;
        $length = \strlen($row);
        for ($k = 0; $k < $length; $k++) {
            $ch = $row[$k];
            if ($ch === '\\' && ($row[$k + 1] ?? '') === '|') {
                $cell .= '|';
                $k++;
                continue;
            }
            if ($ch === '`') {
                $inCode = !$inCode;
            } elseif ($ch === '|' && !$inCode) {
                $cells[] = \trim($cell);
                $cell = '';
                continue;
            }
            $cell .= $ch;
        }
        $cells[] = \trim($cell);

        return $cells;
    }

    private static function escape(string $text): string
    {
        return \htmlspecialchars($text, \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function attribute(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
