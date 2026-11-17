<?php

declare(strict_types=1);

namespace App\Tests;

use App\Telegram\TelegramApiRetry;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

final class TelegramApiRetryTest extends TestCase
{
    public function testRetriesOnceOnRateLimitWithinCap(): void
    {
        $attempts = 0;
        $result = TelegramApiRetry::run(static function () use (&$attempts): string {
            $attempts++;
            if ($attempts === 1) {
                throw new TelegramException('Too Many Requests: retry after 1', 429, null, ['retry_after' => 1]);
            }

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(2, $attempts);
    }

    public function testDoesNotRetryWhenRetryAfterTooLarge(): void
    {
        $this->expectException(TelegramException::class);
        TelegramApiRetry::run(static function (): void {
            throw new TelegramException('Too Many Requests: retry after 30', 429, null, ['retry_after' => 30]);
        });
    }

    public function testRetriesOnceOnCurlTimeout(): void
    {
        $attempts = 0;
        $result = TelegramApiRetry::run(static function () use (&$attempts): string {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('cURL error 28: Operation timed out');
            }

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(2, $attempts);
    }
}