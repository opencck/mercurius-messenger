<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Websocket;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\RoutingChannel;
use Mercurius\Messenger\Test\Support\RecordingConnection;
use Mercurius\Messenger\Websocket\WebsocketChannel;
use PHPUnit\Framework\TestCase;

final class WebsocketChannelTest extends TestCase
{
    private static function connection(): RecordingConnection
    {
        return new RecordingConnection();
    }

    public function testAMessageWithButtonsGoesToTheClientOfTheChat(): void
    {
        $channel = new WebsocketChannel();
        $client = self::connection();
        $channel->attach('ws:p1', $client);

        $result = $channel->send(new OutgoingMessage('ws:p1', 'Привет', buttons: [[new Button('Да', 'ap:1:y')]]));

        self::assertTrue($result->success);
        self::assertSame('message', $client->frames[0]['type']);
        self::assertSame('Привет', $client->frames[0]['text']);
        self::assertSame($result->messageId, $client->frames[0]['id']);
        self::assertSame([[['text' => 'Да', 'data' => 'ap:1:y']]], $client->frames[0]['buttons']);

        self::assertTrue($channel->edit('ws:p1', (string) $result->messageId, 'Пока')->success);
        self::assertSame(['type' => 'edit', 'id' => $result->messageId, 'text' => 'Пока', 'buttons' => []], $client->frames[1]);
    }

    public function testMessagesWhileNobodyIsConnectedWaitForTheClient(): void
    {
        $channel = new WebsocketChannel(outboxMax: 2);
        $channel->send(new OutgoingMessage('ws:p1', 'один'));
        $channel->send(new OutgoingMessage('ws:p1', 'два'));
        $channel->send(new OutgoingMessage('ws:p1', 'три'));

        $client = self::connection();
        $channel->attach('ws:p1', $client);

        self::assertSame(['два', 'три'], array_column($client->frames, 'text'), 'the oldest is dropped over the limit');
        $channel->send(new OutgoingMessage('ws:p1', 'четыре'));
        self::assertCount(3, $client->frames, 'the outbox is not sent twice');
    }

    public function testAClosedConnectionIsForgotten(): void
    {
        $channel = new WebsocketChannel();
        $client = self::connection();
        $key = $channel->attach('ws:p1', $client);
        $channel->detach('ws:p1', $key);

        $channel->send(new OutgoingMessage('ws:p1', 'потом'));
        self::assertSame([], $client->frames);
    }

    public function testIncomingFramesBecomeMessagesAndPresses(): void
    {
        $channel = new WebsocketChannel();
        $client = self::connection();
        $channel->attach('ws:p1', $client);

        $message = $channel->receive('ws:p1', 'p1', 'Owner', json_encode(['type' => 'message', 'text' => 'сделай отчёт', 'files' => [['name' => 'a.txt', 'mime' => 'text/plain', 'content_base64' => base64_encode('hello')]]], JSON_THROW_ON_ERROR));
        self::assertInstanceOf(IncomingMessage::class, $message);
        self::assertSame('ws', $message->channel);
        self::assertSame('ws:p1', $message->chatId);
        self::assertSame('p1', $message->userId);
        self::assertTrue($message->isPrivateChat);
        self::assertSame('сделай отчёт', $message->text);
        self::assertCount(1, $message->attachments);
        self::assertSame('a.txt', $message->attachments[0]->fileName);
        self::assertSame('hello', $channel->download($message->attachments[0]->fileId, 100));
        self::assertNull($channel->download($message->attachments[0]->fileId, 2), 'over the limit');
        self::assertSame('ack', $client->frames[0]['type'], 'the client learns the id of its message');

        $press = $channel->receive('ws:p1', 'p1', 'Owner', '{"type":"callback","data":"ap:7:y","message_id":"m3"}');
        self::assertInstanceOf(IncomingCallback::class, $press);
        self::assertSame('ap:7:y', $press->data);
        self::assertSame('m3', $press->messageId);
        $channel->answerCallback($press->callbackId, 'Готово');
        self::assertSame(['type' => 'answer', 'callback_id' => $press->callbackId, 'text' => 'Готово', 'alert' => false], $client->frames[1]);
    }

    public function testBadFramesAreRefused(): void
    {
        $channel = new WebsocketChannel();
        $refused = 0;
        foreach (['not json', '{"type":"message"}', '{"type":"message","text":5}', '{"type":"spam"}', '{"type":"message","text":"x","files":[{"name":"a","content_base64":"%%%"}]}'] as $frame) {
            try {
                $channel->receive('ws:p1', 'p1', null, $frame);
                self::fail($frame);
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }
        self::assertSame(5, $refused);
    }

    public function testFilesArePassedWhole(): void
    {
        $channel = new WebsocketChannel();
        $client = self::connection();
        $channel->attach('ws:p1', $client);

        self::assertTrue($channel->sendFile('ws:p1', 'r.txt', 'data', 'итог')->success);

        self::assertSame('file', $client->frames[0]['type']);
        self::assertSame('r.txt', $client->frames[0]['name']);
        self::assertSame(base64_encode('data'), $client->frames[0]['content_base64']);
        self::assertSame('итог', $client->frames[0]['caption']);
    }

    public function testTheRouterSendsByThePrefixOfTheChat(): void
    {
        $ws = new WebsocketChannel();
        $client = self::connection();
        $ws->attach('ws:p1', $client);
        $other = new WebsocketChannel();
        $otherClient = self::connection();
        $other->attach('100', $otherClient);
        $router = new RoutingChannel($other, [WebsocketChannel::PREFIX => $ws]);

        $router->send(new OutgoingMessage('ws:p1', 'в сокет'));
        $router->send(new OutgoingMessage('100', 'в телеграм'));

        self::assertSame(['в сокет'], array_column($client->frames, 'text'));
        self::assertSame(['в телеграм'], array_column($otherClient->frames, 'text'));
        self::assertSame($other->name(), $router->name());
        $press = $ws->receive('ws:p1', 'p1', null, '{"type":"callback","data":"x"}');
        self::assertInstanceOf(IncomingCallback::class, $press);
        $router->answerCallback($press->callbackId);
        self::assertSame('answer', $client->frames[1]['type'], 'a press of the socket is answered there');
    }

    public function testAChatWhoseChannelHasNoTopicsGetsAFailure(): void
    {
        $ws = new WebsocketChannel();
        $client = self::connection();
        $ws->attach('ws:p1', $client);
        $router = new RoutingChannel(new WebsocketChannel(), [WebsocketChannel::PREFIX => $ws]);

        self::assertNull($router->createTopic('ws:p1', 'Сайт'));
        self::assertFalse($router->closeTopic('ws:p1', '5'));
        self::assertTrue($router->sendPoll('ws:p1', 'Куда?', ['А', 'Б'])->success, 'the socket has polls');
        self::assertSame('poll', $client->frames[0]['type'] ?? null);
    }
}
