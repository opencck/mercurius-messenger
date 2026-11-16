<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * Text formatting mode of an outgoing message.
 *
 * Channels that do not support a mode send the text as plain.
 */
enum ParseMode: string
{
    case Plain = 'plain';
    case Html = 'html';
    case MarkdownV2 = 'markdown_v2';
}
