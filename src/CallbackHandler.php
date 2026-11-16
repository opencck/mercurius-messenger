<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * Handles button presses. Presses bypass the chat queue: a press often answers the very task that keeps the
 * chat busy (an approval), so queueing it behind that task would deadlock.
 */
interface CallbackHandler
{
    public function handleCallback(IncomingCallback $callback): void;
}
