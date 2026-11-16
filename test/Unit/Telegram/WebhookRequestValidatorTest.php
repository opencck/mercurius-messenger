<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Unit\Telegram;

use Mercurius\Messenger\Telegram\WebhookRequestValidator;
use PHPUnit\Framework\TestCase;

final class WebhookRequestValidatorTest extends TestCase
{
    public function testAcceptsMatchingSecret(): void
    {
        self::assertTrue((new WebhookRequestValidator())->isValid('s3cret_TOKEN-1', 's3cret_TOKEN-1'));
    }

    public function testRejectsWrongMissingOrEmptySecret(): void
    {
        $validator = new WebhookRequestValidator();

        self::assertFalse($validator->isValid('wrong', 's3cret'));
        self::assertFalse($validator->isValid('s3cre', 's3cret'));
        self::assertFalse($validator->isValid(null, 's3cret'));
        self::assertFalse($validator->isValid('', 's3cret'));
        self::assertFalse($validator->isValid('', ''), 'an empty expected secret rejects everything');
    }
}
