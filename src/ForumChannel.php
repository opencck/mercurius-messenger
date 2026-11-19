<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * A channel with forum topics (Telegram supergroups with topics). Optional: a caller checks `instanceof` and gets a
 * failure (null or false) from a chat of a channel without topics.
 */
interface ForumChannel extends Channel
{
    /**
     * Creates a forum topic; its thread id, or null when the chat is not a forum or the bot may not manage topics.
     * The name is cut to what the messenger allows.
     */
    public function createTopic(string $chatId, string $name): ?string;

    /** Renames a forum topic. Best effort: false when it failed. */
    public function editTopic(string $chatId, string $threadId, string $name): bool;

    /** Closes a forum topic: only admins write in it. Best effort. */
    public function closeTopic(string $chatId, string $threadId): bool;

    /** Opens a closed forum topic again. Best effort. */
    public function reopenTopic(string $chatId, string $threadId): bool;

    /** Deletes a forum topic with all its messages; it cannot be undone. Best effort. */
    public function deleteTopic(string $chatId, string $threadId): bool;
}
