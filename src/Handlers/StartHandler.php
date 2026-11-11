<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\UserIdentitySync;
use App\Telegram\MenuKeyboard;
use App\Telegram\PartKeyboard;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class StartHandler
{
    public static function register(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
    ): void {
        $bot->onCommand('start', function (Nutgram $bot) use ($texts, $users, $access): void {
            $telegramId = $bot->userId();
            if ($telegramId === null) {
                return;
            }

            $displayName = trim($bot->user()?->first_name ?? '');
            UserIdentitySync::syncPrivilegedTelegramUser($telegramId, $displayName, $access, $users);

            if ($access->isOwnerTelegramId($telegramId)) {
                $user = $users->findByTelegramId($telegramId);
                if ($user !== null) {
                    self::respondByStatus($bot, $texts, $user);
                }

                return;
            }

            $user = $users->findByTelegramId($telegramId);
            if ($user === null) {
                $users->create($telegramId, '', null, UserRole::MEMBER, UserStatus::PENDING);
                $bot->sendMessage($texts->get('registration.welcome'));

                return;
            }

            self::respondByStatus($bot, $texts, $user);
        });
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function respondByStatus(Nutgram $bot, TextRepository $texts, array $user): void
    {
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
            if (self::isRegistrationIncomplete($user)) {
                if (trim((string) ($user['display_name'] ?? '')) === '') {
                    $bot->sendMessage($texts->get('registration.welcome'));
                } else {
                    $bot->sendMessage(
                        text: $texts->get('registration.ask_part'),
                        reply_markup: PartKeyboard::registration($texts),
                    );
                }

                return;
            }

            $bot->sendMessage($texts->get('registration.pending_user'));

            return;
        }

        if ($status === UserStatus::ACTIVE) {
            $name = (string) ($user['display_name'] ?? '');
            $bot->sendMessage(
                text: $texts->get('registration.already_registered', ['name' => $name]),
                reply_markup: MenuKeyboard::forUser($texts, $user),
            );
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function isRegistrationIncomplete(array $user): bool
    {
        if (trim((string) ($user['display_name'] ?? '')) === '') {
            return true;
        }

        $part = $user['voice_part'] ?? null;

        return $part === null || $part === '';
    }
}
