<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Telegram;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Mercurius\Messenger\ChatDispatcher;
use Mercurius\Messenger\Deduplicator;
use Mercurius\Messenger\MessageHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\delay;

/**
 * Receives updates with `getUpdates` in a loop.
 *
 * The offset advances past every received update before the messages are handled, so a crash in a handler does
 * not cause redelivery (at-most-once after acknowledgement); repeated `update_id`s are dropped by the
 * deduplicator. Messages go to a `ChatDispatcher`: chats are handled concurrently, one chat in order, and polling
 * never waits for a handler. Errors back off exponentially (honouring `retry_after`); `stop()` or the
 * cancellation passed to `run()` ends the loop.
 *
 * ref: notes/hermes-agent-gateway-security.md, section 3.1 — webhook removed before polling, chats in parallel and
 * one chat in order, deduplication by update_id.
 */
final class LongPolling
{
    private readonly ChatDispatcher $dispatcher;
    private ?DeferredCancellation $stop = null;
    private int $offset = 0;

    /**
     * @param MessageHandler|ChatDispatcher $handler a handler is wrapped into a `ChatDispatcher`
     * @param int $pollTimeout long-poll timeout, seconds
     * @param bool $deleteWebhook remove a configured webhook first (polling fails with 409 while one is set)
     * @param list<string> $allowedUpdates
     */
    public function __construct(
        private readonly TelegramApi $api,
        private readonly UpdateParser $parser,
        MessageHandler|ChatDispatcher $handler,
        private readonly Deduplicator $deduplicator = new Deduplicator(),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $pollTimeout = 30,
        private readonly float $minBackoff = 1.0,
        private readonly float $maxBackoff = 60.0,
        private readonly bool $deleteWebhook = true,
        private readonly array $allowedUpdates = ['message', 'callback_query', 'my_chat_member', 'channel_post', 'edited_channel_post'],
    ) {
        $this->dispatcher = $handler instanceof ChatDispatcher ? $handler : new ChatDispatcher($handler, $logger);
    }

    /**
     * Polls until `stop()` is called or `$cancellation` is cancelled. Does not wait for running handlers:
     * use `dispatcher()->awaitIdle()` for that.
     */
    public function run(?Cancellation $cancellation = null): void
    {
        if ($this->stop !== null) {
            throw new \LogicException('Long polling is already running');
        }
        $stop = new DeferredCancellation();
        $this->stop = $stop;
        $cancel = $cancellation === null
            ? $stop->getCancellation()
            : new CompositeCancellation($cancellation, $stop->getCancellation());

        $backoff = $this->minBackoff;
        $webhookDeleted = !$this->deleteWebhook;
        $this->logger->info('Telegram long polling started', ['offset' => $this->offset]);

        try {
            while (!$cancel->isRequested()) {
                try {
                    if (!$webhookDeleted) {
                        $this->api->deleteWebhook(false, $cancel);
                        $webhookDeleted = true;
                    }
                    $updates = $this->api->getUpdates($this->offset, $this->pollTimeout, $cancel, $this->allowedUpdates);
                    $backoff = $this->minBackoff;
                } catch (TelegramException $e) {
                    $wait = \max($backoff, (float) ($e->retryAfter ?? 0));
                    $this->logger->warning('Telegram polling failed, retrying', [
                        'error' => $e->getMessage(),
                        'kind' => $e->kind->value,
                        'retry_in' => $wait,
                    ]);
                    delay($wait, cancellation: $cancel);
                    $backoff = \min($backoff * 2.0, $this->maxBackoff);
                    continue;
                }

                $this->accept($updates);
            }
        } catch (CancelledException) {
            // Stopped.
        } finally {
            $this->stop = null;
            $this->logger->info('Telegram long polling stopped', ['offset' => $this->offset]);
        }
    }

    public function stop(): void
    {
        $this->stop?->cancel();
    }

    public function isRunning(): bool
    {
        return $this->stop !== null;
    }

    /**
     * The next `update_id` to request.
     */
    public function offset(): int
    {
        return $this->offset;
    }

    public function dispatcher(): ChatDispatcher
    {
        return $this->dispatcher;
    }

    /**
     * @param list<array<array-key, mixed>> $updates
     */
    private function accept(array $updates): void
    {
        foreach ($updates as $update) {
            $updateId = UpdateParser::updateId($update);
            if ($updateId === null) {
                continue;
            }
            $this->offset = \max($this->offset, $updateId + 1);

            $message = $this->parser->parse($update);
            if ($message === null) {
                $callback = $this->parser->parseCallback($update);
                if ($callback !== null && !$this->deduplicator->isDuplicate($callback->dedupeKey())) {
                    $this->dispatcher->dispatchCallback($callback);
                }
                $membership = $callback === null ? $this->parser->parseMembership($update) : null;
                if ($membership !== null && !$this->deduplicator->isDuplicate($membership->dedupeKey())) {
                    $this->dispatcher->dispatchMembership($membership);
                }
                $post = $callback === null && $membership === null ? $this->parser->parsePost($update) : null;
                if ($post !== null && !$this->deduplicator->isDuplicate($post->dedupeKey())) {
                    $this->dispatcher->dispatchPost($post);
                }
                continue;
            }
            if ($this->deduplicator->isDuplicate($message->dedupeKey())) {
                continue;
            }
            $this->dispatcher->dispatch($message);
        }
    }
}
