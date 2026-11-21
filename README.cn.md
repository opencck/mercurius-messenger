# mercurius-messenger

[English](README.md) | [Русский](README.ru.md) | **中文**

带规范化消息模型的统一消息通道接口，以及 Telegram Bot API 适配器（webhook 和长轮询）。新的通道——WebSocket 或 REST API、Slack、Bitrix24——以适配器的形式添加。

[Mercurius](https://github.com/opencck/mercurius) 的一部分——一个自主的个人智能体。该包不依赖 Mercurius 应用，可以单独使用。

**状态：** 早期开发。

**技术栈：** PHP 8.2+、AMPHP v3、`amphp/http-client` 5。

## 安装

该包尚未发布到 Packagist；请从 GitHub 安装：

```bash
composer config repositories.mercurius-messenger vcs https://github.com/opencck/mercurius-messenger
composer require opencck/mercurius-messenger:dev-master
```

需要 `json` 和 `mbstring` 扩展。

## 用法

### 长轮询

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

HTTP 服务器由应用提供；该包处理请求体：

```php
use Mercurius\Messenger\Telegram\WebhookProcessor;
use Mercurius\Messenger\Telegram\WebhookRequestValidator;

$api->setWebhook('https://example.com/telegram', $secret, ['message']);
$webhook = new WebhookProcessor($secret, new UpdateParser(), $handler);

// inside the request handler:
$accepted = $webhook->process($request->getHeader(WebhookRequestValidator::SECRET_HEADER), $body);
// false: answer 403; true: answer 200 (handlers run after the response)
```

### 流式回复

```php
$first = $channel->send(new OutgoingMessage($chatId, 'Thinking…'));
foreach ($deltas as $textSoFar) {
    $channel->edit($chatId, $first->messageId, $textSoFar); // edits within 1 s are coalesced
}
```

### WebSocket 通道

`Websocket\WebsocketChannel` 是第二个通道：基于 WebSocket 的 JSON 帧，每个客户端一个私聊（`ws:<name>`）。该包不依赖任何 WebSocket 库：应用负责验证客户端身份，把它的连接包装成 `Websocket\Connection`，将其挂接到该聊天，并把收到的帧交给 `receive()`。该聊天没有任何客户端连接期间发送的内容，会在有界的发件箱中等待。

```php
$ws = new WebsocketChannel();
$channel = new RoutingChannel($telegram, [WebsocketChannel::PREFIX => $ws]); // ws:… chats go to the socket

$key = $ws->attach('ws:p1', $connection);
$incoming = $ws->receive('ws:p1', 'p1', 'Owner', $frame); // IncomingMessage or IncomingCallback
$ws->detach('ws:p1', $key);
```

来自客户端的帧：`{"type":"message","text":"…","reply_to":"m3","files":[{"name":"a.txt","mime":"text/plain","content_base64":"…"}]}`
和 `{"type":"callback","data":"…","message_id":"m3"}`。发往客户端的帧：`message`（id、text、parse_mode、buttons、reply_to）、`edit`、`delete`、`answer`、`file`、`location`、`contact`、`poll`、`typing`，以及带有客户端消息 id 的 `ack`。

## 主要接口

| 类型 | 用途 |
|------|---------|
| `Channel` | `name()`、`send(OutgoingMessage): SendResult`、`edit(chatId, messageId, text, ParseMode = Plain, buttons = []): SendResult`（消息恰好得到这些按钮）、`answerCallback(callbackId, text, alert)`、`sendFile(chatId, fileName, content, caption, threadId): SendResult`、`sendMedia(chatId, MediaKind, fileName, content, caption, threadId)`（document、photo、audio、video、voice、animation）、`download(fileId, maxBytes): ?string`（收到的文件；过大或未下载时为 null）、`delete(chatId, messageId)`、`leave(chatId)`；`typing(chatId)`、`capabilities(): Capabilities`（`canEdit`、`maxMessageLength`、`supportsButtons`） |
| `ForumChannel`（可选） | 论坛话题：`createTopic(chatId, name): ?string`（线程 id）、`editTopic`、`closeTopic`、`reopenTopic`、`deleteTopic`——尽力而为，失败时返回 false 或 null |
| `PollChannel`、`ContactChannel`、`LocationChannel`（可选） | `sendPoll(chatId, question, options, anonymous, multiple, threadId)`、`sendContact(chatId, phone, firstName, lastName, threadId)`、`sendLocation(chatId, latitude, longitude, threadId)`；用 `instanceof` 检查。`RoutingChannel` 提供所有这些接口，并对没有它们的通道的聊天返回失败 |
| `IncomingMessage` | 不可变的规范化消息：channel、chatId、userId、userName、displayName、text、messageId、replyToMessageId、isPrivateChat、receivedAt、updateId（`dedupeKey()`）、threadId、isEdited、raw、attachments（`Attachment`）、repliedAttachments（所回复消息的文件） |
| `Attachment`、`AttachmentKind` | 随消息而来的文件：kind（`voice`、`audio`、`video_note`、`photo`、`video`、`animation`、`document`）、供 `Channel::download` 使用的 fileId、fileName、mimeType、size、duration、width、height、forwarded；照片取其最大尺寸，贴纸被跳过 |
| `OutgoingMessage` | chatId、text、replyToMessageId、`ParseMode`（`plain`、`markdown`、`html`、`markdown_v2`）、threadId、disableLinkPreview、buttons（`Button` 的行） |
| `Button` | 内联按钮：文本和最多 64 字节的回调数据 |
| `IncomingCallback`、`CallbackHandler` | 一次按钮点击及其处理器；点击绕过聊天队列（`ChatDispatcher::dispatchCallback`） |
| `IncomingPost`、`PostHandler` | 机器人为管理员的频道中的一条帖子或其编辑（`channel_post`、`edited_channel_post`）；绕过聊天队列（`ChatDispatcher::dispatchPost`） |
| `IncomingMembership`、`MembershipHandler` | 机器人被加入某个聊天或被移出（`my_chat_member`），含操作者及其管理员权限（`rights`：`can_post_messages`…）；同样绕过聊天队列（`ChatDispatcher::dispatchMembership`）。长轮询默认会请求它；也要把 `my_chat_member` 传给 `setWebhook` |
| `SendResult` | `ok()` / `failed()`：success、messageId、continuationMessageIds（被拆分的文本）、`SendErrorKind`（`too_long`、`bad_format`、`forbidden`、`not_found`、`rate_limited`、`transient`、`unknown`）、retryAfter、error |
| `MessageHandler` | `handle(IncomingMessage): void`——由应用实现 |
| `ChatDispatcher` | 运行处理器：不同聊天并发，同一聊天按顺序；每个聊天有有界队列；使用 `splitDelay`（秒）时，客户端在限制处拆开的文本会被重新拼接：长度达到 `splitThreshold`（4000）个字符或更多的一部分，会等待同一个人、聊天和话题的下一部分 |
| `UpdateParser` | `allowBots: true` 让群组中其他机器人的消息通过，并标记为 `IncomingMessage::$fromBot`；机器人的私聊消息和机器人自己的消息始终被丢弃 |
| `Deduplicator` | 最近 update id 的有界 LRU；使用 `firstSeen`（基于存储的闭包，例如数据库表）时，重启后重新投递的 update 也会被丢弃，而存储出错时 update 会被放行 |
| `TextSplitter` | 按段落和行拆分文本，保留 ``` 代码块；按 UTF-16 长度计算 |
| `Telegram\MarkdownToTelegramHtml` | 把语言模型常用的 Markdown（`ParseMode::Markdown`）转换为 Telegram HTML；对未完成的流式文本也安全 |
| `Telegram\TelegramApi` | 轻量的异步 Bot API 客户端；错误变为带 `SendErrorKind` 的 `TelegramException`；令牌绝不会出现在错误中；可配合本地 `telegram-bot-api`（`baseUrl`）使用：在 `--local` 模式下，`getFile` 给出服务器磁盘上的路径，由应用传入的 `localFile` 闭包读取 |
| `Telegram\TelegramChannel` | Telegram 的 `Channel`：按 4096 个 UTF-16 单元拆分、格式被拒绝时以纯文本重试、每个聊天每秒一次发送、收到 429 后的本地洪水窗口、未改变的编辑不执行任何操作 |
| `Telegram\UpdateParser` | Telegram `Update` → `?IncomingMessage`（文本或说明文字、附件；忽略机器人、频道帖子，除非启用否则忽略编辑）；`parseCallback()` → `?IncomingCallback`；`parseMembership()` → `?IncomingMembership`；`parsePost()` → `?IncomingPost` |
| `Telegram\LongPolling` | 带偏移量跟踪、去重、退避和 `stop()` 的 `getUpdates` 循环 |
| `Telegram\WebhookProcessor`、`Telegram\WebhookRequestValidator` | webhook 请求体处理和常数时间的密钥检查 |

## 检查

```bash
composer install
composer check             # unit tests, PHPStan (max), Psalm (level 1)
composer test-integration  # calls the real Bot API; needs TELEGRAM_BOT_TOKEN
```

## 许可证

[MIT](LICENSE)
