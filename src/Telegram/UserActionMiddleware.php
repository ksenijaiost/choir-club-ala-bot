<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Services\UserActionRecorder;
use SergiX44\Nutgram\Nutgram;

final class UserActionMiddleware
{
    public static function register(Nutgram $bot, UserActionRecorder $recorder): void
    {
        $bot->middleware(static function (Nutgram $bot, $next) use ($recorder): void {
            try {
                $recorder->record($bot);
            } catch (\Throwable $e) {
                error_log('[user-action-middleware] ' . $e->getMessage());
            }

            $next($bot);
        });
    }
}