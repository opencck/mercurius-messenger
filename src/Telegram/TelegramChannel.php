<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Telegram;

use Amp\DeferredFuture;
use Amp\Sync\LocalKeyedMutex;
use Mercurius\Messenger\Capabilities;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\ParseMode;
use Mercurius\Messenger\SendErrorKind;
use Mercurius\Messenger\SendResult;
use Mercurius\Messenger\TextSplitter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\delay;

/**
 * Telegram Bot API channel.
 *
 * - Texts longer than 4096 UTF-16 units are split (paragraphs, lines, code fences kept where possible).
 * - Rejected formatting is retried once as plain text with the markup stripped.
 * - One "slot" per chat: API calls to the same chat are at least `$minSendInterval` apart and the parts of one
 *   message are never interleaved with another message.
 * - Edits waiting for the slot are coalesced: only the newest text is sent, every caller gets its result.
 * - After a 429 the chat is refused locally (no API call) until `retry_after` expires: Telegram extends the
 *   penalty while it is being hit.
 *
 * ref: notes/hermes-agent-gateway-security.md, sections 3.6–3.7 — slot per chat, skipped intermediate edits,
 * plain-text fallback, local flood window, "message is not modified" as success.
 */
final class TelegramChannel implements Channel
{
    public const NAME = 'telegram';
    public const MAX_MESSAGE_LENGTH = 4096;

    /** Bound of the per-chat timing tables; older expired entries are pruned past it. */
    private const MAX_TRACKED_CHATS = 10_000;
    /** Bound of the "last text" table used to skip unchanged edits. */
    private const MAX_TRACKED_MESSAGES = 1_000;

    private readonly TextSplitter $splitter;
    private readonly LocalKeyedMutex $chatLocks;

    /** @var array<string, float> chat id => monotonic time of the next free slot */
    private array $nextSlot = [];

    /** @var array<string, float> chat id => monotonic time when the flood penalty ends */
    private array $floodUntil = [];

    /** @var array<string, array{text: string, mode: ParseMode, deferred: DeferredFuture<SendResult>}> */
    private array $pendingEdits = [];

    /** @var array<string, string> "chat:message" => fingerprint of the last text sent */
    private array $lastText = [];

    /**
     * @param float $minSendInterval minimum pause between API calls to one chat, seconds
     */
    public function __construct(
        private readonly TelegramApi $api,
        private readonly float $minSendInterval = 1.0,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $name = self::NAME,
    ) {
        $this->splitter = new TextSplitter(self::MAX_MESSAGE_LENGTH);
        $this->chatLocks = new LocalKeyedMutex();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(canEdit: true, maxMessageLength: self::MAX_MESSAGE_LENGTH, supportsButtons: false);
    }

    public function send(OutgoingMessage $message): SendResult
    {
        $chunks = $this->splitter->split($message->text);
        if ($chunks === []) {
            return SendResult::failed(SendErrorKind::Unknown, 'Message text is empty');
        }
        $blocked = $this->floodBlocked($message->chatId);
        if ($blocked !== null) {
            return $blocked;
        }

        $ids = [];
        $lock = $this->chatLocks->acquire($message->chatId);
        try {
            foreach ($chunks as $index => $chunk) {
                $result = $this->sendChunk($message, $chunk, $index === 0 ? $message->replyToMessageId : null);
                if ($result instanceof SendResult) {
                    return SendResult::failed(
                        $result->errorKind ?? SendErrorKind::Unknown,
                        $result->error,
                        $result->retryAfter,
                        $ids[0] ?? null,
                        \array_slice($ids, 1),
                    );
                }
                $ids[] = $result;
            }
        } finally {
            $lock->release();
        }

        return SendResult::ok($ids[0], \array_slice($ids, 1));
    }

