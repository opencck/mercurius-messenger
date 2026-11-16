<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * Receives incoming messages. Implemented by the application.
 *
 * Called inside its own fiber; messages of one chat are delivered in order, one at a time.
 * An exception thrown here is logged and does not stop the receiving loop.
 */
interface MessageHandler
{
    public function handle(IncomingMessage $message): void;
}
