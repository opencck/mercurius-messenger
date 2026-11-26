<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * A channel that can say what the bot may do in a group. Optional: a caller checks `instanceof`.
 */
interface RightsChannel extends Channel
{
    /** The bot's rights in a chat; null when they could not be learned. */
    public function rights(string $chatId): ?BotRights;
}
