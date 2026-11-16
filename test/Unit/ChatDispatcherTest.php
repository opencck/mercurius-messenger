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
}