    public function edit(
        string $chatId,
        string $messageId,
        string $text,
        ParseMode $parseMode = ParseMode::Plain,
    ): SendResult {
        // While streaming, a long text is truncated, not split: a continuation message would become a new edit
        // target and multiply on every update.
        $text = $this->splitter->truncate($text);
        if (\trim($text) === '') {
            return SendResult::failed(SendErrorKind::Unknown, 'Message text is empty');
        }
        $key = $chatId . ':' . $messageId;

        $pending = $this->pendingEdits[$key] ?? null;
        if ($pending !== null) {
            // An edit of this message is waiting for the slot: replace its text and share its result.
            $this->pendingEdits[$key]['text'] = $text;
            $this->pendingEdits[$key]['mode'] = $parseMode;

            return $pending['deferred']->getFuture()->await();
        }
        if (($this->lastText[$key] ?? null) === self::fingerprint($text, $parseMode)) {
            return SendResult::ok($messageId);
        }
        $blocked = $this->floodBlocked($chatId);
        if ($blocked !== null) {
            return $blocked;
        }

        /** @var DeferredFuture<SendResult> $deferred */
        $deferred = new DeferredFuture();
        $deferred->getFuture()->ignore();
        $this->pendingEdits[$key] = ['text' => $text, 'mode' => $parseMode, 'deferred' => $deferred];

        try {
            $lock = $this->chatLocks->acquire($chatId);
            try {
                $this->waitForSlot($chatId);
                // Take the newest text: edits that arrived while waiting have replaced it.
                $latest = $this->pendingEdits[$key];
                unset($this->pendingEdits[$key]);
                $result = $this->editNow($chatId, $messageId, $latest['text'], $latest['mode']);
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            if (($this->pendingEdits[$key]['deferred'] ?? null) === $deferred) {
                unset($this->pendingEdits[$key]);
            }
            $deferred->error($e);

            throw $e;
        }

        $deferred->complete($result);

        return $result;
    }

    public function typing(string $chatId): void
    {
        if ($this->floodBlocked($chatId) !== null) {
            return;
        }
        try {
            $this->api->sendChatAction($chatId);
        } catch (TelegramException $e) {
            $this->registerFlood($chatId, $e);
            $this->logger->debug('Telegram typing indicator failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Sends one part, retrying once as plain text if the formatting is rejected.
     * Must be called under the chat lock.
     *
     * @return string|SendResult the message id or a failed result
     */
    private function sendChunk(OutgoingMessage $message, string $chunk, ?string $replyTo): string|SendResult
    {
        $mode = $message->parseMode;
        $text = $chunk;

        while (true) {
            $blocked = $this->floodBlocked($message->chatId);
            if ($blocked !== null) {
                return $blocked;
            }
            $this->waitForSlot($message->chatId);
            $this->reserveSlot($message->chatId);

            try {
                $sent = $this->api->sendMessage(
                    $message->chatId,
                    $text,
                    $mode,
                    $replyTo,
                    $message->threadId,
                    $message->disableLinkPreview,
                );
            } catch (TelegramException $e) {
                if ($e->kind === SendErrorKind::BadFormat && $mode !== ParseMode::Plain) {
                    $this->logger->notice('Telegram rejected formatting, resending as plain text', [
                        'chat_id' => $message->chatId,
                        'parse_mode' => $mode->value,
                        'error' => $e->getMessage(),
                    ]);
                    $text = self::toPlain($text, $mode);
                    $mode = ParseMode::Plain;
                    continue;
                }

                return $this->failure($message->chatId, $e);
            }

            $id = $sent['message_id'] ?? null;
            if (!\is_int($id) && !\is_string($id)) {
                return SendResult::failed(SendErrorKind::Unknown, 'Telegram response has no message_id');
            }
            $id = (string) $id;
            $this->remember($message->chatId . ':' . $id, self::fingerprint($chunk, $message->parseMode));

            return $id;
        }
    }

    /**
     * Must be called under the chat lock, after the slot wait.
     */
    private function editNow(string $chatId, string $messageId, string $text, ParseMode $parseMode): SendResult
    {
        $key = $chatId . ':' . $messageId;
        $fingerprint = self::fingerprint($text, $parseMode);
        if (($this->lastText[$key] ?? null) === $fingerprint) {
            return SendResult::ok($messageId);
        }

        $mode = $parseMode;
        $body = $text;
        while (true) {
            $blocked = $this->floodBlocked($chatId);
            if ($blocked !== null) {
                return $blocked;
            }
            $this->reserveSlot($chatId);
            try {
                $this->api->editMessageText($chatId, $messageId, $body, $mode);
            } catch (TelegramException $e) {
                if ($e->isNotModified()) {
                    break;
                }
                if ($e->kind === SendErrorKind::BadFormat && $mode !== ParseMode::Plain) {
                    $body = self::toPlain($body, $mode);
                    $mode = ParseMode::Plain;
                    $this->waitForSlot($chatId);
                    continue;
                }

                return $this->failure($chatId, $e);
            }
            break;
        }

        $this->remember($key, $fingerprint);

        return SendResult::ok($messageId);
    }

    private function failure(string $chatId, TelegramException $e): SendResult
    {
        $this->registerFlood($chatId, $e);
        $this->logger->warning('Telegram call failed', [
            'chat_id' => $chatId,
            'method' => $e->method,
            'kind' => $e->kind->value,
            'error' => $e->getMessage(),
        ]);

        return SendResult::failed($e->kind, $e->getMessage(), $e->retryAfter);
    }

    private function registerFlood(string $chatId, TelegramException $e): void
    {
        if ($e->kind === SendErrorKind::RateLimited) {
            $this->floodUntil[$chatId] = self::now() + (float) \max(1, $e->retryAfter ?? 1);
        }
    }

    private function floodBlocked(string $chatId): ?SendResult
    {
        $until = $this->floodUntil[$chatId] ?? null;
        if ($until === null) {
            return null;
        }
        $left = $until - self::now();
        if ($left <= 0) {
            unset($this->floodUntil[$chatId]);

            return null;
        }

        return SendResult::failed(
            SendErrorKind::RateLimited,
            'Chat is in a Telegram flood-control window',
            (int) \ceil($left),
        );
    }

    private function waitForSlot(string $chatId): void
    {
        $wait = ($this->nextSlot[$chatId] ?? 0.0) - self::now();
        if ($wait > 0) {
            delay($wait);
        }
    }

    private function reserveSlot(string $chatId): void
    {
        $now = self::now();
        $this->nextSlot[$chatId] = $now + $this->minSendInterval;

        if (\count($this->nextSlot) > self::MAX_TRACKED_CHATS) {
            $this->nextSlot = \array_filter($this->nextSlot, static fn (float $at): bool => $at > $now);
            $this->floodUntil = \array_filter($this->floodUntil, static fn (float $at): bool => $at > $now);
        }
    }

    private function remember(string $key, string $fingerprint): void
    {
        unset($this->lastText[$key]);
        $this->lastText[$key] = $fingerprint;
        if (\count($this->lastText) > self::MAX_TRACKED_MESSAGES) {
            unset($this->lastText[\array_key_first($this->lastText)]);
        }
    }

    private static function fingerprint(string $text, ParseMode $mode): string
    {
        return \hash('xxh128', $mode->value . "\0" . $text);
    }

    /**
     * Strips markup so that a rejected formatted text can be delivered as plain text.
     */
    private static function toPlain(string $text, ParseMode $mode): string
    {
        $plain = match ($mode) {
            ParseMode::Plain => $text,
            ParseMode::Markdown => MarkdownToTelegramHtml::toPlain($text),
            ParseMode::Html => \html_entity_decode(\strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
            ParseMode::MarkdownV2 => \preg_replace('/\\\\([_*\[\]()~`>#+\-=|{}.!\\\\])/', '$1', $text) ?? $text,
        };

        return \trim($plain) === '' ? $text : $plain;
    }

    private static function now(): float
    {
        return (float) \hrtime(true) / 1e9;
    }
}
