<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\UserStatus;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\UserIdentitySync;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;

final class PrivateMenuCommandHandler
{
    public static function register(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        ScheduleDeps $schedule,
        AttendanceDeps $attendance,
        PaymentDeps $payments,
        ?MaterialsDeps $materials,
        ?AdminHistoryContext $adminHistory,
        ?AdminChatDeps $adminChat,
        ?BroadcastDeps $broadcast,
        ?ProgramDeps $programDeps,
    ): void {
        foreach (['song', 'recordings', 'profile', 'about'] as $command) {
            $bot->onCommand($command, function (Nutgram $bot) use (
                $command,
                $texts,
                $users,
                $access,
                $schedule,
                $attendance,
                $payments,
                $materials,
                $adminHistory,
                $adminChat,
                $broadcast,
                $programDeps,
            ): void {
                $chat = $bot->chat();
                if ($chat === null || $chat->type !== ChatType::PRIVATE) {
                    return;
                }

                $telegramId = $bot->userId();
                if ($telegramId === null) {
                    return;
                }

                UserIdentitySync::syncPrivilegedTelegramUser(
                    $telegramId,
                    trim($bot->user()?->first_name ?? ''),
                    $access,
                    $users,
                );

                $user = $users->findByTelegramId($telegramId);
                if ($user === null) {
                    return;
                }

                $status = (string) ($user['status'] ?? '');
                if ($status === UserStatus::BLOCKED) {
                    $bot->sendMessage($texts->get('registration.blocked'));

                    return;
                }
                if ($status === UserStatus::REJECTED) {
                    $bot->sendMessage($texts->get('registration.rejected'));

                    return;
                }
                if ($status === UserStatus::PENDING) {
                    $bot->sendMessage($texts->get('registration.pending_user'));

                    return;
                }
                if ($status !== UserStatus::ACTIVE) {
                    return;
                }

                $label = match ($command) {
                    'song' => $texts->get('menu.find_song'),
                    'recordings' => $texts->get('menu.my_recordings'),
                    'profile' => $texts->get('menu.my_profile'),
                    'about' => $texts->get('menu.about_bot'),
                    default => '',
                };

                if ($label === '') {
                    return;
                }

                MenuActionDispatcher::dispatchMenuByLabel(
                    $bot,
                    $texts,
                    $users,
                    $access,
                    $schedule,
                    $attendance,
                    $payments,
                    $materials,
                    $adminHistory,
                    $adminChat,
                    $broadcast,
                    $programDeps,
                    $user,
                    $label,
                );
            });
        }
    }
}