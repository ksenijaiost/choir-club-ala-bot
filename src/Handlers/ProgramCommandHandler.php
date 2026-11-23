<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\UserRepository;
use App\Services\AccessControl;
use SergiX44\Nutgram\Nutgram;

final class ProgramCommandHandler
{
    public static function register(
        Nutgram $bot,
        ProgramDeps $deps,
        AccessControl $access,
        UserRepository $users,
    ): void {
        $bot->onCommand('programs', function (Nutgram $bot) use ($deps, $access, $users): void {
            $chatId = $bot->chatId();
            if ($chatId !== null && $access->isAdminChatId($chatId)) {
                $user = AdminChatCommandHandler::resolveActor($bot, $access, $users);
                if ($user === null || !$access->canManageBroadcasts($user)) {
                    return;
                }
                $messageId = (int) ($bot->message()?->message_id ?? 0);
                ProgramAdminHandler::openHome(
                    $bot,
                    $deps,
                    $access,
                    $user,
                    (int) $chatId,
                    $messageId > 0 ? $messageId : null,
                );

                return;
            }

            $telegramId = $bot->userId();
            if ($telegramId === null) {
                return;
            }

            $user = $users->findByTelegramId($telegramId);
            if ($user === null) {
                return;
            }

            ProgramAdminHandler::openHome($bot, $deps, $access, $user);
        });
    }
}