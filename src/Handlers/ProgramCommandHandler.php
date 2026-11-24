<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\UserStatus;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\UserIdentitySync;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;

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
            $telegramId = $bot->userId();
            if ($telegramId === null) {
                return;
            }

            $user = $users->findByTelegramId($telegramId);
            if ($user === null) {
                return;
            }

            if ($chatId !== null && $access->isAdminChatId($chatId)) {
                if (!$access->canManageBroadcasts($user)) {
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

            if (!self::ensureActivePrivateUser($bot, $deps, $access, $users, $telegramId, $user)) {
                return;
            }

            ProgramMemberHandler::openCatalog($bot, $deps, $user);
        });

        $bot->onCommand('programs_edit', function (Nutgram $bot) use ($deps, $access, $users): void {
            $telegramId = $bot->userId();
            if ($telegramId === null) {
                return;
            }

            $user = $users->findByTelegramId($telegramId);
            if ($user === null) {
                return;
            }

            $chatId = $bot->chatId();
            if ($chatId !== null && $access->isAdminChatId($chatId)) {
                if (!$access->canManageBroadcasts($user)) {
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

            if (!self::ensureActivePrivateUser($bot, $deps, $access, $users, $telegramId, $user)) {
                return;
            }

            if (!$access->canManageBroadcasts($user)) {
                SafeTelegram::sendMessage($bot, $deps->texts->get('programs.forbidden'));

                return;
            }

            ProgramAdminHandler::openHome($bot, $deps, $access, $user);
        });
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function ensureActivePrivateUser(
        Nutgram $bot,
        ProgramDeps $deps,
        AccessControl $access,
        UserRepository $users,
        int $telegramId,
        array $user,
    ): bool {
        $chat = $bot->chat();
        if ($chat === null || $chat->type !== ChatType::PRIVATE) {
            return false;
        }

        UserIdentitySync::syncPrivilegedTelegramUser(
            $telegramId,
            trim($bot->user()?->first_name ?? ''),
            $access,
            $users,
        );

        $status = (string) ($user['status'] ?? '');
        if ($status === UserStatus::BLOCKED) {
            SafeTelegram::sendMessage($bot, $deps->texts->get('registration.blocked'));

            return false;
        }
        if ($status === UserStatus::REJECTED) {
            SafeTelegram::sendMessage($bot, $deps->texts->get('registration.rejected'));

            return false;
        }
        if ($status === UserStatus::PENDING) {
            SafeTelegram::sendMessage($bot, $deps->texts->get('registration.pending_user'));

            return false;
        }

        return $status === UserStatus::ACTIVE;
    }
}