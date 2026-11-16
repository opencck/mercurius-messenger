<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * Outcome of `Channel::send()` or `Channel::edit()`.
 *
 * API errors are returned as a failed result, not thrown. A failed result may still carry ids of the parts that
 * were delivered before the failure (partial delivery of a split text).
 */
final readonly class SendResult
{
    /**
     * @param list<string> $continuationMessageIds
     */
    private function __construct(
        public bool $success,
        public ?string $messageId,
        public array $continuationMessageIds,
        public ?SendErrorKind $errorKind,
        public ?int $retryAfter,
        public ?string $error,
    ) {
    }

    /**
     * @param string|null $messageId id of the (first) delivered message
     * @param list<string> $continuationMessageIds ids of the following parts when the text was split
     */
    public static function ok(?string $messageId = null, array $continuationMessageIds = []): self
    {
        return new self(true, $messageId, $continuationMessageIds, null, null, null);
    }

    /**
     * @param string|null $error human-readable reason, safe to log (contains no secrets)
     * @param int|null $retryAfter seconds to wait before retrying (flood control)
     * @param string|null $messageId id of the first part, when some parts were delivered before the failure
     * @param list<string> $continuationMessageIds ids of further delivered parts
     */
    public static function failed(
        SendErrorKind $kind,
        ?string $error = null,
        ?int $retryAfter = null,
        ?string $messageId = null,
        array $continuationMessageIds = [],
    ): self {
        return new self(false, $messageId, $continuationMessageIds, $kind, $retryAfter, $error);
    }

    /**
     * Ids of all delivered messages: the first one followed by the continuations.
     *
     * @return list<string>
     */
    public function messageIds(): array
    {
        return $this->messageId === null
            ? $this->continuationMessageIds
            : [$this->messageId, ...$this->continuationMessageIds];
    }

    public function isRetryable(): bool
    {
        return $this->errorKind?->isRetryable() ?? false;
    }
}
