<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Integration;

use Amp\Http\Client\HttpClientBuilder;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\Telegram\TelegramApi;

/**
 * Calls the real Bot API. Runs only with `composer test-integration` and `TELEGRAM_BOT_TOKEN` set.
 *
 * @group integration
 */
final class TelegramApiIntegrationTest extends AsyncTestCase
{
    public function testGetMe(): void
    {
        $token = \getenv('TELEGRAM_BOT_TOKEN');
        if (!\is_string($token) || $token === '') {
            self::markTestSkipped('TELEGRAM_BOT_TOKEN is not set');
        }

        $api = new TelegramApi($token, HttpClientBuilder::buildDefault());
        $me = $api->getMe();

        self::assertTrue($me['is_bot'] ?? false);
        self::assertIsInt($me['id'] ?? null);
    }
}
