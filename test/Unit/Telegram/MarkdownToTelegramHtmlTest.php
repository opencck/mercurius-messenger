<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Mercurius\Messenger\Telegram\MarkdownToTelegramHtml;
use PHPUnit\Framework\TestCase;

final class MarkdownToTelegramHtmlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'plain text is escaped' => ['a < b & c > d', 'a &lt; b &amp; c &gt; d'];
        yield 'bold' => ['**Долгая пауза.** Дальше', '<b>Долгая пауза.</b> Дальше'];
        yield 'bold with underscores' => ['__bold__ text', '<b>bold</b> text'];
        yield 'italic with stars' => ['an *italic* word', 'an <i>italic</i> word'];
        yield 'italic with underscores' => ['an _italic_ word', 'an <i>italic</i> word'];
        yield 'snake_case is left alone' => ['call some_function_name now', 'call some_function_name now'];
        yield 'italic inside bold' => ['**a *b* c**', '<b>a <i>b</i> c</b>'];
        yield 'strikethrough' => ['~~old~~ new', '<s>old</s> new'];
        yield 'inline code is escaped and not formatted' => ['run `a **b** <c>`', 'run <code>a **b** &lt;c&gt;</code>'];
        yield 'link' => ['see [the docs](https://example.com/a_b?x=1&y=2)', 'see <a href="https://example.com/a_b?x=1&amp;y=2">the docs</a>'];
        yield 'formatted link label' => ['[**bold** link](https://example.com)', '<a href="https://example.com"><b>bold</b> link</a>'];
        yield 'unsafe link target shows the label' => ['[x](javascript:alert(1))', 'x'];
        yield 'schemeless title shows the label' => ['[Title](Title)', 'Title'];
        yield 'bare domain link gets https' => ['[site](example.com/a)', '<a href="https://example.com/a">site</a>'];
        yield 'link with parentheses' => ['[w](https://en.wikipedia.org/wiki/A_(b))', '<a href="https://en.wikipedia.org/wiki/A_(b)">w</a>'];
        yield 'bare url keeps stars and trailing dot outside' => ['see https://x.io/*a*_b_.', 'see https://x.io/*a*_b_.'];
        yield 'heading drops inner bold' => ['## **Итоги** года', '<b>Итоги года</b>'];
        yield 'fence after lead-in prose' => ["Code: ```sh\nls\n```", "Code:\n<pre><code class=\"language-sh\">ls</code></pre>"];
        yield 'table becomes bullet groups' => [
            "| Planet | Moons |\n|---|---|\n| Mars | 2 |\n| Earth | 1 |",
            "<b>Mars</b>\n• Moons: 2\n\n<b>Earth</b>\n• Moons: 1",
        ];
        yield 'table with a row-label column' => [
            "| A | B |\n|---|---|\n| r1 | 1 | 2 |",
            "<b>r1</b>\n• A: 1\n• B: 2",
        ];
        yield 'bare url with underscores' => ['https://example.com/a_b_c', 'https://example.com/a_b_c'];
        yield 'heading becomes bold' => ["# Title\ntext", "<b>Title</b>\ntext"];
        yield 'list markers become bullets' => ["- one\n* two\n  + nested", "• one\n• two\n  • nested"];
        yield 'numbered list is kept' => ["1. one\n2. two", "1. one\n2. two"];
        yield 'blockquote' => ["> quoted\n> more\nafter", "<blockquote>quoted\nmore</blockquote>\nafter"];
        yield 'horizontal rule' => ["a\n---\nb", "a\n──────────\nb"];
        yield 'fenced code with language' => ["```php\necho '<b>';\n```", "<pre><code class=\"language-php\">echo '&lt;b&gt;';</code></pre>"];
        yield 'fenced code without language' => ["```\nx **y**\n```\nafter", "<pre>x **y**</pre>\nafter"];
        yield 'unclosed fence while streaming' => ["text\n```js\nlet a = 1;", "text\n<pre><code class=\"language-js\">let a = 1;</code></pre>"];
        yield 'unclosed bold while streaming' => ['**Долгая па', '**Долгая па'];
        yield 'unclosed inline code while streaming' => ['run `cmd', 'run `cmd'];
        yield 'crossing markers stay balanced' => ['**a *b** c*', '<b>a *b</b> c*'];
        yield 'lone stars are kept' => ['2 * 3 * 4', '2 * 3 * 4'];
    }

    /**
     * @dataProvider cases
     */
    public function testConverts(string $markdown, string $html): void
    {
        self::assertSame($html, MarkdownToTelegramHtml::convert($markdown));
    }

    public function testPlainFallbackDropsMarkers(): void
    {
        self::assertSame(
            "Title\nbold, italic, some_name, code and docs (https://x.io)",
            MarkdownToTelegramHtml::toPlain("# Title\n**bold**, *italic*, some_name, `code` and [docs](https://x.io)"),
        );
    }

    public function testOutputTagsAreAlwaysBalanced(): void
    {
        $samples = [
            '**a _b** c_ ~~d **e~~ f**',
            "> **quote\n> end** x",
            '[**a](https://x.y) b**',
            "```\n**x",
            '*a **b** c*',
        ];
        foreach ($samples as $sample) {
            $html = MarkdownToTelegramHtml::convert($sample);
            self::assertTrue(self::balanced($html), $sample . ' => ' . $html);
        }
    }

    private static function balanced(string $html): bool
    {
        \preg_match_all('~<(/?)([a-z]+)[^>]*>~', $html, $matches, \PREG_SET_ORDER);
        $stack = [];
        foreach ($matches as $m) {
            if ($m[1] === '') {
                $stack[] = $m[2];
            } elseif (\array_pop($stack) !== $m[2]) {
                return false;
            }
        }

        return $stack === [];
    }
}
