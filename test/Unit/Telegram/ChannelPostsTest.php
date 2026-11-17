<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\ChatDispatcher;
use Mercurius\Messenger\IncomingPost;
use Mercurius\Messenger\PostHandler;
use Mercurius\Messenger\Telegram\UpdateParser;
use Mercurius\Messenger\Telegram\WebhookProcessor;
use Mercurius\Messenger\Test\Support\RecordingHandler;

use function Amp\delay;

/**
 * Posts of a channel where the bot is an admin, and the bot's rights there.
 */
final class ChannelPostsTest extends AsyncTestCase
{
    /** @return array<string, mixed> */
    private static function post(int $update, string $key = 'channel_post', string $text = 'Новый пост'): array
    {
        return ['update_id' => $update, $key => [
            'message_id' => 31, 'date' => 1_700_000_000, 'text' => $text,
            'chat' => ['id' => -1002, 'title' => 'Блог', 'type' => 'channel'],
        ]];
    }

    public function testPostsAndTheirEditsAreParsed(): void
    {
        $parser = new UpdateParser();
        $post = $parser->parsePost(self::post(1));
        $edited = $parser->parsePost(self::post(2, 'edited_channel_post', 'Исправлено'));

        self::assertInstanceOf(IncomingPost::class, $post);
        self::assertSame(['-1002', 'Блог', '31', 'Новый пост', false], [$post->chatId, $post->chatTitle, $post->messageId, $post->text, $post->isEdited]);
        self::assertSame(['Исправлено', true], [$edited?->text, $edited?->isEdited]);
        self::assertNull($parser->parse(self::post(3)), 'a post is not a chat message');
        self::assertNull($parser->parsePost(['update_id' => 4, 'channel_post' => ['chat' => ['id' => -1002]]]));
    }

    public function testRightsOfAnAdminComeWithTheMembership(): void
    {
        $change = (new UpdateParser())->parseMembership(['update_id' => 5, 'my_chat_member' => [
            'chat' => ['id' => -1002, 'title' => 'Блог', 'type' => 'channel'],
            'from' => ['id' => 42, 'first_name' => 'Ann'],
            'new_chat_member' => ['status' => 'administrator', 'can_post_messages' => true, 'can_edit_messages' => true, 'can_delete_messages' => false, 'user' => ['id' => 7]],
        ]]);

        self::assertSame(['can_post_messages' => true, 'can_edit_messages' => true, 'can_delete_messages' => false], $change?->rights);
    }

    public function testPostsGoToTheirHandler(): void
    {
        $posts = new class implements PostHandler {
            /** @var list<string> */
            public array $seen = [];

            public function handlePost(IncomingPost $post): void
            {
                $this->seen[] = $post->text;
            }
        };
        $processor = new WebhookProcessor('secret-1234567890', new UpdateParser(), new ChatDispatcher(new RecordingHandler(), posts: $posts));

        $processor->process('secret-1234567890', (string) json_encode(self::post(1)));
        $processor->process('secret-1234567890', (string) json_encode(self::post(1)));
        delay(0.05);

        self::assertSame(['Новый пост'], $posts->seen);
    }
}
