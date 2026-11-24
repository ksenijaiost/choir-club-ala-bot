<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\AccessControl;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;

final class ProgramCallbackHandler
{
    /**
     * @param array<string, mixed> $actor
     */
    public static function handle(
        Nutgram $bot,
        ProgramDeps $programs,
        MaterialsDeps $materials,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
        if (!str_starts_with($data, 'prg:')) {
            return false;
        }

        if (
            str_starts_with($data, 'prg:mr:')
            || str_starts_with($data, 'prg:mo:')
            || str_starts_with($data, 'prg:ls:')
            || str_starts_with($data, 'prg:in:')
            || str_starts_with($data, 'prg:nd:')
            || str_starts_with($data, 'prg:pk:')
        ) {
            return ProgramMemberHandler::handleCallback(
                $bot,
                $programs,
                $materials,
                $actor,
                $data,
            );
        }

        if (!$access->canManageBroadcasts($actor)) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $programs->texts->get('programs.forbidden'),
                'show_alert' => true,
            ]);

            return true;
        }

        $chatId = $bot->chatId();
        if ($chatId !== null && $access->isAdminChatId($chatId)) {
            if (!$programs->interactiveMenus->callbackAllowed(
                $bot,
                (int) ($actor['telegram_id'] ?? 0),
                $programs->texts->get('common.unavailable'),
            )) {
                return true;
            }
        }

        return ProgramAdminHandler::handleCallback($bot, $programs, $access, $actor, $data);
    }
}