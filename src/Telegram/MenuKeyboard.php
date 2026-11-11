<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Domain\UserRole;
use App\Text\TextRepository;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

final class MenuKeyboard
{
    /**
     * @param array<string, mixed> $user
     */
    public static function forUser(TextRepository $texts, array $user): ReplyKeyboardMarkup
    {
        $role = (string) ($user['role'] ?? UserRole::MEMBER);
        $rows = [];

        $rows[] = [
            $texts->get('menu.schedule'),
            $texts->get('menu.wont_come'),
        ];

        $rows[] = [
            $texts->get('menu.find_song'),
            $texts->get('menu.my_recordings'),
        ];

        if (in_array($role, [UserRole::ADMIN, UserRole::CONDUCTOR, UserRole::OWNER], true)) {
            $rows[] = [
                $texts->get('menu.admin_schedule'),
                $texts->get('menu.admin_materials'),
            ];
            $rows[] = [
                $texts->get('menu.admin_attendance'),
            ];
        }

        if ($role === UserRole::CONDUCTOR || $role === UserRole::OWNER) {
            $rows[] = [
                $texts->get('menu.admin_payment'),
                $texts->get('menu.admin_history'),
            ];
            $rows[] = [
                $texts->get('menu.admin_helpers'),
                $texts->get('menu.admin_members'),
            ];
        }

        if ($role === UserRole::OWNER) {
            $rows[] = [
                $texts->get('menu.assign_conductor'),
            ];
        }

        $keyboard = ReplyKeyboardMarkup::make(resize_keyboard: true);
        foreach ($rows as $row) {
            $buttons = array_map(
                static fn (string $label): KeyboardButton => KeyboardButton::make($label),
                $row
            );
            $keyboard = $keyboard->addRow(...$buttons);
        }

        return $keyboard;
    }

    /**
     * @return list<string>
     */
    public static function allMenuLabels(TextRepository $texts): array
    {
        return [
            $texts->get('menu.schedule'),
            $texts->get('menu.wont_come'),
            $texts->get('menu.find_song'),
            $texts->get('menu.my_recordings'),
            $texts->get('menu.admin_schedule'),
            $texts->get('menu.admin_attendance'),
            $texts->get('menu.admin_payment'),
            $texts->get('menu.admin_history'),
            $texts->get('menu.admin_helpers'),
            $texts->get('menu.admin_members'),
            $texts->get('menu.admin_materials'),
            $texts->get('menu.assign_conductor'),
        ];
    }
}
