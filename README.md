# mercurius-messenger

**English** | [Русский](README.ru.md)

A unified messaging channel interface with a normalized message model, and a Telegram Bot API adapter (webhook and long polling). New channels — WebSocket or REST API, Slack, Bitrix24 — are added as adapters.

Part of [Mercurius](https://github.com/opencck/mercurius) — an autonomous personal agent. The package does not depend on the Mercurius application and can be used on its own.

**Status:** early development.

**Stack:** PHP 8.2+, AMPHP v3, `amphp/http-client` 5.

## Installation

The package is not on Packagist yet; install it from GitHub:

```bash
composer config repositories.mercurius-messenger vcs https://github.com/opencck/mercurius-messenger
composer require opencck/mercurius-messenger:dev-master
```

Requires the `json` and `mbstring` extensions.

## Usage

### Long polling

```php
use Amp\Http\Client\HttpClientBuilder;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\MessageHandler;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\Telegram\LongPolling;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\TelegramChannel;
use Mercurius\Messenger\Telegram\UpdateParser;

$api = new TelegramApi(getenv('TELEGRAM_BOT_TOKEN'), HttpClientBuilder::buildDefault());
$channel = new TelegramChannel($api);

$handler = new class ($channel) implements MessageHandler {
    public function __construct(private TelegramChannel $channel) {}

    public function handle(IncomingMessage $message): void
    {
        $this->channel->typing($message->chatId);
        $result = $this->channel->send(new OutgoingMessage($message->chatId, 'You said: ' . $message->text));
        if (!$result->success) {
            // $result->errorKind, $result->retryAfter, $result->error
        }
    }
};

$me = $api->getMe();
$polling = new LongPolling($api, new UpdateParser(botId: $me['id']), $handler);
$polling->run(); // until $polling->stop() or a cancellation passed to run()
```

### Webhook

The HTTP server is the application's; the package processes the request body:

```php
use Mercurius\Messenger\Telegram\WebhookProcessor;
use Mercurius\Messenger\Telegram\WebhookRequestValidator;

$api->setWebhook('https://example.com/telegram', $secret, ['message']);
$webhook = new WebhookProcessor($secret, new UpdateParser(), $handler);

// inside the request handler:
$accepted = $webhook->process($request->getHeader(WebhookRequestValidator::SECRET_HEADER), $body);
// false: answer 403; true: answer 200 (handlers run after the response)
```

### Streaming a reply

```php
$first = $channel->send(new OutgoingMessage($chatId, 'Thinking…'));
foreach ($deltas as $textSoFar) {
    $channel->edit($chatId, $first->messageId, $textSoFar); // edits within 1 s are coalesced
}
```

### WebSocket channel

`Websocket\WebsocketChannel` is a second channel: JSON frames over WebSocket, one private chat per client
(`ws:<name>`). The package does not depend on a WebSocket library: the application authenticates the client, wraps
its connection in `Websocket\Connection`, attaches it to the chat and passes received frames to `receive()`.
What is sent while no client of the chat is connected waits in a bounded outbox.

```php
$ws = new WebsocketChannel();
$channel = new RoutingChannel($telegram, [WebsocketChannel::PREFIX => $ws]); // ws:… chats go to the socket

$key = $ws->attach('ws:p1', $connection);
$incoming = $ws->receive('ws:p1', 'p1', 'Owner', $frame); // IncomingMessage or IncomingCallback
$ws->detach('ws:p1', $key);
```

Frames from the client: `{"type":"message","text":"…","reply_to":"m3","files":[{"name":"a.txt","mime":"text/plain","content_base64":"…"}]}`
and `{"type":"callback","data":"…","message_id":"m3"}`. To the client: `message` (id, text, parse_mode, buttons,
reply_to), `edit`, `delete`, `answer`, `file`, `location`, `contact`, `poll`, `typing` and `ack` with the id of the
client's message.

## Main interfaces

| Type | Purpose |
|------|---------|
| `Channel` | `name()`, `send(OutgoingMessage): SendResult`, `edit(chatId, messageId, text, ParseMode = Plain, buttons = []): SendResult` (the message gets exactly these buttons), `answerCallback(callbackId, text, alert)`, `sendFile(chatId, fileName, content, caption, threadId): SendResult`, `sendMedia(chatId, MediaKind, fileName, content, caption, threadId)` (document, photo, audio, video, voice, animation), `download(fileId, maxBytes): ?string` (an incoming file; null when larger or not downloaded), `delete(chatId, messageId)`, `leave(chatId)`; `typing(chatId)`, `capabilities(): Capabilities` (`canEdit`, `maxMessageLength`, `supportsButtons`) |
| `ForumChannel` (optional) | forum topics: `createTopic(chatId, name): ?string` (thread id), `editTopic`, `closeTopic`, `reopenTopic`, `deleteTopic` — best effort, false or null on failure |
| `PollChannel`, `ContactChannel`, `LocationChannel` (optional) | `sendPoll(chatId, question, options, anonymous, multiple, threadId)`, `sendContact(chatId, phone, firstName, lastName, threadId)`, `sendLocation(chatId, latitude, longitude, threadId)`; check with `instanceof`. `RoutingChannel` offers all of them and gives the chat of a channel without one a failure |
| `IncomingMessage` | immutable normalized message: channel, chatId, userId, userName, displayName, text, messageId, replyToMessageId, isPrivateChat, receivedAt, updateId (`dedupeKey()`), threadId, isEdited, raw, attachments (`Attachment`), repliedAttachments (files of the message it replies to) |
| `Attachment`, `AttachmentKind` | a file that came with a message: kind (`voice`, `audio`, `video_note`, `photo`, `video`, `animation`, `document`), fileId for `Channel::download`, fileName, mimeType, size, duration, width, height, forwarded; a photo is its largest size, stickers are skipped |
| `OutgoingMessage` | chatId, text, replyToMessageId, `ParseMode` (`plain`, `markdown`, `html`, `markdown_v2`), threadId, disableLinkPreview, buttons (rows of `Button`) |
| `Button` | inline button: text and up to 64 bytes of callback data |
| `IncomingCallback`, `CallbackHandler` | a button press and its handler; presses bypass the chat queue (`ChatDispatcher::dispatchCallback`) |
| `IncomingPost`, `PostHandler` | a post of a channel where the bot is an admin, or its edit (`channel_post`, `edited_channel_post`); bypasses the chat queue (`ChatDispatcher::dispatchPost`) |
| `IncomingMembership`, `MembershipHandler` | the bot was added to a chat or removed from it (`my_chat_member`), with who did it and its admin rights (`rights`: `can_post_messages`…); also bypasses the chat queue (`ChatDispatcher::dispatchMembership`). Long polling asks for it by default; pass `my_chat_member` to `setWebhook` too |
| `SendResult` | `ok()` / `failed()`: success, messageId, continuationMessageIds (split text), `SendErrorKind` (`too_long`, `bad_format`, `forbidden`, `not_found`, `rate_limited`, `transient`, `unknown`), retryAfter, error |
| `MessageHandler` | `handle(IncomingMessage): void` — implemented by the application |
| `ChatDispatcher` | runs the handler: chats concurrently, one chat in order; bounded queue per chat; with `splitDelay` (seconds) a text the client split at the limit is glued back: a part of `splitThreshold` (4000) characters or more waits for the next one from the same person, chat and topic |
| `Deduplicator` | bounded LRU of recent update ids; with `firstSeen` (a closure over a store, e.g. a database table) an update redelivered after a restart is dropped too, and a failing store lets updates through |
| `TextSplitter` | splits text by paragraphs and lines, keeps ``` code blocks; UTF-16 length |
| `Telegram\MarkdownToTelegramHtml` | converts the common Markdown of language models (`ParseMode::Markdown`) to Telegram HTML; safe on unfinished streamed text |
| `Telegram\TelegramApi` | thin async Bot API client; errors become `TelegramException` with a `SendErrorKind`; the token never appears in errors; works with a local `telegram-bot-api` (`baseUrl`): in `--local` mode `getFile` gives a path on the server's disk, read by the `localFile` closure the application passes |
| `Telegram\TelegramChannel` | `Channel` for Telegram: splitting at 4096 UTF-16 units, plain-text retry when formatting is rejected, one send per second per chat, local flood window after a 429, unchanged edits are no-ops |
| `Telegram\UpdateParser` | Telegram `Update` → `?IncomingMessage` (text or caption, attachments; ignores bots, channel posts, edits unless enabled); `parseCallback()` → `?IncomingCallback`; `parseMembership()` → `?IncomingMembership`; `parsePost()` → `?IncomingPost` |
| `Telegram\LongPolling` | `getUpdates` loop with offset tracking, deduplication, back-off and `stop()` |
| `Telegram\WebhookProcessor`, `Telegram\WebhookRequestValidator` | webhook body processing and constant-time secret check |

## Checks

```bash
composer install
composer check             # unit tests, PHPStan (max), Psalm (level 1)
composer test-integration  # calls the real Bot API; needs TELEGRAM_BOT_TOKEN
```

## License

[MIT](LICENSE)
