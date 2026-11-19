<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Mercurius\Messenger\Telegram\UpdateParser;
use PHPUnit\Framework\TestCase;

final class UpdateParserTest extends TestCase
{
    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function message(array $override = []): array
    {
        return \array_merge([
            'message_id' => 42,
            'date' => 1_763_280_000,
            'chat' => ['id' => 1001, 'type' => 'private', 'first_name' => 'Ann'],
            'from' => ['id' => 1001, 'is_bot' => false, 'first_name' => 'Ann', 'last_name' => 'Lee', 'username' => 'ann'],
            'text' => 'Привет',
        ], $override);
    }

    public function testParsesPrivateTextMessage(): void
    {
        $msg = (new UpdateParser())->parse(['update_id' => 777, 'message' => self::message()]);

        self::assertNotNull($msg);
        self::assertSame('telegram', $msg->channel);
        self::assertSame('1001', $msg->chatId);
        self::assertSame('1001', $msg->userId);
        self::assertSame('ann', $msg->userName);
        self::assertSame('Ann Lee', $msg->displayName);
        self::assertSame('Привет', $msg->text);
        self::assertSame('42', $msg->messageId);
        self::assertNull($msg->replyToMessageId);
        self::assertTrue($msg->isPrivateChat);
        self::assertSame(1_763_280_000, $msg->receivedAt->getTimestamp());
        self::assertSame('777', $msg->updateId);
        self::assertSame('telegram:777', $msg->dedupeKey());
        self::assertFalse($msg->isEdited);
    }

    public function testParsesGroupMessageWithReplyAndThread(): void
    {
        $msg = (new UpdateParser())->parse(['update_id' => 1, 'message' => self::message([
            'chat' => ['id' => -100_123, 'type' => 'supergroup', 'title' => 'Team'],
            'from' => ['id' => 5, 'is_bot' => false, 'first_name' => 'Bob'],
            'reply_to_message' => ['message_id' => 40],
            'message_thread_id' => 3,
        ])]);

        self::assertNotNull($msg);
        self::assertSame('-100123', $msg->chatId);
        self::assertFalse($msg->isPrivateChat);
        self::assertNull($msg->userName);
        self::assertSame('Bob', $msg->displayName);
        self::assertSame('40', $msg->replyToMessageId);
        self::assertSame('3', $msg->threadId);
    }

    public function testUsesCaptionWhenThereIsNoText(): void
    {
        $data = self::message(['caption' => 'фото дня', 'photo' => [['file_id' => 'x']]]);
        unset($data['text']);
        $msg = (new UpdateParser())->parse(['update_id' => 2, 'message' => $data]);

        self::assertNotNull($msg);
        self::assertSame('фото дня', $msg->text);
        self::assertArrayHasKey('photo', $msg->raw);
    }

    public function testMissingTextGivesEmptyString(): void
    {
        $data = self::message(['sticker' => ['file_id' => 'y']]);
        unset($data['text']);
        $msg = (new UpdateParser())->parse(['update_id' => 3, 'message' => $data]);

        self::assertNotNull($msg);
        self::assertSame('', $msg->text);
    }

    public function testIgnoresBotSenders(): void
    {
        $parser = new UpdateParser();
        self::assertNull($parser->parse(['update_id' => 4, 'message' => self::message([
            'from' => ['id' => 99, 'is_bot' => true, 'first_name' => 'OtherBot'],
        ])]));

        // The bot's own id is ignored even if is_bot were missing.
        $own = new UpdateParser(botId: 500);
        self::assertNull($own->parse(['update_id' => 5, 'message' => self::message([
            'from' => ['id' => 500, 'first_name' => 'Me'],
        ])]));
    }

    public function testABotOfAGroupComesFlaggedWhenAllowed(): void
    {
        $parser = new UpdateParser(botId: 500, allowBots: true);
        $group = ['chat' => ['id' => -100, 'type' => 'supergroup', 'title' => 'Дом']];

        $message = $parser->parse(['update_id' => 7, 'message' => self::message($group + ['from' => ['id' => 99, 'is_bot' => true, 'first_name' => 'OtherBot', 'username' => 'other_bot']])]);
        self::assertNotNull($message);
        self::assertTrue($message->fromBot);
        self::assertSame('other_bot', $message->userName);

        self::assertNull($parser->parse(['update_id' => 8, 'message' => self::message(['from' => ['id' => 99, 'is_bot' => true, 'first_name' => 'OtherBot']])]), 'never in a private chat');
        self::assertNull($parser->parse(['update_id' => 9, 'message' => self::message($group + ['from' => ['id' => 500, 'is_bot' => true, 'first_name' => 'Me']])]), 'never its own');
        $person = $parser->parse(['update_id' => 10, 'message' => self::message($group)]);
        self::assertNotNull($person);
        self::assertFalse($person->fromBot, 'a person is not a bot');
    }

    public function testEditedMessagesAreIgnoredOrFlagged(): void
    {
        $update = ['update_id' => 6, 'edited_message' => self::message(['text' => 'fixed'])];

        self::assertNull((new UpdateParser())->parse($update));

        $msg = (new UpdateParser(includeEdited: true))->parse($update);
        self::assertNotNull($msg);
        self::assertTrue($msg->isEdited);
        self::assertSame('fixed', $msg->text);
    }

    public function testIgnoresChannelPostsAndOtherUpdates(): void
    {
        $parser = new UpdateParser();
        self::assertNull($parser->parse(['update_id' => 7, 'channel_post' => self::message()]));
        self::assertNull($parser->parse(['update_id' => 8, 'callback_query' => ['id' => 'q']]));
    }

    public function testRejectsMalformedUpdates(): void
    {
        $parser = new UpdateParser();
        self::assertNull($parser->parse(['message' => self::message()]), 'no update_id');
        self::assertNull($parser->parse(['update_id' => 'x', 'message' => self::message()]));
        self::assertNull($parser->parse(['update_id' => 9, 'message' => 'oops']));
        self::assertNull($parser->parse(['update_id' => 10, 'message' => self::message(['chat' => []])]));

        $noSender = self::message();
        unset($noSender['from']);
        self::assertNull($parser->parse(['update_id' => 11, 'message' => $noSender]));
    }

    public function testUpdateIdHelper(): void
    {
        self::assertSame(12, UpdateParser::updateId(['update_id' => 12]));
        self::assertNull(UpdateParser::updateId(['update_id' => '12']));
    }
}
