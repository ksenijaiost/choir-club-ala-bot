<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\UserStatus;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\StatsPresenter;
use App\Telegram\SafeTelegram;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;

final class StatsCommandHandler
{
    public static function register(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        StatsPresenter $presenter,
    ): void {
        $bot->onCommand('stats', function (Nutgram $bot) use ($texts, $users, $access, $presenter): void {
            self::openStats($bot, $texts, $users, $access, $presenter, '7');
        });
    }

    public static function handleCallback(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        StatsPresenter $presenter,
        string $data,
        int $actorTelegramId,
    ): bool {
        if (!preg_match('/^stats:(7|30|all)$/', $data, $m)) {
            return false;
        }

        $actor = $users->findByTelegramId($actorTelegramId);
        if ($actor === null || !self::canViewStats($access, $actor)) {
            SafeTelegram::answerCallbackQuery($bot);

            return true;
        }

        SafeTelegram::answerCallbackQuery($bot);
        $built = $presenter->build($m[1]);
        SafeTelegram::editMessageText(
            $bot,
            (int) ($bot->callbackQuery()?->message?->chat?->id ?? $actorTelegramId),
            (int) ($bot->callbackQuery()?->message?->message_id ?? 0),
            $built['text'],
            $built['keyboard'],
        );

        return true;
    }

    private static function openStats(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        StatsPresenter $presenter,
        string $period,
    ): void {
        $chat = $bot->chat();
        if ($chat === null) {
            return;
        }

        $chatId = $bot->chatId();
        if ($chatId !== null && $access->isAdminChatId($chatId)) {
            $user = AdminChatCommandHandler::resolveActor($bot, $access, $users);
            if ($user === null || !self::canViewStats($access, $user)) {
                return;
            }
            $built = $presenter->build($period);
            SafeTelegram::sendMessage(
                $bot,
                $built['text'],
                chatId: (int) $chatId,
                replyMarkup: $built['keyboard'],
            );

            return;
        }

        if ($chat->type !== ChatType::PRIVATE) {
            return;
        }

        $telegramId = $bot->userId();
        if ($telegramId === null) {
            return;
        }

        $user = $users->findByTelegramId($telegramId);
        if ($user === null || (string) ($user['status'] ?? '') !== UserStatus::ACTIVE) {
            return;
        }

        if (!self::canViewStats($access, $user)) {
            $bot->sendMessage($texts->get('usage_stats.forbidden'));

            return;
        }

        $built = $presenter->build($period);
        SafeTelegram::sendMessage($bot, $built['text'], replyMarkup: $built['keyboard']);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function canViewStats(AccessControl $access, array $user): bool
    {
        return $access->canManageBroadcasts($user) || $access->hasConductorPrivileges($user);
    }
}