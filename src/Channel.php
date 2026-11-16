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
     * Replaces the text of a message sent earlier. Unchanged text is a successful no-op. The message gets exactly
     * the given buttons: none removes the ones it had.
     *
     * @param list<list<Button>> $buttons
     */
    public function edit(
        string $chatId,
        string $messageId,
        string $text,
        ParseMode $parseMode = ParseMode::Plain,
        array $buttons = [],
    ): SendResult;

    /**
     * Answers a button press, optionally with a short notice (an alert when `$alert`). Best effort: never fails.
     */
    public function answerCallback(string $callbackId, ?string $text = null, bool $alert = false): void;

    /**
     * Sends a file as a document.
     */
    public function sendFile(string $chatId, string $fileName, string $content, ?string $caption = null): SendResult;

    /**
     * Shows a "typing" indicator. Best effort: never fails.
     */
    public function typing(string $chatId): void;

    public function capabilities(): Capabilities;
}
