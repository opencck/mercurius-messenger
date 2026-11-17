<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\MediaKind;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\TelegramChannel;
use Mercurius\Messenger\Test\Support\FakeTelegramServer;

/**
 * What the Bot API sends besides text: photos, audio, video, voice, animations, a location, a contact, a poll.
 */
final class RichContentTest extends AsyncTestCase
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

    private function multipart(string $method): string
    {
        /** @var mixed $raw */
        $raw = $this->server->params($method)[0]['multipart'] ?? '';
        return \is_string($raw) ? $raw : '';
    }

    public function testEachMediaKindGoesByItsOwnMethodAndField(): void
    {
        foreach (MediaKind::cases() as $kind) {
            $this->server->respondOk($kind->method(), ['message_id' => 5]);
        }

        foreach (MediaKind::cases() as $kind) {
            $result = $this->channel()->sendMedia('-1001', $kind, 'file.bin', 'BYTES', 'Подпись', '119');
            self::assertTrue($result->success, $kind->value);
            $multipart = $this->multipart($kind->method());
            self::assertStringContainsString('name="' . $kind->value . '"; filename="file.bin"', $multipart, $kind->value);
            self::assertStringContainsString("name=\"message_thread_id\"\r\n\r\n119\r\n", $multipart);
            self::assertStringContainsString("name=\"caption\"\r\n\r\nПодпись\r\n", $multipart);
        }
        self::assertSame(['sendDocument', 'sendPhoto', 'sendAudio', 'sendVideo', 'sendVoice', 'sendAnimation'], array_map(static fn(MediaKind $k): string => $k->method(), MediaKind::cases()));
    }

    public function testLocationContactAndPoll(): void
    {
        $this->server->respondOk('sendLocation', ['message_id' => 6]);
        $this->server->respondOk('sendContact', ['message_id' => 7]);
        $this->server->respondOk('sendPoll', ['message_id' => 8]);
        $channel = $this->channel();

        self::assertSame('6', $channel->sendLocation('1001', 12.93, 100.88, '5')->messageId);
        self::assertSame('7', $channel->sendContact('1001', '+66123456789', 'Сергей', 'Крупкин')->messageId);
        self::assertSame('8', $channel->sendPoll('1001', 'Куда идём?', ['Пляж', 'Горы'], false, true)->messageId);

        self::assertSame(['chat_id' => 1001, 'latitude' => 12.93, 'longitude' => 100.88, 'message_thread_id' => 5], $this->server->params('sendLocation')[0]);
        self::assertSame(['chat_id' => 1001, 'phone_number' => '+66123456789', 'first_name' => 'Сергей', 'last_name' => 'Крупкин'], $this->server->params('sendContact')[0]);
        self::assertSame(['chat_id' => 1001, 'question' => 'Куда идём?', 'options' => [['text' => 'Пляж'], ['text' => 'Горы']], 'is_anonymous' => false, 'allows_multiple_answers' => true], $this->server->params('sendPoll')[0]);
    }

    public function testARefusedPollIsAFailure(): void
    {
        $this->server->respondError('sendPoll', 400, 'Bad Request: poll must have at least 2 options');

        $result = $this->channel()->sendPoll('1001', 'Один?', ['Да'], true, false);

        self::assertFalse($result->success);
    }
}
