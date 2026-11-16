<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Telegram;

use SensitiveParameter;

/**
 * Checks the `X-Telegram-Bot-Api-Secret-Token` header of a webhook request.
 *
 * Without the check anyone who knows the webhook URL can post forged updates, so an empty expected secret is
 * treated as "reject everything".
 */
final class WebhookRequestValidator
{
    public const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    public function isValid(
        #[SensitiveParameter] ?string $secretHeader,
        #[SensitiveParameter] string $expectedSecret,
    ): bool {
        if ($secretHeader === null || $expectedSecret === '') {
            return false;
        }

        return \hash_equals($expectedSecret, $secretHeader);
    }
}
