<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AdminChatCommandMessenger;
use App\Services\AdminChatSongListPresenter;
use App\Services\UserIdentitySync;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class AdminChatCommandHandler
{
    public static function register(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        UserRepository $users,
        SongRepository $songs,
        MaterialRepository $materials,
    ): void {
        $songPresenter = new AdminChatSongListPresenter($songs, $materials, $texts);

        $bot->onCommand('songs', function (Nutgram $bot) use ($access, $users, $songPresenter): void {
            if (!self::isAdminChatMessage($bot, $access)) {
                return;
            }

            $user = self::resolveActor($bot, $access, $users);
            if ($user === null || !$access->canUploadMaterials($user)) {
                return;
            }

            $chatId = (int) $bot->chatId();
            AdminChatCommandMessenger::sendChunks($bot, $chatId, $songPresenter->buildMessageChunks());
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolveActor(
        Nutgram $bot,
        AccessControl $access,
        UserRepository $users,
    ): ?array {
        $telegramId = $bot->userId();
        if ($telegramId === null) {
            return null;
        }

        UserIdentitySync::syncPrivilegedTelegramUser(
            $telegramId,
            trim($bot->user()?->first_name ?? ''),
            $access,
            $users,
        );

        $user = $users->findByTelegramId($telegramId);
        if ($user === null) {
            return null;
        }

        $username = $bot->user()?->username;
        $users->updateTelegramUsername((int) $user['id'], $username !== null ? (string) $username : null);

        return $users->findById((int) $user['id']);
    }

    private static function isAdminChatMessage(Nutgram $bot, AccessControl $access): bool
    {
        $chatId = $bot->chatId();

        return $chatId !== null && $access->isAdminChatId($chatId);
    }

}