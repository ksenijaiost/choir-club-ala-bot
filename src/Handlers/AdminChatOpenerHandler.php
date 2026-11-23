<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Telegram\CallbackUi;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;

final class AdminChatOpenerHandler
{
    public static function handleCallback(
        Nutgram $bot,
        ?ProgramDeps $programDeps,
        ?BroadcastDeps $broadcastDeps,
        AccessControl $access,
        UserRepository $users,
        string $data,
        int $actorTelegramId,
    ): bool {
        if (!str_starts_with($data, 'opn:')) {
            return false;
        }

        $actor = $users->findByTelegramId($actorTelegramId);
        if ($actor === null) {
            SafeTelegram::answerCallbackQuery($bot);

            return true;
        }

        $callbackMessage = CallbackUi::callbackMessage($bot);
        $targetChatId = $callbackMessage !== null
            ? (int) $callbackMessage->chat->id
            : ($bot->chatId() ?? null);
        $replyTo = $callbackMessage !== null ? (int) $callbackMessage->message_id : null;

        if ($data === 'opn:prg') {
            if ($programDeps === null || !$access->canManageBroadcasts($actor)) {
                SafeTelegram::answerCallbackQuery($bot);

                return true;
            }
            SafeTelegram::answerCallbackQuery($bot);
            ProgramAdminHandler::openHome($bot, $programDeps, $access, $actor, $targetChatId, $replyTo);

            return true;
        }

        if ($data === 'opn:bc') {
            if ($broadcastDeps === null || !$access->canManageBroadcasts($actor)) {
                SafeTelegram::answerCallbackQuery($bot);

                return true;
            }
            SafeTelegram::answerCallbackQuery($bot);
            BroadcastWizardHandler::start($bot, $broadcastDeps, $access, $actor, $targetChatId, $replyTo);

            return true;
        }

        if ($data === 'opn:polls') {
            if ($broadcastDeps === null || !$access->canManageBroadcasts($actor)) {
                SafeTelegram::answerCallbackQuery($bot);

                return true;
            }
            SafeTelegram::answerCallbackQuery($bot);
            BroadcastAdminHandler::openList($bot, $broadcastDeps, $access, $actor, true);

            return true;
        }

        return false;
    }
}