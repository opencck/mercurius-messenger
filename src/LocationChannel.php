<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/** A channel that sends a point on a map. Optional: a caller checks `instanceof`. */
interface LocationChannel extends Channel
{
    public function sendLocation(string $chatId, float $latitude, float $longitude, ?string $threadId = null): SendResult;
}
