<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * Receives changes of the bot's own membership in chats.
 */
interface MembershipHandler
{
    public function handleMembership(IncomingMembership $change): void;
}
