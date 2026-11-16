<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Telegram;

use Mercurius\Messenger\SendErrorKind;

/**
 * A failed Bot API call: an `ok: false` response, an unexpected response or a network error.
 *
 * The message never contains the bot token.
 */
final class TelegramException extends \RuntimeException
{
    /**
     * @param string $method Bot API method, e.g. `sendMessage`
     * @param int $errorCode Telegram `error_code` (the HTTP status), 0 for network errors
     * @param string $description Telegram `description` or the network error text (redacted)
     * @param int|null $retryAfter `parameters.retry_after`, seconds
     */
    public function __construct(
        public readonly string $method,
        public readonly SendErrorKind $kind,
        public readonly int $errorCode,
        public readonly string $description,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct(
            \sprintf('Telegram %s failed: %s%s', $method, $errorCode > 0 ? "[{$errorCode}] " : '', $description),
            $errorCode,
        );
    }

    public static function fromApiError(string $method, int $errorCode, string $description, ?int $retryAfter): self
    {
        return new self($method, self::classify($errorCode, $description, $retryAfter), $errorCode, $description, $retryAfter);
    }

    /**
     * Maps a Telegram error to a platform-independent kind.
     */
    public static function classify(int $errorCode, string $description, ?int $retryAfter = null): SendErrorKind
    {
        $text = \strtolower($description);

        return match (true) {
            $errorCode === 429 || $retryAfter !== null => SendErrorKind::RateLimited,
            $errorCode === 401, $errorCode === 403 => SendErrorKind::Forbidden,
            $errorCode === 404 => SendErrorKind::NotFound,
            $errorCode === 0, $errorCode >= 500 => SendErrorKind::Transient,
            $errorCode !== 400 => SendErrorKind::Unknown,
            \str_contains($text, 'parse entities'),
            \str_contains($text, 'find end of the entity'),
            \str_contains($text, 'unsupported start tag'),
            \str_contains($text, "can't parse") => SendErrorKind::BadFormat,
            \str_contains($text, 'too long') => SendErrorKind::TooLong,
            \str_contains($text, 'not enough rights'),
            \str_contains($text, 'have no rights'),
            \str_contains($text, 'chat_write_forbidden') => SendErrorKind::Forbidden,
            \str_contains($text, 'not found') => SendErrorKind::NotFound,
            default => SendErrorKind::Unknown,
        };
    }

    /**
     * Telegram rejects an edit that does not change the message; for the caller this is a success.
     */
    public function isNotModified(): bool
    {
        return \str_contains(\strtolower($this->description), 'message is not modified');
    }
}
