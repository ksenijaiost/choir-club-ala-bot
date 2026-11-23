<?php

declare(strict_types=1);

namespace App\Telegram;

use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

final class TelegramApiRetry
{
    private const MAX_RETRY_AFTER = 20;

    /**
     * @template T
     *
     * @param callable(): T $action
     * @return T
     */
    public static function run(callable $action): mixed
    {
        try {
            return $action();
        } catch (TelegramException $e) {
            if (self::isRateLimited($e)) {
                $wait = self::retryAfterSeconds($e);
                if ($wait > 0 && $wait <= self::MAX_RETRY_AFTER) {
                    sleep($wait);

                    return $action();
                }
            }

            throw $e;
        }
    }

    public static function isRateLimited(TelegramException $e): bool
    {
        if ($e->getCode() === 429) {
            return true;
        }

        return str_contains($e->getMessage(), 'Too Many Requests');
    }

    public static function retryAfterSeconds(TelegramException $e): int
    {
        if ($e->hasParameter('retry_after')) {
            return max(0, (int) $e->getParameter('retry_after'));
        }

        if (preg_match('/retry after (\d+)/i', $e->getMessage(), $m) === 1) {
            return max(0, (int) $m[1]);
        }

        return 0;
    }
}