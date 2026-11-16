<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * Platform-independent classification of a failed send or edit.
 */
enum SendErrorKind: string
{
    /** The text exceeds the platform limit even after splitting. */
    case TooLong = 'too_long';
    /** The platform rejected the formatting (markup could not be parsed). */
    case BadFormat = 'bad_format';
    /** The bot is blocked, kicked or not allowed to write to the chat. */
    case Forbidden = 'forbidden';
    /** The chat or the message does not exist. */
    case NotFound = 'not_found';
    /** Flood control: retry after `SendResult::$retryAfter` seconds. */
    case RateLimited = 'rate_limited';
    /** Network error or a server-side failure: a retry may succeed. */
    case Transient = 'transient';
    case Unknown = 'unknown';

    /**
     * Whether repeating the same request later may succeed.
     */
    public function isRetryable(): bool
    {
        return $this === self::RateLimited || $this === self::Transient;
    }
}
