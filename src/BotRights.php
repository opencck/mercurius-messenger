<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * What the bot may do in a group chat at the moment it was asked.
 */
final class BotRights
{
    public function __construct(
        /** member, administrator, creator, restricted, left or kicked */
        public readonly string $status,
        /** the privacy mode is off: every message of a group comes to the bot */
        public readonly bool $readsAll,
        public readonly bool $canManageTopics,
        public readonly bool $canDeleteTopics,
    ) {}

    public function isAdmin(): bool
    {
        return $this->status === 'administrator' || $this->status === 'creator';
    }

    /** Whether every message of the group comes to the bot, not only what is addressed to it. */
    public function seesAll(): bool
    {
        return $this->readsAll || $this->isAdmin();
    }
}
