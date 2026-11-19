<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * A messaging channel: Telegram, a WebSocket/REST API, Slack and so on. What only some channels do — forum topics,
 * polls, contacts, a point on a map — is in optional interfaces (`ForumChannel`, `PollChannel`, `ContactChannel`,
 * `LocationChannel`); what every channel does in its own way is in `capabilities()`.
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
     * Deletes a message — for example one that carried a secret. Best effort: false when it could not be deleted.
     */
    public function delete(string $chatId, string $messageId): bool;

    /**
     * Leaves a group or a channel — for example one the owner did not approve. Best effort: false when it failed.
     */
    public function leave(string $chatId): bool;

    /**
     * Sends a file as a document; $threadId — the forum topic it belongs to.
     */
    public function sendFile(string $chatId, string $fileName, string $content, ?string $caption = null, ?string $threadId = null): SendResult;

    /**
     * Uploads a file as the given kind: a photo, audio, video, a voice message, an animation or a document.
     */
    public function sendMedia(string $chatId, MediaKind $kind, string $fileName, string $content, ?string $caption = null, ?string $threadId = null): SendResult;

    /**
     * The bytes of an incoming file (`Attachment::$fileId`); null when it is larger than `$maxBytes` or could not be
     * downloaded. A Telegram bot downloads files up to 20 MB.
     */
    public function download(string $fileId, int $maxBytes): ?string;

    /**
     * Shows a "typing" indicator. Best effort: never fails.
     */
    public function typing(string $chatId): void;

    public function capabilities(): Capabilities;
}
