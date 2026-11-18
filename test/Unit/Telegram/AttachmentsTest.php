<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\AttachmentKind;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\TelegramChannel;
use Mercurius\Messenger\Telegram\UpdateParser;
use Mercurius\Messenger\Test\Support\FakeTelegramServer;

/**
 * Attachments of incoming messages: what came, its file and its size; the file is downloaded on demand, up to a limit.
 */
final class AttachmentsTest extends AsyncTestCase
{
    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function update(array $override): array
    {
        return ['update_id' => 900, 'message' => \array_merge([
            'message_id' => 7,
            'date' => 1_763_280_000,
            'chat' => ['id' => 1001, 'type' => 'private'],
            'from' => ['id' => 1001, 'is_bot' => false, 'first_name' => 'Ann'],
        ], $override)];
    }

    public function testAPhotoWithoutCaptionCarriesTheLargestSize(): void
    {
        $msg = (new UpdateParser())->parse(self::update(['photo' => [
            ['file_id' => 'small', 'file_unique_id' => 's', 'width' => 90, 'height' => 60, 'file_size' => 1000],
            ['file_id' => 'big', 'file_unique_id' => 'b', 'width' => 1280, 'height' => 853, 'file_size' => 120_000],
        ]]));

        self::assertNotNull($msg);
        self::assertSame('', $msg->text);
        self::assertCount(1, $msg->attachments);
        $photo = $msg->attachments[0];
        self::assertSame([AttachmentKind::Photo, 'big', 'photo-7.jpg', 'image/jpeg', 120_000, 1280, 853, false], [$photo->kind, $photo->fileId, $photo->fileName, $photo->mimeType, $photo->size, $photo->width, $photo->height, $photo->forwarded]);
    }

