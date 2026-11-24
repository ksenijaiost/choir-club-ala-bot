<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\AccessControl;
use SergiX44\Nutgram\Nutgram;

final class RehearsalRsvpCallbackHandler
{
    /**
     * @param array<string, mixed> $actor
     */
    public static function handle(
        Nutgram $bot,
        AttendanceDeps $deps,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
        if (!str_starts_with($data, 'rsv:')) {
            return false;
        }

        $chatId = $bot->chatId();
        if ($chatId !== null && $access->isAdminChatId($chatId)) {
            if (!$deps->interactiveMenus->callbackAllowed(
                $bot,
                (int) ($actor['telegram_id'] ?? 0),
                $deps->texts->get('common.unavailable'),
            )) {
                return true;
            }
        }

        return RehearsalRsvpAdminHandler::handleCallback($bot, $deps, $access, $actor, $data);
    }
}