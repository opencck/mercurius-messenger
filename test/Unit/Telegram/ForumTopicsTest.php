<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\TelegramChannel;
use Mercurius\Messenger\Test\Support\FakeTelegramServer;

/**
 * Forum topics: create, rename, close, reopen, delete. Failures are answers, not exceptions.
 */
final class ForumTopicsTest extends AsyncTestCase
{
    private FakeTelegramServer $server;
    private TelegramChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeTelegramServer();
        $this->channel = new TelegramChannel(new TelegramApi(FakeTelegramServer::TOKEN, $this->server->client()), 0.0);
    }

    public function testATopicIsCreatedWithACutNameAndItsThreadComesBack(): void
    {
        $this->server->respondOk('createForumTopic', ['message_thread_id' => 77, 'name' => 'x', 'icon_color' => 0]);

        self::assertSame('77', $this->channel->createTopic('-1001', str_repeat('я', 200)));
        $params = $this->server->params('createForumTopic')[0];
        self::assertSame(-1001, $params['chat_id']);
        self::assertSame(str_repeat('я', 128), $params['name'], 'Telegram allows 128 characters');
    }

    public function testTopicsAreRenamedClosedReopenedAndDeleted(): void
    {
        self::assertTrue($this->channel->editTopic('-1001', '77', 'Сайт'));
        self::assertTrue($this->channel->closeTopic('-1001', '77'));
        self::assertTrue($this->channel->reopenTopic('-1001', '77'));
        self::assertTrue($this->channel->deleteTopic('-1001', '77'));

        self::assertSame(['chat_id' => -1001, 'message_thread_id' => 77, 'name' => 'Сайт'], $this->server->params('editForumTopic')[0]);
        self::assertSame(77, $this->server->params('closeForumTopic')[0]['message_thread_id']);
        self::assertSame(77, $this->server->params('reopenForumTopic')[0]['message_thread_id']);
        self::assertSame(77, $this->server->params('deleteForumTopic')[0]['message_thread_id']);
    }

    public function testAChatThatIsNotAForumGivesNoTopic(): void
    {
        $this->server->respondError('createForumTopic', 400, 'Bad Request: the chat is not a forum');
        $this->server->respondError('closeForumTopic', 400, 'Bad Request: not enough rights to manage topics');

        self::assertNull($this->channel->createTopic('-1001', 'Сайт'));
        $this->server->respondOk('createForumTopic', ['name' => 'без номера']);
        self::assertNull($this->channel->createTopic('-1001', 'Сайт'), 'an answer without a thread id is a failure');
        self::assertFalse($this->channel->closeTopic('-1001', '77'));
    }
}
