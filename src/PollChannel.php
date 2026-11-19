<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/** A channel that sends polls. Optional: a caller checks `instanceof`. */
interface PollChannel extends Channel
{
    /**
     * @param list<string> $options two to ten answers
     */
    public function sendPoll(string $chatId, string $question, array $options, bool $anonymous = true, bool $multiple = false, ?string $threadId = null): SendResult;
}
