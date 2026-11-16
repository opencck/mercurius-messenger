<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Amp\PHPUnit\AsyncTestCase;
use Amp\DeferredCancellation;
use Mercurius\Messenger\Telegram\LongPolling;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\UpdateParser;
use Mercurius\Messenger\Test\Support\FakeTelegramServer;
use Mercurius\Messenger\Test\Support\RecordingHandler;
use Revolt\EventLoop;

use function Amp\async;

final class LongPollingTest extends AsyncTestCase
{
    private FakeTelegramServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setTimeout(5);
        $this->server = new FakeTelegramServer();
    }

    private function polling(RecordingHandler $handler): LongPolling
    {
        return new LongPolling(
            new TelegramApi(FakeTelegramServer::TOKEN, $this->server->client()),
            new UpdateParser(),
            $handler,
            minBackoff: 0.01,
            maxBackoff: 0.05,
        );
    }

    /**
     * Runs polling until the server has served `$polls` getUpdates calls, then stops it.
     */
    private function runUntil(LongPolling $polling, int $polls): void
    {
        $run = async(fn () => $polling->run());
        $this->server->waitForCalls('getUpdates', $polls);
        $polling->stop();
        $run->await();
        $polling->dispatcher()->awaitIdle();
    }

    public function testOffsetAdvancesAndDuplicatesAreDropped(): void
    {
        $this->server->respondOk('getUpdates', [
            RecordingHandler::update(1, '10', 'a'),
            RecordingHandler::update(2, '10', 'b'),
        ]);
        // Redelivery of update 2 (e.g. the acknowledgement was lost) plus a new one and a channel post.
        $this->server->respondOk('getUpdates', [
            RecordingHandler::update(2, '10', 'b'),
            RecordingHandler::update(3, '10', 'c'),
            ['update_id' => 4, 'channel_post' => ['message_id' => 1]],
        ]);
        $handler = new RecordingHandler();
        $polling = $this->polling($handler);

        $this->runUntil($polling, 3);

        self::assertSame(['a', 'b', 'c'], $handler->texts());
        self::assertSame([0, 3, 5], \array_column($this->server->params('getUpdates'), 'offset'));
        self::assertSame(5, $polling->offset());
        self::assertSame('deleteWebhook', $this->server->calls[0]['method']);
        self::assertFalse($polling->isRunning());
    }

    public function testChatsRunConcurrentlyButEachChatInOrder(): void
    {
        $this->server->respondOk('getUpdates', [
            RecordingHandler::update(1, '10', 'A1'),
            RecordingHandler::update(2, '10', 'A2'),
            RecordingHandler::update(3, '20', 'B1'),
        ]);
        $handler = new RecordingHandler(['A1' => 0.1]);
        $polling = $this->polling($handler);

        $this->runUntil($polling, 2);

        $events = $handler->events;
        $pos = static fn (string $event): int => (int) \array_search($event, $events, true);
        self::assertCount(6, $events);
        self::assertLessThan($pos('start:A2'), $pos('end:A1'), 'messages of one chat are handled in order');
        self::assertLessThan($pos('end:A1'), $pos('end:B1'), 'a slow chat does not block another chat');
    }

    public function testPollingDoesNotWaitForHandlers(): void
    {
        $this->server->respondOk('getUpdates', [RecordingHandler::update(1, '10', 'slow')]);
        $this->server->respondOk('getUpdates', [RecordingHandler::update(2, '20', 'fast')]);
        $handler = new RecordingHandler(['slow' => 0.2]);
        $polling = $this->polling($handler);

        $this->runUntil($polling, 3);

        self::assertSame(['fast', 'slow'], $handler->texts());
    }

    public function testBacksOffAndRecoversAfterErrors(): void
    {
        $this->server->failNetwork('getUpdates');
        $this->server->respondError('getUpdates', 502, 'Bad Gateway');
        $this->server->respondOk('getUpdates', [RecordingHandler::update(7, '10', 'after errors')]);
        $handler = new RecordingHandler();
        $polling = $this->polling($handler);

        $this->runUntil($polling, 4);

        self::assertSame(['after errors'], $handler->texts());
        self::assertSame(8, $polling->offset());
    }

    public function testHandlerFailureDoesNotStopTheChat(): void
    {
        $this->server->respondOk('getUpdates', [
            RecordingHandler::update(1, '10', 'boom'),
            RecordingHandler::update(2, '10', 'next'),
        ]);
        $handler = new RecordingHandler(failOn: ['boom']);
        $polling = $this->polling($handler);

        $this->runUntil($polling, 2);

        self::assertSame(['next'], $handler->texts());
    }

    public function testStopsOnCancellation(): void
    {
        $polling = $this->polling(new RecordingHandler());
        $start = \microtime(true);

        // TimeoutCancellation's timer is unreferenced and the fake server holds no socket, so cancel explicitly.
        $source = new DeferredCancellation();
        EventLoop::delay(0.05, static fn () => $source->cancel());
        $polling->run($source->getCancellation());

        self::assertLessThan(1.0, \microtime(true) - $start);
        self::assertFalse($polling->isRunning());
    }

    public function testRejectsSecondRun(): void
    {
        $polling = $this->polling(new RecordingHandler());
        $run = async(fn () => $polling->run());
        $this->server->waitForCalls('getUpdates', 1);

        try {
            $polling->run();
            self::fail('Expected LogicException');
        } catch (\LogicException) {
            self::assertTrue($polling->isRunning());
        } finally {
            $polling->stop();
            $run->await();
        }
    }
}
