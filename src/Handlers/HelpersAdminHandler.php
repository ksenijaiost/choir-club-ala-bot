<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\UserRole;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class HelpersAdminHandler
{
    public static function register(Nutgram $bot, TextRepository $texts, UserRepository $users, AccessControl $access): void
    {
        $bot->onCommand('helpers', function (Nutgram $bot) use ($texts, $users, $access): void {
            $telegramId = $bot->userId();
            if ($telegramId === null) {
                return;
            }

            $user = $users->findByTelegramId($telegramId);
            if ($user === null) {
                return;
            }

            self::openHelpersUi($bot, $texts, $users, $access, $user);
        });
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function openHelpersUi(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        array $user,
    ): void {
        if (!$access->canManageHelpers($user)) {
            $bot->sendMessage($texts->get('admin.only_conductor_or_owner'));

            return;
        }

        self::sendHelpersList($bot, $texts, $users);
    }

    public static function sendHelpersList(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
    ): void {
        $helpers = $users->listActiveByRole(UserRole::ADMIN);
        $lines = [$texts->get('admin.helpers_list')];

        if ($helpers === []) {
            $lines[] = $texts->get('admin.helpers_list_empty');
        } else {
            foreach ($helpers as $helper) {
                $partLabel = $helper['voice_part'] !== null && $helper['voice_part'] !== ''
                    ? $texts->get('parts.' . (string) $helper['voice_part'])
                    : '—';
                $lines[] = $texts->get('admin.helper_line', [
                    'name' => (string) $helper['display_name'],
                    'part' => $partLabel,
                ]);
            }
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($helpers as $helper) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: $texts->get('buttons.remove_helper') . ': ' . (string) $helper['display_name'],
                    callback_data: 'hp:d:' . (int) $helper['id'],
                ),
            );
        }

        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make(
                text: $texts->get('buttons.appoint_helper'),
                callback_data: 'hp:pick',
            ),
        );

        $bot->sendMessage(text: implode("\n", $lines), reply_markup: $keyboard);
    }

    public static function sendMemberPicker(Nutgram $bot, TextRepository $texts, UserRepository $users): void
    {
        $members = $users->listActiveMembers();
        if ($members === []) {
            $bot->sendMessage($texts->get('admin.no_members_to_promote'));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($members as $member) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: (string) $member['display_name'],
                    callback_data: 'hp:a:' . (int) $member['id'],
                ),
            );
        }

        $bot->sendMessage(
            text: $texts->get('admin.pick_member_for_helper'),
            reply_markup: $keyboard,
        );
    }

    public static function handleCallback(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        string $data,
        int $actorTelegramId,
    ): bool {
        if (!str_starts_with($data, 'hp:')) {
            return false;
        }

        $actor = $users->findByTelegramId($actorTelegramId);
        if ($actor === null || !$access->canManageHelpers($actor)) {
            $bot->answerCallbackQuery(text: $texts->get('admin.only_conductor_or_owner'), show_alert: true);

            return true;
        }

        if ($data === 'hp:pick') {
            $bot->answerCallbackQuery();
            self::sendMemberPicker($bot, $texts, $users);

            return true;
        }

        if (preg_match('/^hp:a:(\d+)$/', $data, $m) === 1) {
            $targetId = (int) $m[1];
            $target = $users->findById($targetId);
            if ($target === null || ($target['role'] ?? '') !== UserRole::MEMBER) {
                $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

                return true;
            }

            $users->updateRole($targetId, UserRole::ADMIN);
            $bot->answerCallbackQuery();
            $bot->sendMessage($texts->get('admin.helper_appointed', ['name' => (string) $target['display_name']]));
            self::sendHelpersList($bot, $texts, $users);

            return true;
        }

        if (preg_match('/^hp:d:(\d+)$/', $data, $m) === 1) {
            $targetId = (int) $m[1];
            $target = $users->findById($targetId);
            if ($target === null || ($target['role'] ?? '') !== UserRole::ADMIN) {
                $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

                return true;
            }

            $users->updateRole($targetId, UserRole::MEMBER);
            $bot->answerCallbackQuery();
            $bot->sendMessage($texts->get('admin.helper_removed', ['name' => (string) $target['display_name']]));
            self::sendHelpersList($bot, $texts, $users);

            return true;
        }

        return false;
    }
}
