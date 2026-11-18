<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Websocket;

/**
 * One open connection of a client of the WebSocket channel. The application adapts its WebSocket library to this, so
 * the package does not depend on one.
 */
interface Connection
{
    /** Sends one text frame; false when the connection is gone. */
    public function send(string $frame): bool;
}
