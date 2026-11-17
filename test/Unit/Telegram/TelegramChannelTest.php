<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\ParseMode;
use Mercurius\Messenger\SendErrorKind;
use Mercurius\Messenger\SendResult;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\TelegramChannel;
use Mercurius\Messenger\TextSplitter;
use Mercurius\Messenger\Test\Support\FakeTelegramServer;

use function Amp\async;

final class TelegramChannelTest extends AsyncTestCase
{
    private FakeTelegramServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeTelegramServer();
    }

    private function channel(float $interval = 0.0): TelegramChannel
    {
        return new TelegramChannel(new TelegramApi(FakeTelegramServer::TOKEN, $this->server->client()), $interval);
    }

    public function testDescribesItself(): void
    {
        $channel = $this->channel();
        self::assertSame('telegram', $channel->name());
        self::assertTrue($channel->capabilities()->canEdit);
        self::assertSame(4096, $channel->capabilities()->maxMessageLength);
    }

    public function testSendsShortMessage(): void
    {
        $result = $this->channel()->send(new OutgoingMessage('1001', 'hello', '7'));

        self::assertTrue($result->success);
        self::assertSame('100', $result->messageId);
        self::assertSame([], $result->continuationMessageIds);
        $reply = $this->server->params('sendMessage')[0]['reply_parameters'] ?? null;
        self::assertIsArray($reply);
        self::assertSame(7, $reply['message_id'] ?? null);
    }

    public function testSplitsLongMessage(): void
    {
        $paragraph = \str_repeat('Длинный абзац текста. ', 100);
        $text = \implode("\n\n", [$paragraph, $paragraph, $paragraph]);

        $result = $this->channel()->send(new OutgoingMessage('1001', $text, '7'));

        self::assertTrue($result->success);
        $calls = $this->server->params('sendMessage');
        self::assertCount(3, $calls);
        self::assertSame('100', $result->messageId);
        self::assertSame(['101', '102'], $result->continuationMessageIds);
        self::assertSame(['100', '101', '102'], $result->messageIds());
        self::assertArrayHasKey('reply_parameters', $calls[0]);
        self::assertArrayNotHasKey('reply_parameters', $calls[1]);
        foreach ($calls as $call) {
            self::assertIsString($call['text']);
            self::assertLessThanOrEqual(4096, TextSplitter::utf16Length($call['text']));
        }
    }

    public function testRejectsEmptyText(): void
    {
        $result = $this->channel()->send(new OutgoingMessage('1001', "  \n "));

        self::assertFalse($result->success);
        self::assertSame([], $this->server->calls);
    }

    public function testRateLimitIsReturnedAndBlocksTheChatLocally(): void
    {
        $this->server->respondError('sendMessage', 429, 'Too Many Requests: retry after 17', ['retry_after' => 17]);
        $channel = $this->channel();

        $result = $channel->send(new OutgoingMessage('1001', 'x'));

        self::assertFalse($result->success);
        self::assertSame(SendErrorKind::RateLimited, $result->errorKind);
        self::assertSame(17, $result->retryAfter);
        self::assertTrue($result->isRetryable());

        // Within the flood window the chat is refused without calling the API; other chats still work.
        $again = $channel->send(new OutgoingMessage('1001', 'y'));
        self::assertSame(SendErrorKind::RateLimited, $again->errorKind);
        self::assertNotNull($again->retryAfter);
        self::assertGreaterThan(0, $again->retryAfter);
        $channel->typing('1001');
        self::assertCount(1, $this->server->calls);

        self::assertTrue($channel->send(new OutgoingMessage('2002', 'z'))->success);
    }

    public function testParseErrorRetriesOnceAsPlainText(): void
    {
        $this->server->respondError('sendMessage', 400, "Bad Request: can't parse entities: Unsupported start tag \"x\"");

        $result = $this->channel()->send(
            new OutgoingMessage('1001', '<b>Bold</b> &amp; <x>broken', parseMode: ParseMode::Html),
        );

        self::assertTrue($result->success);
        $calls = $this->server->params('sendMessage');
        self::assertCount(2, $calls);
        self::assertSame('HTML', $calls[0]['parse_mode']);
        self::assertArrayNotHasKey('parse_mode', $calls[1]);
        self::assertSame('Bold & broken', $calls[1]['text']);
    }

    public function testMarkdownFallbackUnescapes(): void
    {
        $this->server->respondError('sendMessage', 400, "Bad Request: can't parse entities: Character '.' is reserved");

        $result = $this->channel()->send(
            new OutgoingMessage('1001', 'Price 1\.5\!', parseMode: ParseMode::MarkdownV2),
        );

        self::assertTrue($result->success);
        self::assertSame('Price 1.5!', $this->server->params('sendMessage')[1]['text']);
    }

    public function testMarkdownFallbackSendsTextWithoutMarkers(): void
    {
        $this->server->respondError('sendMessage', 400, "Bad Request: can't parse entities: Unexpected end tag");

        $result = $this->channel()->send(
            new OutgoingMessage('1001', '**Bold** text', parseMode: ParseMode::Markdown),
        );

        self::assertTrue($result->success);
        $calls = $this->server->params('sendMessage');
        self::assertSame('<b>Bold</b> text', $calls[0]['text']);
        self::assertArrayNotHasKey('parse_mode', $calls[1]);
        self::assertSame('Bold text', $calls[1]['text']);
    }

    public function testParseErrorInPlainTextIsNotRetried(): void
    {
        $this->server->respondError('sendMessage', 400, "Bad Request: can't parse entities");

        $result = $this->channel()->send(new OutgoingMessage('1001', 'x'));

        self::assertSame(SendErrorKind::BadFormat, $result->errorKind);
        self::assertCount(1, $this->server->calls);
    }

    public function testForbidden(): void
    {
        $this->server->respondError('sendMessage', 403, 'Forbidden: bot was blocked by the user');

        $result = $this->channel()->send(new OutgoingMessage('1001', 'x'));

        self::assertFalse($result->success);
        self::assertSame(SendErrorKind::Forbidden, $result->errorKind);
        self::assertFalse($result->isRetryable());
        self::assertNotNull($result->error);
        self::assertStringNotContainsString(FakeTelegramServer::TOKEN, $result->error);
    }

    public function testPartialDeliveryKeepsDeliveredIds(): void
    {
        $this->server->respondOk('sendMessage', ['message_id' => 1]);
        $this->server->respondError('sendMessage', 502, 'Bad Gateway');
        $text = \str_repeat('a', 4000) . "\n\n" . \str_repeat('b', 4000);

        $result = $this->channel()->send(new OutgoingMessage('1001', $text));

        self::assertFalse($result->success);
        self::assertSame(SendErrorKind::Transient, $result->errorKind);
        self::assertSame('1', $result->messageId);
    }

    public function testEditSendsNewText(): void
    {
        $result = $this->channel()->edit('1001', '55', 'new text');

        self::assertTrue($result->success);
        self::assertSame('55', $result->messageId);
        self::assertSame(
            ['chat_id' => 1001, 'message_id' => 55, 'text' => 'new text', 'reply_markup' => ['inline_keyboard' => []]],
            $this->server->params('editMessageText')[0],
        );
    }

    public function testEditWithUnchangedTextIsNoOp(): void
    {
        $channel = $this->channel();
        $sent = $channel->send(new OutgoingMessage('1001', 'same'));
        self::assertNotNull($sent->messageId);

        $result = $channel->edit('1001', $sent->messageId, 'same');

        self::assertTrue($result->success);
        self::assertSame([], $this->server->params('editMessageText'));
    }

    public function testNotModifiedFromApiIsSuccess(): void
    {
        $this->server->respondError('editMessageText', 400, 'Bad Request: message is not modified');

        $result = $this->channel()->edit('1001', '55', 'whatever');

        self::assertTrue($result->success);
        self::assertSame('55', $result->messageId);
    }

    public function testEditTruncatesLongText(): void
    {
        $this->channel()->edit('1001', '55', \str_repeat('я', 5000));

        $text = $this->server->params('editMessageText')[0]['text'];
        self::assertIsString($text);
        self::assertSame(4096, TextSplitter::utf16Length($text));
        self::assertStringEndsWith('…', $text);
    }

    public function testEditsWithinIntervalAreCoalesced(): void
    {
        $channel = $this->channel(0.2);
        $channel->send(new OutgoingMessage('1001', 'start'));

        $futures = [];
        foreach (['one', 'two', 'three'] as $text) {
            $futures[] = async(fn () => $channel->edit('1001', '100', $text));
        }
        $results = Future\await($futures);

        foreach ($results as $result) {
            self::assertInstanceOf(SendResult::class, $result);
            self::assertTrue($result->success);
        }
        $edits = $this->server->params('editMessageText');
        self::assertCount(1, $edits, 'superseded edits are skipped');
        self::assertSame('three', $edits[0]['text']);
    }

    public function testEditsAfterCoalescedOnesStillReachTheChat(): void
    {
        $channel = $this->channel(0.2);
        $channel->send(new OutgoingMessage('1001', 'start'));

        Future\await([
            async(fn () => $channel->edit('1001', '100', 'tool one')),
            async(fn () => $channel->edit('1001', '100', 'tool two')),
        ]);
        $channel->edit('1001', '100', 'streamed', ParseMode::Markdown);
        $final = $channel->edit('1001', '100', 'final **answer**', ParseMode::Markdown);

        self::assertTrue($final->success);
        $texts = \array_map(static fn (array $p): mixed => $p['text'] ?? null, $this->server->params('editMessageText'));
        self::assertSame(['tool two', 'streamed', 'final <b>answer</b>'], $texts);
    }

    public function testSendsToOneChatArePaced(): void
    {
        $channel = $this->channel(0.2);

        Future\await([
            async(fn () => $channel->send(new OutgoingMessage('1001', 'a'))),
            async(fn () => $channel->send(new OutgoingMessage('1001', 'b'))),
            async(fn () => $channel->send(new OutgoingMessage('2002', 'c'))),
        ]);

        $a = $this->server->sentAt('a');
        self::assertGreaterThanOrEqual(0.18, $this->server->sentAt('b') - $a);
        self::assertLessThan(0.1, \abs($this->server->sentAt('c') - $a), 'another chat is not delayed');
    }

    public function testDeleteRemovesTheMessageAndReportsFailure(): void
    {
        $channel = $this->channel();

        self::assertTrue($channel->delete('1001', '55'));
        self::assertSame(['chat_id' => 1001, 'message_id' => 55], $this->server->params('deleteMessage')[0]);

        $this->server->respondError('deleteMessage', 400, 'Bad Request: message cannot be deleted');
        self::assertFalse($channel->delete('1001', '56'));
    }

    public function testLeaveLeavesTheChatAndReportsFailure(): void
    {
        $channel = $this->channel();

        self::assertTrue($channel->leave('-1002003004'));
        self::assertSame(['chat_id' => -1002003004], $this->server->params('leaveChat')[0]);

        $this->server->respondError('leaveChat', 400, 'Bad Request: chat not found');
        self::assertFalse($channel->leave('-1002003005'));
    }

    public function testTypingIsBestEffort(): void
    {
        $this->server->failNetwork('sendChatAction');
        $channel = $this->channel();

        $channel->typing('1001');
        $channel->typing('1001');

        self::assertSame(
            [['chat_id' => 1001, 'action' => 'typing'], ['chat_id' => 1001, 'action' => 'typing']],
            $this->server->params('sendChatAction'),
        );
    }
}
