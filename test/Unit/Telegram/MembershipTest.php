<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\ChatDispatcher;
use Mercurius\Messenger\IncomingMembership;
use Mercurius\Messenger\MembershipHandler;
use Mercurius\Messenger\Telegram\UpdateParser;
use Mercurius\Messenger\Telegram\WebhookProcessor;
use Mercurius\Messenger\Test\Support\RecordingHandler;

use function Amp\delay;

/**
 * The bot learns that it was added to a chat or removed from it (`my_chat_member`), without waiting for a message.
 */
final class MembershipTest extends AsyncTestCase
{
    /** @return array<string, mixed> */
    private static function update(int $id, string $status, string $type = 'supergroup', bool $byBot = false): array
    {
        return ['update_id' => $id, 'my_chat_member' => [
            'chat' => ['id' => -1001, 'title' => 'Дом', 'type' => $type],
            'from' => ['id' => 42, 'first_name' => 'Ann', 'is_bot' => $byBot],
            'date' => 1_700_000_000,
            'old_chat_member' => ['status' => 'left', 'user' => ['id' => 7, 'is_bot' => true]],
            'new_chat_member' => ['status' => $status, 'user' => ['id' => 7, 'is_bot' => true]],
        ]];
    }

    public function testParserReadsWhoAddedTheBotWhereAndHow(): void
    {
        $change = (new UpdateParser())->parseMembership(self::update(9, 'administrator'));

        self::assertInstanceOf(IncomingMembership::class, $change);
        self::assertSame(['-1001', 'Дом', 'supergroup', '42', 'Ann', 'administrator', 'left'], [
            $change->chatId, $change->chatTitle, $change->chatType, $change->userId, $change->displayName, $change->status, $change->oldStatus,
        ]);
        self::assertTrue($change->isMember());
        self::assertFalse((new UpdateParser())->parseMembership(self::update(10, 'kicked'))?->isMember());
        self::assertNull((new UpdateParser())->parseMembership(['update_id' => 11, 'message' => []]));
        self::assertNull((new UpdateParser())->parseMembership(['update_id' => 12, 'my_chat_member' => ['chat' => 'x']]));
    }

    public function testChangesGoToTheirHandlerOutsideTheChatQueue(): void
    {
        $memberships = new class implements MembershipHandler {
            /** @var list<string> */
            public array $seen = [];

            public function handleMembership(IncomingMembership $change): void
            {
                $this->seen[] = $change->status;
            }
        };
        $dispatcher = new ChatDispatcher(new RecordingHandler(), memberships: $memberships);
        $processor = new WebhookProcessor('secret-1234567890', new UpdateParser(), $dispatcher);

        $processor->process('secret-1234567890', (string) json_encode(self::update(1, 'member')));
        $processor->process('secret-1234567890', (string) json_encode(self::update(1, 'member')));
        $processor->process('secret-1234567890', (string) json_encode(self::update(2, 'left')));
        delay(0.05);

        self::assertSame(['member', 'left'], $memberships->seen, 'a repeated update is dropped');
    }
}
