<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * A messaging channel: Telegram, a WebSocket/REST API, Slack and so on.
 *
 * Methods do not throw on platform errors: they return a failed `SendResult`. Cancellation and programming errors
 * still propagate.
 */
interface Channel
{
    /**
     * Stable channel name, e.g. `telegram`. Equals `IncomingMessage::$channel` of the messages it receives.
     */
    public function name(): string;

    /**
     * Sends a message, splitting it when it exceeds `Capabilities::$maxMessageLength`.
     */
    public function send(OutgoingMessage $message): SendResult;

    /**
     * Replaces the text of a message sent earlier. Unchanged text is a successful no-op.
     */
    public function edit(
        string $chatId,
        string $messageId,
        string $text,
        ParseMode $parseMode = ParseMode::Plain,
    ): SendResult;

    /**
     * Shows a "typing" indicator. Best effort: never fails.
     */
    public function typing(string $chatId): void;

    public function capabilities(): Capabilities;
}
