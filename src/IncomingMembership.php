<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * The bot's own membership in a chat changed: it was added, made an admin, removed or left. Telegram sends it as
 * `my_chat_member`, also when nobody writes in the chat yet. Every field comes from the outside world.
 */
final readonly class IncomingMembership
{
    public function __construct(
        public string $channel,
        public string $chatId,
        public ?string $chatTitle,
        /** private, group, supergroup or channel */
        public string $chatType,
        /** who made the change */
        public string $userId,
        public ?string $displayName,
        /** the bot's new status: creator, administrator, member, restricted, left or kicked */
        public string $status,
        public ?string $oldStatus,
        public string $updateId,
        /** @var array<string, bool> the admin rights of the bot (`can_post_messages`…), empty for a plain member */
        public array $rights = [],
    ) {
    }

    /** The bot is in the chat now (a restricted member is still in it). */
    public function isMember(): bool
    {
        return \in_array($this->status, ['creator', 'administrator', 'member', 'restricted'], true);
    }

    public function dedupeKey(): string
    {
        return $this->channel . ':membership:' . $this->updateId;
    }
}
