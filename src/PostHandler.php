<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * Receives posts of channels where the bot is an admin.
 */
interface PostHandler
{
    public function handlePost(IncomingPost $post): void;
}
