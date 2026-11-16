<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Telegram;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Mercurius\Messenger\ParseMode;
use Mercurius\Messenger\SendErrorKind;
use SensitiveParameter;

/**
 * Thin asynchronous Telegram Bot API client.
 *
 * Every method throws `TelegramException` on an API or network error; `CancelledException` passes through.
 * The bot token is part of each request URL, so it is never put into exceptions: error texts are redacted and
 * the original HTTP exception is not chained.
 */
final class TelegramApi
{
    public const DEFAULT_BASE_URL = 'https://api.telegram.org';

    /** Extra time on top of the long-poll timeout before the HTTP request is considered hung. */
    private const POLL_GRACE_SECONDS = 15.0;

    /** A `getUpdates` batch of 100 updates is far below this; anything larger is not a Bot API response. */
    private const BODY_SIZE_LIMIT = 8 * 1024 * 1024;

    private readonly string $token;
    private readonly string $baseUrl;

    /**
     * @param string $token bot token from @BotFather
     * @param HttpClient $httpClient the application may route it through a proxy/connector
     * @param string $baseUrl Bot API server, e.g. a local `telegram-bot-api` instance
     * @param float $timeout timeout of an ordinary call, seconds
     */
    public function __construct(
        #[SensitiveParameter] string $token,
        private readonly HttpClient $httpClient,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 30.0,
    ) {
        if (\preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token) !== 1) {
            throw new \InvalidArgumentException('Invalid Telegram bot token format');
        }
        if (\preg_match('#^https?://[^\s/]+(/[^\s]*)?$#', $baseUrl) !== 1) {
            throw new \InvalidArgumentException('Invalid Bot API base URL');
        }
        $this->token = $token;
        $this->baseUrl = \rtrim($baseUrl, '/');
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'token' => '***'];
    }

    /**
     * @return array<array-key, mixed> the bot's `User` object (`id`, `username`, ...)
     */
    public function getMe(?Cancellation $cancellation = null): array
    {
        return self::expectArray('getMe', $this->call('getMe', [], $cancellation));
    }

    /**
     * @param list<string> $allowedUpdates
     */
    public function setWebhook(
        string $url,
        #[SensitiveParameter] string $secretToken,
        array $allowedUpdates = ['message'],
        bool $dropPendingUpdates = false,
        ?Cancellation $cancellation = null,
    ): void {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => $allowedUpdates,
            'drop_pending_updates' => $dropPendingUpdates,
        ], $cancellation);
    }

    public function deleteWebhook(bool $dropPendingUpdates = false, ?Cancellation $cancellation = null): void
    {
        $this->call('deleteWebhook', ['drop_pending_updates' => $dropPendingUpdates], $cancellation);
    }

    /**
     * Long-polls for updates.
     *
     * @param int $offset id of the first update to return (last seen `update_id` + 1)
     * @param int $timeout long-poll timeout, seconds
     * @param list<string> $allowedUpdates
     * @return list<array<array-key, mixed>> raw updates; non-array entries are dropped
     */
    public function getUpdates(
        int $offset,
        int $timeout,
        ?Cancellation $cancellation = null,
        array $allowedUpdates = ['message'],
        int $limit = 100,
    ): array {
        $result = self::expectArray('getUpdates', $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => \max(0, $timeout),
            'limit' => \max(1, \min(100, $limit)),
            'allowed_updates' => $allowedUpdates,
        ], $cancellation, (float) \max(0, $timeout) + self::POLL_GRACE_SECONDS));

        return \array_values(\array_filter($result, 'is_array'));
    }

    /**
     * @return array<array-key, mixed> the sent `Message` object
     */
    public function sendMessage(
        string $chatId,
        string $text,
        ParseMode $parseMode = ParseMode::Plain,
        ?string $replyToMessageId = null,
        ?string $threadId = null,
        bool $disableLinkPreview = false,
        ?Cancellation $cancellation = null,
    ): array {
        $params = ['chat_id' => self::chatId($chatId)] + self::formatted($text, $parseMode);
        if ($replyToMessageId !== null) {
            $params['reply_parameters'] = [
                'message_id' => (int) $replyToMessageId,
                'allow_sending_without_reply' => true,
            ];
        }
        if ($threadId !== null) {
            $params['message_thread_id'] = (int) $threadId;
        }
        if ($disableLinkPreview) {
            $params['link_preview_options'] = ['is_disabled' => true];
        }

        return self::expectArray('sendMessage', $this->call('sendMessage', $params, $cancellation));
    }

    public function editMessageText(
        string $chatId,
        string $messageId,
        string $text,
        ParseMode $parseMode = ParseMode::Plain,
        ?Cancellation $cancellation = null,
    ): void {
        $params = ['chat_id' => self::chatId($chatId), 'message_id' => (int) $messageId]
            + self::formatted($text, $parseMode);
        $this->call('editMessageText', $params, $cancellation);
    }

    public function sendChatAction(
        string $chatId,
        string $action = 'typing',
        ?string $threadId = null,
        ?Cancellation $cancellation = null,
    ): void {
        $params = ['chat_id' => self::chatId($chatId), 'action' => $action];
        if ($threadId !== null) {
            $params['message_thread_id'] = (int) $threadId;
        }
        $this->call('sendChatAction', $params, $cancellation);
    }

    /**
     * Calls any Bot API method and returns its `result`.
     *
     * @param array<string, mixed> $params
     * @param float|null $timeout overrides the default timeout, seconds
     * @throws TelegramException
     */
    public function call(
        string $method,
        array $params = [],
        ?Cancellation $cancellation = null,
        ?float $timeout = null,
    ): mixed {
        if (\preg_match('/^[A-Za-z]+$/', $method) !== 1) {
            throw new \InvalidArgumentException('Invalid Bot API method name');
        }

        $request = new Request($this->baseUrl . '/bot' . $this->token . '/' . $method, 'POST');
        $request->setHeader('content-type', 'application/json');
        $request->setBody(\json_encode($params === [] ? new \stdClass() : $params, \JSON_THROW_ON_ERROR));
        $request->setTransferTimeout($timeout ?? $this->timeout);
        $request->setInactivityTimeout($timeout ?? $this->timeout);
        $request->setBodySizeLimit(self::BODY_SIZE_LIMIT);

        try {
            $response = $this->httpClient->request($request, $cancellation);
            $body = $response->getBody()->buffer($cancellation);
        } catch (CancelledException $e) {
            throw $e;
        } catch (HttpException|StreamException $e) {
            // Not chained: the original exception may reference the request URL with the token.
            throw new TelegramException(
                $method,
                SendErrorKind::Transient,
                0,
                $this->redact(\get_class($e) . ': ' . $e->getMessage()),
            );
        }

        $status = $response->getStatus();
        try {
            $data = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!\is_array($data)) {
            throw new TelegramException(
                $method,
                $status >= 500 ? SendErrorKind::Transient : SendErrorKind::Unknown,
                $status,
                "Unexpected non-JSON response (HTTP {$status})",
            );
        }

        if (($data['ok'] ?? false) === true) {
            return $data['result'] ?? null;
        }

        $parameters = self::arrayOrEmpty($data['parameters'] ?? null);

        throw TelegramException::fromApiError(
            $method,
            self::intOrNull($data['error_code'] ?? null) ?? $status,
            $this->redact(self::stringOrNull($data['description'] ?? null) ?? 'Unknown error'),
            self::intOrNull($parameters['retry_after'] ?? null),
        );
    }

    /**
     * Replaces every occurrence of the token (plain or URL-encoded) with `***`.
     */
    public function redact(string $text): string
    {
        return \str_replace([$this->token, \rawurlencode($this->token)], '***', $text);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function expectArray(string $method, mixed $result): array
    {
        if (!\is_array($result)) {
            throw new TelegramException($method, SendErrorKind::Unknown, 0, 'Unexpected result type');
        }

        return $result;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayOrEmpty(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    private static function chatId(string $chatId): int|string
    {
        return \preg_match('/^-?\d{1,19}$/', $chatId) === 1 ? (int) $chatId : $chatId;
    }

    /**
     * The `text` and `parse_mode` parameters; common Markdown is converted to Telegram HTML.
     *
     * @return array{text: string, parse_mode?: string}
     */
    private static function formatted(string $text, ParseMode $parseMode): array
    {
        return match ($parseMode) {
            ParseMode::Plain => ['text' => $text],
            ParseMode::Markdown => ['text' => MarkdownToTelegramHtml::convert($text), 'parse_mode' => 'HTML'],
            ParseMode::Html => ['text' => $text, 'parse_mode' => 'HTML'],
            ParseMode::MarkdownV2 => ['text' => $text, 'parse_mode' => 'MarkdownV2'],
        };
    }
}
