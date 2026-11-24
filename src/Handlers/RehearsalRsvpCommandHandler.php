<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\UserRepository;
use App\Services\AccessControl;
use SergiX44\Nutgram\Nutgram;

final class RehearsalRsvpCommandHandler
{
    public static function register(
        Nutgram $bot,
        AttendanceDeps $deps,
        AccessControl $access,
        UserRepository $users,
    ): void {
        $bot->onCommand('rsvp', function (Nutgram $bot) use ($deps, $access, $users): void {
            $chatId = $bot->chatId();
            if ($chatId !== null && $access->isAdminChatId($chatId)) {
                $user = AdminChatCommandHandler::resolveActor($bot, $access, $users);
                if ($user === null || !$access->hasConductorPrivileges($user)) {
                    return;
                }
                $messageId = (int) ($bot->message()?->message_id ?? 0);
                RehearsalRsvpAdminHandler::openHome(
                    $bot,
                    $deps,
                    $access,
                    $user,
                    null,
                    (int) $chatId,
                );

                return;
            }

            $telegramId = $bot->userId();
            if ($telegramId === null) {
                return;
            }

            $user = $users->findByTelegramId($telegramId);
            if ($user === null || !$access->hasConductorPrivileges($user)) {
                return;
            }

            RehearsalRsvpAdminHandler::openHome($bot, $deps, $access, $user);
        });
    }
}