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

## Main interfaces

| Type | Purpose |
|------|---------|
| `Channel` | `name()`, `send(OutgoingMessage): SendResult`, `edit(chatId, messageId, text, ParseMode = Plain, buttons = []): SendResult` (the message gets exactly these buttons), `answerCallback(callbackId, text, alert)`, `sendFile(chatId, fileName, content, caption): SendResult`, `delete(chatId, messageId)`, `leave(chatId)`, forum topics: `createTopic(chatId, name): ?string` (thread id), `editTopic`, `closeTopic`, `reopenTopic`, `deleteTopic` — best effort, false or null on failure; `typing(chatId)`, `capabilities(): Capabilities` |
| `IncomingMessage` | immutable normalized message: channel, chatId, userId, userName, displayName, text, messageId, replyToMessageId, isPrivateChat, receivedAt, updateId (`dedupeKey()`), threadId, isEdited, raw |
| `OutgoingMessage` | chatId, text, replyToMessageId, `ParseMode` (`plain`, `markdown`, `html`, `markdown_v2`), threadId, disableLinkPreview, buttons (rows of `Button`) |
| `Button` | inline button: text and up to 64 bytes of callback data |
| `IncomingCallback`, `CallbackHandler` | a button press and its handler; presses bypass the chat queue (`ChatDispatcher::dispatchCallback`) |
| `IncomingMembership`, `MembershipHandler` | the bot was added to a chat or removed from it (`my_chat_member`), with who did it; also bypasses the chat queue (`ChatDispatcher::dispatchMembership`). Long polling asks for it by default; pass `my_chat_member` to `setWebhook` too |
| `SendResult` | `ok()` / `failed()`: success, messageId, continuationMessageIds (split text), `SendErrorKind` (`too_long`, `bad_format`, `forbidden`, `not_found`, `rate_limited`, `transient`, `unknown`), retryAfter, error |
| `MessageHandler` | `handle(IncomingMessage): void` — implemented by the application |
| `ChatDispatcher` | runs the handler: chats concurrently, one chat in order; bounded queue per chat |
| `Deduplicator` | bounded LRU of recent update ids |
| `TextSplitter` | splits text by paragraphs and lines, keeps ``` code blocks; UTF-16 length |
| `Telegram\MarkdownToTelegramHtml` | converts the common Markdown of language models (`ParseMode::Markdown`) to Telegram HTML; safe on unfinished streamed text |
| `Telegram\TelegramApi` | thin async Bot API client; errors become `TelegramException` with a `SendErrorKind`; the token never appears in errors |
| `Telegram\TelegramChannel` | `Channel` for Telegram: splitting at 4096 UTF-16 units, plain-text retry when formatting is rejected, one send per second per chat, local flood window after a 429, unchanged edits are no-ops |
| `Telegram\UpdateParser` | Telegram `Update` → `?IncomingMessage` (text or caption; ignores bots, channel posts, edits unless enabled); `parseCallback()` → `?IncomingCallback`; `parseMembership()` → `?IncomingMembership` |
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
