<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;

final class BroadcastCommandHandler
{
    public static function register(
        Nutgram $bot,
        BroadcastDeps $deps,
        AccessControl $access,
        UserRepository $users,
    ): void {
        $bot->onCommand('broadcast', function (Nutgram $bot) use ($deps, $access, $users): void {
            $chatId = $bot->chatId();
            if ($chatId !== null && $access->isAdminChatId($chatId)) {
                $user = AdminChatCommandHandler::resolveActor($bot, $access, $users);
                if ($user === null || !$access->canManageBroadcasts($user)) {
                    return;
                }
                $privateId = (int) ($user['telegram_id'] ?? 0);
                if ($privateId > 0) {
                    SafeTelegram::sendMessage($bot, $deps->texts->get('broadcast.admin_chat_redirect'), $privateId);
                }

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

            BroadcastWizardHandler::start($bot, $deps, $access, $user);
        });

        $bot->onCommand('polls', function (Nutgram $bot) use ($deps, $access, $users): void {
            $chatId = $bot->chatId();
            if ($chatId !== null && $access->isAdminChatId($chatId)) {
                $user = AdminChatCommandHandler::resolveActor($bot, $access, $users);
                if ($user === null || !$access->canManageBroadcasts($user)) {
                    return;
                }
                $privateId = (int) ($user['telegram_id'] ?? 0);
                if ($privateId > 0) {
                    SafeTelegram::sendMessage($bot, $deps->texts->get('broadcast.admin_chat_redirect'), $privateId);
                }

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

            BroadcastAdminHandler::openList($bot, $deps, $access, $user, true);
        });
    }
}