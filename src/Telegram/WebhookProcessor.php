<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Telegram;

use Mercurius\Messenger\ChatDispatcher;
use Mercurius\Messenger\Deduplicator;
use Mercurius\Messenger\MessageHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;

/**
 * Handles the body of a Telegram webhook request; the HTTP server itself belongs to the application.
 *
 * `process()` returns false when the secret header is wrong (answer 401/403). Otherwise it returns true even for
 * ignored or malformed updates (answer 200), so that Telegram does not redeliver them. Messages are handed to a
 * `ChatDispatcher` and handled after the response.
 */
final class WebhookProcessor
{
    /** Bot API updates are small; a larger body is not a legitimate update. */
    public const MAX_BODY_BYTES = 1024 * 1024;

    private readonly string $secret;
    private readonly ChatDispatcher $dispatcher;

    /**
     * @param string $secret the `secret_token` passed to `setWebhook`; required
     * @param MessageHandler|ChatDispatcher $handler a handler is wrapped into a `ChatDispatcher`
     */
    public function __construct(
        #[SensitiveParameter] string $secret,
        private readonly UpdateParser $parser,
        MessageHandler|ChatDispatcher $handler,
        private readonly Deduplicator $deduplicator = new Deduplicator(),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly WebhookRequestValidator $validator = new WebhookRequestValidator(),
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('A webhook secret is required: without it anyone can post forged updates');
        }
        $this->secret = $secret;
        $this->dispatcher = $handler instanceof ChatDispatcher ? $handler : new ChatDispatcher($handler, $logger);
    }

    /**
     * @param string|null $secretHeader value of the `X-Telegram-Bot-Api-Secret-Token` header
     * @param string $body raw request body
     * @return bool false: reject the request (bad secret); true: answer 200
     */
    public function process(#[SensitiveParameter] ?string $secretHeader, string $body): bool
    {
        if (!$this->validator->isValid($secretHeader, $this->secret)) {
            $this->logger->warning('Telegram webhook request with an invalid secret rejected');

            return false;
        }
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            $this->logger->warning('Telegram webhook body too large, ignored', ['bytes' => \strlen($body)]);

            return true;
        }

        try {
            $update = \json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning('Telegram webhook body is not valid JSON, ignored');

            return true;
        }
        if (!\is_array($update)) {
            return true;
        }

        $message = $this->parser->parse($update);
        if ($message !== null && !$this->deduplicator->isDuplicate($message->dedupeKey())) {
            $this->dispatcher->dispatch($message);
        }
        $callback = $message === null ? $this->parser->parseCallback($update) : null;
        if ($callback !== null && !$this->deduplicator->isDuplicate($callback->dedupeKey())) {
            $this->dispatcher->dispatchCallback($callback);
        }
        $membership = $message === null && $callback === null ? $this->parser->parseMembership($update) : null;
        if ($membership !== null && !$this->deduplicator->isDuplicate($membership->dedupeKey())) {
            $this->dispatcher->dispatchMembership($membership);
        }

        return true;
    }

    public function dispatcher(): ChatDispatcher
    {
        return $this->dispatcher;
    }
}
