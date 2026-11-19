<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/** A channel that sends contact cards. Optional: a caller checks `instanceof`. */
interface ContactChannel extends Channel
{
    public function sendContact(string $chatId, string $phone, string $firstName, ?string $lastName = null, ?string $threadId = null): SendResult;
}
