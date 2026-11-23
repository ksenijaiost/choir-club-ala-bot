<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AdminChatCommandRedirect;
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
                AdminChatCommandRedirect::notifyOpenedInPrivate(
                    $bot,
                    $deps->texts,
                    (int) $chatId,
                    (int) ($user['telegram_id'] ?? 0),
                    'programs.admin_chat_open_button',
                    $deps->texts->get('programs.admin_chat_open_action'),
                    'opn:prg',
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