<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

use Amp\Future;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\async;

/**
 * Hands incoming messages to a `MessageHandler` without blocking the receiver.
 *
 * Each chat gets its own queue and its own worker fiber: chats are processed concurrently, while messages of one
 * chat are handled strictly in arrival order, one at a time. A chat's queue is bounded; overflowing messages are
 * dropped and logged. Handler exceptions are logged and do not stop the queue.
 */
final class ChatDispatcher
{
    /** @var array<string, list<IncomingMessage>> chat id => waiting messages, the oldest first */
    private array $queues = [];

    /** @var array<string, Future<void>> */
    private array $workers = [];

    public function __construct(
        private readonly MessageHandler $handler,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxQueuePerChat = 100,
    ) {
        if ($maxQueuePerChat < 1) {
            throw new \InvalidArgumentException('Queue limit must be positive');
        }
    }

    /**
     * Queues a message for its chat. Returns false when the chat's queue is full and the message was dropped.
     */
    public function dispatch(IncomingMessage $message): bool
    {
        $chatId = $message->chatId;
        $queue = $this->queues[$chatId] ?? [];

        if (\count($queue) >= $this->maxQueuePerChat) {
            $this->logger->warning('Chat queue is full, message dropped', [
                'channel' => $message->channel,
                'chat_id' => $chatId,
                'update_id' => $message->updateId,
            ]);

            return false;
        }

        $queue[] = $message;
        $this->queues[$chatId] = $queue;
        if (!isset($this->workers[$chatId])) {
            // async() starts the fiber on the next tick, so the worker is registered before it runs.
            $this->workers[$chatId] = async($this->drain(...), $chatId);
        }

        return true;
    }

    /**
     * Waits until every queued message has been handled.
     */
    public function awaitIdle(): void
    {
        while ($this->workers !== []) {
            Future\awaitAll($this->workers);
        }
    }

    /**
     * Number of messages waiting in all chat queues (not counting the ones being handled).
     */
    public function pendingCount(): int
    {
        $count = 0;
        foreach ($this->queues as $queue) {
            $count += \count($queue);
        }

        return $count;
    }

    private function drain(string $chatId): void
    {
        try {
            while (true) {
                $queue = $this->queues[$chatId] ?? [];
                $message = \array_shift($queue);
                if ($message === null) {
                    break;
                }
                $this->queues[$chatId] = $queue;
                try {
                    $this->handler->handle($message);
                } catch (\Throwable $e) {
                    $this->logger->error('Message handler failed', [
                        'channel' => $message->channel,
                        'chat_id' => $chatId,
                        'update_id' => $message->updateId,
                        'exception' => $e,
                    ]);
                }
            }
        } finally {
            unset($this->queues[$chatId], $this->workers[$chatId]);
        }
    }
}
