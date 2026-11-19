<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\ChatDispatcher;
use Mercurius\Messenger\Telegram\UpdateParser;
use Mercurius\Messenger\Test\Support\RecordingHandler;

final class ChatDispatcherTest extends AsyncTestCase
{
    public function testDropsMessagesWhenChatQueueIsFull(): void
    {
        $handler = new RecordingHandler();
        $dispatcher = new ChatDispatcher($handler, maxQueuePerChat: 2);
        $parser = new UpdateParser();

        $accepted = [];
        foreach ([1, 2, 3] as $id) {
            $message = $parser->parse(RecordingHandler::update($id, '10', "m{$id}"));
            self::assertNotNull($message);
            $accepted[] = $dispatcher->dispatch($message);
        }
        $other = $parser->parse(RecordingHandler::update(4, '20', 'other chat'));
        self::assertNotNull($other);

        self::assertSame([true, true, false], $accepted);
        self::assertTrue($dispatcher->dispatch($other), 'the limit is per chat');
        self::assertSame(3, $dispatcher->pendingCount());

        $dispatcher->awaitIdle();

        self::assertSame(['m1', 'm2', 'other chat'], $handler->texts());
        self::assertSame(0, $dispatcher->pendingCount());
    }

    public function testATextTheClientSplitIsGluedBack(): void
    {
        $handler = new RecordingHandler();
        $dispatcher = new ChatDispatcher($handler, splitDelay: 0.05);
        $parser = new UpdateParser();
        $long = str_repeat('слово ', 680);
        $message = static fn(int $id, string $chat, string $text): \Mercurius\Messenger\IncomingMessage => $parser->parse(RecordingHandler::update($id, $chat, $text)) ?? throw new \LogicException('not parsed');

        $dispatcher->dispatch($message(1, '10', $long));
        $dispatcher->dispatch($message(2, '10', $long));
        $dispatcher->dispatch($message(3, '20', 'другой чат'));
        $dispatcher->dispatch($message(4, '10', 'конец'));
        $dispatcher->awaitIdle();

        self::assertSame(['другой чат', $long . "
" . $long . "
конец"], $handler->texts(), 'parts near the limit wait for the rest');

        $dispatcher->dispatch($message(5, '10', $long));
        \Amp\delay(0.2);
        $dispatcher->awaitIdle();
        $texts = $handler->texts();
        self::assertSame($long, end($texts), 'a long text with nothing after it goes once the pause is over');
        self::assertCount(3, $texts);
    }
}
