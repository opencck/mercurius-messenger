<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\Telegram\UpdateParser;
use Mercurius\Messenger\Telegram\WebhookProcessor;
use Mercurius\Messenger\Test\Support\RecordingHandler;

final class WebhookProcessorTest extends AsyncTestCase
{
    private const SECRET = 'webhook-secret_1';

    public function testDispatchesValidUpdateOnce(): void
    {
        $handler = new RecordingHandler();
        $processor = new WebhookProcessor(self::SECRET, new UpdateParser(), $handler);
        $body = \json_encode(RecordingHandler::update(5, '10', 'hi'), \JSON_THROW_ON_ERROR);

        self::assertTrue($processor->process(self::SECRET, $body));
        self::assertTrue($processor->process(self::SECRET, $body), 'a redelivered update is acknowledged');
        $processor->dispatcher()->awaitIdle();

        self::assertSame(['hi'], $handler->texts());
    }

    public function testRejectsInvalidSecret(): void
    {
        $handler = new RecordingHandler();
        $processor = new WebhookProcessor(self::SECRET, new UpdateParser(), $handler);
        $body = \json_encode(RecordingHandler::update(5, '10', 'forged'), \JSON_THROW_ON_ERROR);

        self::assertFalse($processor->process('wrong', $body));
        self::assertFalse($processor->process(null, $body));
        $processor->dispatcher()->awaitIdle();

        self::assertSame([], $handler->texts());
    }

    public function testAcknowledgesMalformedBodies(): void
    {
        $handler = new RecordingHandler();
        $processor = new WebhookProcessor(self::SECRET, new UpdateParser(), $handler);

        self::assertTrue($processor->process(self::SECRET, '{not json'));
        self::assertTrue($processor->process(self::SECRET, '"string"'));
        self::assertTrue($processor->process(self::SECRET, \str_repeat(' ', WebhookProcessor::MAX_BODY_BYTES + 1)));
        $processor->dispatcher()->awaitIdle();

        self::assertSame([], $handler->texts());
    }

    public function testRequiresSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WebhookProcessor('', new UpdateParser(), new RecordingHandler());
    }
}