    public function testVoiceAudioVideoNoteVideoAnimationAndDocument(): void
    {
        $parser = new UpdateParser();
        $cases = [
            ['voice', ['file_id' => 'v', 'duration' => 4, 'mime_type' => 'audio/ogg', 'file_size' => 9000], AttachmentKind::Voice, 'voice-7.ogg'],
            ['audio', ['file_id' => 'a', 'duration' => 200, 'mime_type' => 'audio/mpeg', 'file_name' => 'song.mp3'], AttachmentKind::Audio, 'song.mp3'],
            ['video_note', ['file_id' => 'n', 'duration' => 9, 'length' => 240], AttachmentKind::VideoNote, 'video_note-7.mp4'],
            ['video', ['file_id' => 'w', 'duration' => 30, 'width' => 640, 'height' => 360, 'mime_type' => 'video/mp4'], AttachmentKind::Video, 'video-7.mp4'],
            ['animation', ['file_id' => 'g', 'duration' => 2, 'file_name' => 'fun.mp4'], AttachmentKind::Animation, 'fun.mp4'],
            ['document', ['file_id' => 'd', 'file_name' => 'report.docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'file_size' => 20_000], AttachmentKind::Document, 'report.docx'],
        ];
        foreach ($cases as [$field, $data, $kind, $name]) {
            $override = [$field => $data, 'caption' => 'смотри'];
            if ($field === 'animation') {
                // Telegram sends an animation with a document field too: the animation wins
                $override['document'] = $data;
            }
            $msg = $parser->parse(self::update($override));
            self::assertNotNull($msg, $field);
            self::assertSame('смотри', $msg->text, $field);
            self::assertCount(1, $msg->attachments, $field);
            self::assertSame([$kind, $name], [$msg->attachments[0]->kind, $msg->attachments[0]->fileName], $field);
        }
        $voice = $parser->parse(self::update(['voice' => $cases[0][1]]));
        self::assertSame(4, $voice?->attachments[0]->duration);
    }

    public function testAForwardedFileIsMarked(): void
    {
        $msg = (new UpdateParser())->parse(self::update([
            'voice' => ['file_id' => 'v', 'duration' => 3],
            'forward_origin' => ['type' => 'user', 'date' => 1, 'sender_user' => ['id' => 5, 'is_bot' => false, 'first_name' => 'Bob']],
        ]));

        self::assertTrue($msg?->attachments[0]->forwarded);
    }

    public function testAStickerIsSkipped(): void
    {
        $msg = (new UpdateParser())->parse(self::update(['sticker' => ['file_id' => 's', 'width' => 512, 'height' => 512]]));

        self::assertNotNull($msg);
        self::assertSame([], $msg->attachments);
        self::assertSame('', $msg->text);
    }

    public function testDownloadAsksForThePathThenTheFile(): void
    {
        $server = new FakeTelegramServer();
        $server->respondOk('getFile', ['file_id' => 'big', 'file_size' => 7, 'file_path' => 'photos/file_1.jpg']);
        $server->respond('file_1.jpg', 200, 'JPEGBIN');
        $channel = new TelegramChannel(new TelegramApi(FakeTelegramServer::TOKEN, $server->client()), 0.0);

        self::assertSame('JPEGBIN', $channel->download('big', 1000));
        self::assertSame(['file_id' => 'big'], $server->params('getFile')[0] ?? null);
    }

    public function testAFileOverTheLimitIsNotDownloaded(): void
    {
        $server = new FakeTelegramServer();
        $server->respondOk('getFile', ['file_id' => 'big', 'file_size' => 5000, 'file_path' => 'photos/file_2.jpg']);
        $server->respondOk('getFile', ['file_id' => 'lie', 'file_size' => 3, 'file_path' => 'docs/file_3.bin']);
        $server->respond('file_3.bin', 200, 'longer than said');
        $channel = new TelegramChannel(new TelegramApi(FakeTelegramServer::TOKEN, $server->client()), 0.0);

        self::assertNull($channel->download('big', 1000), 'the size from getFile is over the limit');
        self::assertSame([], $server->params('file_2.jpg'), 'and the file is not asked for');
        self::assertNull($channel->download('lie', 10), 'the body itself is over the limit');
    }

    public function testALocalServerGivesAPathOnItsDiskReadByTheApplication(): void
    {
        $server = new FakeTelegramServer();
        $server->respondOk('getFile', ['file_id' => 'big', 'file_size' => 9, 'file_path' => '/var/lib/telegram-bot-api/123:AAF-x_y/videos/file_9.mp4']);
        $server->respondOk('getFile', ['file_id' => 'big', 'file_size' => 9, 'file_path' => '/var/lib/telegram-bot-api/123:AAF-x_y/videos/file_9.mp4']);
        $read = [];
        $local = static function (string $path, int $maxBytes) use (&$read): string {
            $read[] = [$path, $maxBytes];
            return 'MP4BYTES!';
        };
        $withDisk = new TelegramChannel(new TelegramApi(FakeTelegramServer::TOKEN, $server->client(), 'http://telegram-api:8081', localFile: $local), 0.0);
        $without = new TelegramChannel(new TelegramApi(FakeTelegramServer::TOKEN, $server->client(), 'http://telegram-api:8081'), 0.0);

        self::assertSame('MP4BYTES!', $withDisk->download('big', 100));
        self::assertSame([['/var/lib/telegram-bot-api/123:AAF-x_y/videos/file_9.mp4', 100]], $read);
        self::assertNull($without->download('big', 100), 'a path on a disk the application cannot read');
    }

    public function testAReplyBringsTheFilesOfTheMessageItAnswers(): void
    {
        $msg = (new UpdateParser())->parse(self::update([
            'text' => 'что на фото?',
            'reply_to_message' => [
                'message_id' => 5,
                'date' => 1_763_279_000,
                'chat' => ['id' => 1001, 'type' => 'private'],
                'from' => ['id' => 1001, 'is_bot' => false, 'first_name' => 'Ann'],
                'photo' => [['file_id' => 'old', 'file_unique_id' => 'o', 'width' => 640, 'height' => 480, 'file_size' => 5000]],
            ],
        ]));

        self::assertNotNull($msg);
        self::assertSame([], $msg->attachments);
        self::assertCount(1, $msg->repliedAttachments);
        self::assertSame(['old', 'photo-5.jpg'], [$msg->repliedAttachments[0]->fileId, $msg->repliedAttachments[0]->fileName]);
    }
}
