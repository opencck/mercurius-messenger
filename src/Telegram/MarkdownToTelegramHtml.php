<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Telegram;

/**
 * Converts the Markdown that language models write into the HTML subset of the Telegram Bot API.
 *
 * Works on unfinished text as well (a streamed answer): unclosed markers stay as they are, an unclosed code fence
 * runs to the end. Only matched pairs become tags, so the output is always balanced; italic and strikethrough
 * never wrap other tags, which keeps crossing markers (`**a *b** c*`) from producing broken nesting.
 * Formatting does not span lines. Headings become bold, list markers become bullets.
 */
final class MarkdownToTelegramHtml
{
    private const RULE = '──────────';

    public static function convert(string $markdown): string
    {
        $lines = \explode("\n", \str_replace(["\r\n", "\r"], "\n", $markdown));
        $out = [];
        $quote = [];
        $count = \count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (\preg_match('/^\s*```\s*([\w+#.-]*)\s*$/', $line, $fence) === 1) {
                self::flushQuote($quote, $out);
                $code = [];
                for ($i++; $i < $count && \preg_match('/^\s*```\s*$/', $lines[$i]) !== 1; $i++) {
                    $code[] = $lines[$i];
                }
                $out[] = self::codeBlock(\implode("\n", $code), $fence[1]);
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

    private static function block(string $line): string
    {
        if (\preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
            return self::RULE;
        }
        if (\preg_match('/^\s{0,3}#{1,6}\s+(.+?)(\s+#+)?\s*$/', $line, $m) === 1) {
            return '<b>' . self::inline($m[1]) . '</b>';
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
                '/\[([^\[\]\n]+)\]\((https?:\/\/[^\s()<>"]+)\)/',
                static fn (array $m): string => $hold(
                    '<a href="' . \str_replace('"', '&quot;', self::escape($m[2])) . '">'
                    . self::inline($m[1], false) . '</a>',
                ),
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

    private static function escape(string $text): string
    {
        return \htmlspecialchars($text, \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
