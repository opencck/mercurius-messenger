# mercurius-messenger

[English](README.md) | **Русский**

Единый интерфейс канала связи с нормализованной моделью сообщения и адаптер Telegram Bot API (webhook и long polling). Новые каналы — WebSocket или REST API, Slack, Bitrix24 — добавляются адаптерами.

Часть проекта [Меркурий (Mercurius)](https://github.com/opencck/mercurius) — автономного персонального агента. Пакет не зависит от приложения Меркурия и может использоваться отдельно.

**Статус:** ранняя разработка.

**Стек:** PHP 8.2+, AMPHP v3, `amphp/http-client` 5.

## Установка

Пакета пока нет на Packagist, он ставится из GitHub:

```bash
composer config repositories.mercurius-messenger vcs https://github.com/opencck/mercurius-messenger
composer require opencck/mercurius-messenger:dev-master
```

Нужны расширения `json` и `mbstring`.

## Использование

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
        $result = $this->channel->send(new OutgoingMessage($message->chatId, 'Вы написали: ' . $message->text));
        if (!$result->success) {
            // $result->errorKind, $result->retryAfter, $result->error
        }
    }
};

$me = $api->getMe();
$polling = new LongPolling($api, new UpdateParser(botId: $me['id']), $handler);
$polling->run(); // до $polling->stop() или отмены, переданной в run()
```

### Webhook

HTTP-сервер — забота приложения; пакет обрабатывает тело запроса:

```php
use Mercurius\Messenger\Telegram\WebhookProcessor;
use Mercurius\Messenger\Telegram\WebhookRequestValidator;

$api->setWebhook('https://example.com/telegram', $secret, ['message']);
$webhook = new WebhookProcessor($secret, new UpdateParser(), $handler);

// в обработчике запроса:
$accepted = $webhook->process($request->getHeader(WebhookRequestValidator::SECRET_HEADER), $body);
// false — ответить 403; true — ответить 200 (обработчики работают уже после ответа)
```

### Ответ по мере генерации

```php
$first = $channel->send(new OutgoingMessage($chatId, 'Думаю…'));
foreach ($deltas as $textSoFar) {
    $channel->edit($chatId, $first->messageId, $textSoFar); // правки чаще раза в секунду склеиваются
}
```

## Основные интерфейсы

| Тип | Назначение |
|-----|------------|
| `Channel` | `name()`, `send(OutgoingMessage): SendResult`, `edit(chatId, messageId, text, ParseMode = Plain, buttons = []): SendResult` (у сообщения остаются ровно эти кнопки), `answerCallback(callbackId, text, alert)`, `sendFile(chatId, fileName, content, caption, threadId): SendResult`, `sendMedia(chatId, MediaKind, fileName, content, caption, threadId)` (документ, фото, аудио, видео, голосовое, анимация), `sendLocation(chatId, latitude, longitude, threadId)`, `sendContact(chatId, phone, firstName, lastName, threadId)`, `sendPoll(chatId, question, options, anonymous, multiple, threadId)`, `download(fileId, maxBytes): ?string` (входящий файл; null — больше предела или не скачался), `delete(chatId, messageId)`, `leave(chatId)`, темы форума: `createTopic(chatId, name): ?string` (номер темы), `editTopic`, `closeTopic`, `reopenTopic`, `deleteTopic` — без исключений, при неудаче false или null; `typing(chatId)`, `capabilities(): Capabilities` |
| `IncomingMessage` | неизменяемое нормализованное сообщение: channel, chatId, userId, userName, displayName, text, messageId, replyToMessageId, isPrivateChat, receivedAt, updateId (`dedupeKey()`), threadId, isEdited, raw, attachments (`Attachment`), repliedAttachments (файлы сообщения, на которое это ответ) |
| `Attachment`, `AttachmentKind` | файл, пришедший с сообщением: вид (`voice`, `audio`, `video_note`, `photo`, `video`, `animation`, `document`), fileId для `Channel::download`, fileName, mimeType, size, duration, width, height, forwarded; фото — самый большой размер, стикеры пропускаются |
| `OutgoingMessage` | chatId, text, replyToMessageId, `ParseMode` (`plain`, `markdown`, `html`, `markdown_v2`), threadId, disableLinkPreview, buttons (ряды `Button`) |
| `Button` | кнопка под сообщением: текст и до 64 байт данных нажатия |
| `IncomingCallback`, `CallbackHandler` | нажатие кнопки и его обработчик; нажатия идут в обход очереди чата (`ChatDispatcher::dispatchCallback`) |
| `IncomingPost`, `PostHandler` | пост канала, где бот — администратор, или его правка (`channel_post`, `edited_channel_post`); в обход очереди чата (`ChatDispatcher::dispatchPost`) |
| `IncomingMembership`, `MembershipHandler` | бота добавили в чат или убрали из него (`my_chat_member`), кто это сделал и права администратора (`rights`: `can_post_messages`…); тоже в обход очереди чата (`ChatDispatcher::dispatchMembership`). Long polling запрашивает это по умолчанию; в `setWebhook` передайте `my_chat_member` сами |
| `SendResult` | `ok()` / `failed()`: success, messageId, continuationMessageIds (текст разбит), `SendErrorKind` (`too_long`, `bad_format`, `forbidden`, `not_found`, `rate_limited`, `transient`, `unknown`), retryAfter, error |
| `MessageHandler` | `handle(IncomingMessage): void` — реализует приложение |
| `ChatDispatcher` | вызывает обработчик: чаты параллельно, один чат по порядку; очередь чата ограничена |
| `Deduplicator` | ограниченный LRU последних id обновлений |
| `TextSplitter` | режет текст по абзацам и строкам, сохраняет блоки кода ```; длина в UTF-16 |
| `Telegram\MarkdownToTelegramHtml` | переводит обычный Markdown языковых моделей (`ParseMode::Markdown`) в HTML Telegram; работает и с недописанным текстом потока |
| `Telegram\TelegramApi` | тонкий асинхронный клиент Bot API; ошибки — `TelegramException` с `SendErrorKind`; токен не попадает в ошибки; работает с локальным `telegram-bot-api` (`baseUrl`): в режиме `--local` `getFile` отдаёт путь на диске сервера, его читает замыкание `localFile`, которое передаёт приложение |
| `Telegram\TelegramChannel` | `Channel` для Telegram: разбиение по 4096 единиц UTF-16, повтор плоским текстом при отказе разметки, не чаще раза в секунду на чат, локальное окно flood после 429, правка без изменений — пустая операция |
| `Telegram\UpdateParser` | Telegram `Update` → `?IncomingMessage` (текст или подпись, вложения; боты, посты каналов и правки, если не включены, отбрасываются); `parseCallback()` → `?IncomingCallback`; `parseMembership()` → `?IncomingMembership`; `parsePost()` → `?IncomingPost` |
| `Telegram\LongPolling` | цикл `getUpdates` с учётом offset, дедупликацией, паузами при ошибках и `stop()` |
| `Telegram\WebhookProcessor`, `Telegram\WebhookRequestValidator` | обработка тела webhook и проверка секрета за постоянное время |

## Проверки

```bash
composer install
composer check             # юнит-тесты, PHPStan (max), Psalm (уровень 1)
composer test-integration  # вызывает настоящий Bot API; нужен TELEGRAM_BOT_TOKEN
```

## Лицензия

[MIT](LICENSE)
