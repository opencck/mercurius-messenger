<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\ParseMode;
use Mercurius\Messenger\SendErrorKind;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\TelegramException;
use Mercurius\Messenger\Test\Support\FakeTelegramServer;

final class TelegramApiTest extends AsyncTestCase
{
    private FakeTelegramServer $server;
    private TelegramApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeTelegramServer();
        $this->api = new TelegramApi(FakeTelegramServer::TOKEN, $this->server->client());
    }

    public function testSendMessageBuildsRequest(): void
    {
        $this->server->respondOk('sendMessage', ['message_id' => 7, 'text' => 'hi']);

        $message = $this->api->sendMessage('-100123', 'hi', ParseMode::Html, '5', '3', true);

        self::assertSame(7, $message['message_id']);
        $call = $this->server->calls[0];
        self::assertSame('https://api.telegram.org/bot' . FakeTelegramServer::TOKEN . '/sendMessage', $call['url']);
        self::assertSame([
            'chat_id' => -100123,
            'text' => 'hi',
            'parse_mode' => 'HTML',
            'reply_parameters' => ['message_id' => 5, 'allow_sending_without_reply' => true],
            'message_thread_id' => 3,
            'link_preview_options' => ['is_disabled' => true],
        ], $call['params']);
    }

    public function testPlainModeOmitsParseMode(): void
    {
        $this->api->sendMessage('@channel', 'x');
        self::assertSame(['chat_id' => '@channel', 'text' => 'x'], $this->server->params('sendMessage')[0]);
    }

    public function testGetUpdatesFiltersEntries(): void
    {
        $this->server->respondOk('getUpdates', [['update_id' => 1], 'junk', ['update_id' => 2]]);

        $updates = $this->api->getUpdates(10, 0);

        self::assertSame([['update_id' => 1], ['update_id' => 2]], $updates);
        self::assertSame(10, $this->server->params('getUpdates')[0]['offset']);
    }

    public function testRateLimitError(): void
    {
        $this->server->respondError('sendMessage', 429, 'Too Many Requests: retry after 17', ['retry_after' => 17]);

        $e = $this->catchError(fn () => $this->api->sendMessage('1', 'x'));

        self::assertSame(SendErrorKind::RateLimited, $e->kind);
        self::assertSame(17, $e->retryAfter);
        self::assertSame(429, $e->errorCode);
        self::assertSame('sendMessage', $e->method);
    }

    public function testErrorClassification(): void
    {
        $cases = [
            [400, "Bad Request: can't parse entities: Can't find end of the entity starting at byte offset 3", SendErrorKind::BadFormat],
            [400, 'Bad Request: message is too long', SendErrorKind::TooLong],
            [400, 'Bad Request: chat not found', SendErrorKind::NotFound],
            [400, 'Bad Request: message to edit not found', SendErrorKind::NotFound],
            [400, 'Bad Request: something odd', SendErrorKind::Unknown],
            [403, 'Forbidden: bot was blocked by the user', SendErrorKind::Forbidden],
            [401, 'Unauthorized', SendErrorKind::Forbidden],
            [502, 'Bad Gateway', SendErrorKind::Transient],
            [409, 'Conflict: terminated by other getUpdates request', SendErrorKind::Unknown],
        ];
        foreach ($cases as [$code, $description, $kind]) {
            self::assertSame($kind, TelegramException::classify($code, $description), $description);
        }
    }

    public function testNotModifiedIsRecognised(): void
    {
        $this->server->respondError(
            'editMessageText',
            400,
            'Bad Request: message is not modified: specified new message content and reply markup are exactly the same',
        );

        $e = $this->catchError(fn () => $this->api->editMessageText('1', '2', 'same'));

        self::assertTrue($e->isNotModified());
    }

    public function testNetworkErrorIsTransientAndHidesToken(): void
    {
        $this->server->failNetwork('getMe');

        $e = $this->catchError(fn () => $this->api->getMe());

        self::assertSame(SendErrorKind::Transient, $e->kind);
        self::assertSame(0, $e->errorCode);
        self::assertNull($e->getPrevious());
        self::assertStringNotContainsString(FakeTelegramServer::TOKEN, $e->getMessage());
    }

    public function testNonJsonResponse(): void
    {
        $this->server->respond('getMe', 502, '<html>Bad Gateway</html>');

        $e = $this->catchError(fn () => $this->api->getMe());

        self::assertSame(SendErrorKind::Transient, $e->kind);
        self::assertSame(502, $e->errorCode);
    }

    public function testTokenIsRedactedFromDescriptionsAndDebugOutput(): void
    {
        $this->server->respondError('getMe', 404, 'Not Found: /bot' . FakeTelegramServer::TOKEN . '/getMe');

        $e = $this->catchError(fn () => $this->api->getMe());

        self::assertStringNotContainsString(FakeTelegramServer::TOKEN, $e->getMessage());
        self::assertStringContainsString('***', $e->description);
        self::assertStringNotContainsString(FakeTelegramServer::TOKEN, \print_r($this->api, true));
        self::assertSame('a *** b', $this->api->redact('a ' . \rawurlencode(FakeTelegramServer::TOKEN) . ' b'));
    }

    public function testRejectsMalformedToken(): void
    {
        try {
            new TelegramApi('not a token', $this->server->client());
            self::fail('Expected an exception');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('not a token', $e->getMessage());
        }
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function catchError(\Closure $call): TelegramException
    {
        try {
            $call();
        } catch (TelegramException $e) {
            return $e;
        }
        self::fail('Expected TelegramException');
    }
}
