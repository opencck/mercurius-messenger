<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\ChatDispatcher;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\TelegramChannel;
use Mercurius\Messenger\Telegram\UpdateParser;
use Mercurius\Messenger\Telegram\WebhookProcessor;
use Mercurius\Messenger\Test\Support\FakeTelegramServer;
use Mercurius\Messenger\Test\Support\RecordingHandler;

use function Amp\delay;

final class ButtonsAndCallbacksTest extends AsyncTestCase
{
    private FakeTelegramServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeTelegramServer();
    }

    private function channel(): TelegramChannel
    {
        return new TelegramChannel(new TelegramApi(FakeTelegramServer::TOKEN, $this->server->client()), 0.0);
    }

    public function testButtonsGoAsInlineKeyboard(): void
    {
        $this->channel()->send(new OutgoingMessage('1001', 'Подтвердите', buttons: [[new Button('Да', 'ap:1:a'), new Button('Нет', 'ap:1:d')]]));

        $markup = $this->server->params('sendMessage')[0]['reply_markup'] ?? null;
        self::assertSame(['inline_keyboard' => [[['text' => 'Да', 'callback_data' => 'ap:1:a'], ['text' => 'Нет', 'callback_data' => 'ap:1:d']]]], $markup);
    }

    public function testEditWithoutButtonsRemovesKeyboard(): void
    {
        $this->channel()->edit('1001', '5', 'Решено');

        $markup = $this->server->params('editMessageText')[0]['reply_markup'] ?? null;
        self::assertSame(['inline_keyboard' => []], $markup);
    }

    public function testCallbackDataLongerThan64BytesIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Button('x', str_repeat('a', 65));
    }

    public function testAnswerCallback(): void
    {
        $this->channel()->answerCallback('cb1', 'Только владелец', true);

        self::assertSame(['callback_query_id' => 'cb1', 'text' => 'Только владелец', 'show_alert' => true], $this->server->params('answerCallbackQuery')[0]);
    }

    public function testSendFileUploadsDocument(): void
    {
        $this->server->respondOk('sendDocument', ['message_id' => 77]);

        $result = $this->channel()->sendFile('1001', 'report.txt', "line\n", 'Отчёт');

        self::assertTrue($result->success);
        self::assertSame('77', $result->messageId);
        self::assertSame(1, count($this->server->params('sendDocument')));
    }

    public function testParserReadsCallbackQuery(): void
    {
        $callback = (new UpdateParser())->parseCallback([
            'update_id' => 9,
            'callback_query' => [
                'id' => 'cb7',
                'from' => ['id' => 42, 'first_name' => 'Ann', 'is_bot' => false],
                'message' => ['message_id' => 5, 'chat' => ['id' => 1001, 'type' => 'private']],
                'data' => 'ap:3:a',
            ],
        ]);

        self::assertInstanceOf(IncomingCallback::class, $callback);
        self::assertSame('cb7', $callback->callbackId);
        self::assertSame('1001', $callback->chatId);
        self::assertSame('42', $callback->userId);
        self::assertSame('5', $callback->messageId);
        self::assertSame('ap:3:a', $callback->data);
        self::assertSame('Ann', $callback->displayName);
    }

    public function testCallbackBypassesTheBusyChatQueue(): void
    {
        $handler = new RecordingHandler(['long' => 2.0]);
        $callbacks = new class implements CallbackHandler {
            /** @var list<string> */
            public array $seen = [];

            public function handleCallback(IncomingCallback $callback): void
            {
                $this->seen[] = $callback->data;
            }
        };
        $dispatcher = new ChatDispatcher($handler, callbacks: $callbacks);
        $processor = new WebhookProcessor('secret-1234567890', new UpdateParser(), $dispatcher);
        $processor->process('secret-1234567890', (string) json_encode(['update_id' => 1, 'message' => [
            'message_id' => 1, 'date' => 0, 'chat' => ['id' => 1001, 'type' => 'private'], 'from' => ['id' => 42, 'first_name' => 'A'], 'text' => 'long',
        ]]));
        delay(0.05);
        $processor->process('secret-1234567890', (string) json_encode(['update_id' => 2, 'callback_query' => [
            'id' => 'cb', 'from' => ['id' => 42, 'first_name' => 'A'], 'message' => ['message_id' => 5, 'chat' => ['id' => 1001, 'type' => 'private']], 'data' => 'stop',
        ]]));
        delay(0.1);

        self::assertSame(['stop'], $callbacks->seen);
        $dispatcher->awaitIdle();
    }
}
