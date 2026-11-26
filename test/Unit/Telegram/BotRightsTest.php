<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\TelegramChannel;
use Mercurius\Messenger\Test\Support\FakeTelegramServer;

/**
 * What the bot may do in a group: whether it sees every message (privacy mode off, or an administrator), and whether it
 * may manage and delete forum topics.
 */
final class BotRightsTest extends AsyncTestCase
{
    private FakeTelegramServer $server;
    private TelegramChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeTelegramServer();
        $this->channel = new TelegramChannel(new TelegramApi(FakeTelegramServer::TOKEN, $this->server->client()), 0.0);
    }

    public function testAMemberInPrivacyModeSeesOnlyWhatIsAddressedToIt(): void
    {
        $this->server->respondOk('getMe', ['id' => 42, 'is_bot' => true, 'username' => 'bot', 'can_read_all_group_messages' => false]);
        $this->server->respondOk('getChatMember', ['user' => ['id' => 42], 'status' => 'member']);

        $rights = $this->channel->rights('-1001');

        self::assertNotNull($rights);
        self::assertSame(['member', false, false, false], [$rights->status, $rights->seesAll(), $rights->canManageTopics, $rights->canDeleteTopics]);
        self::assertSame(['chat_id' => -1001, 'user_id' => 42], $this->server->params('getChatMember')[0]);
    }

    public function testAnAdministratorSeesAllAndHasTheRightsItWasGiven(): void
    {
        $this->server->respondOk('getMe', ['id' => 42, 'is_bot' => true, 'username' => 'bot', 'can_read_all_group_messages' => false]);
        $this->server->respondOk('getChatMember', ['user' => ['id' => 42], 'status' => 'administrator', 'can_manage_topics' => true, 'can_delete_messages' => true]);

        $rights = $this->channel->rights('-1001');

        self::assertNotNull($rights);
        self::assertSame([true, true, true], [$rights->seesAll(), $rights->canManageTopics, $rights->canDeleteTopics]);
    }

    public function testPrivacyOffLetsAMemberSeeAllAndAFailureGivesNothing(): void
    {
        $this->server->respondOk('getMe', ['id' => 42, 'is_bot' => true, 'username' => 'bot', 'can_read_all_group_messages' => true]);
        $this->server->respondOk('getChatMember', ['user' => ['id' => 42], 'status' => 'member']);
        self::assertTrue($this->channel->rights('-1001')?->seesAll());

        $this->server->respondError('getChatMember', 400, 'Bad Request: chat not found');
        self::assertNull($this->channel->rights('-1002'));
    }
}
