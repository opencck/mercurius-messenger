<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * Text formatting mode of an outgoing message.
 *
 * Channels that do not support a mode send the text as plain.
 * `Markdown` is the common Markdown that language models write; each channel converts it to its own markup
 * and falls back to the source text when the result is rejected.
 */
enum ParseMode: string
{
    case Plain = 'plain';
    case Markdown = 'markdown';
    case Html = 'html';
    case MarkdownV2 = 'markdown_v2';
}
