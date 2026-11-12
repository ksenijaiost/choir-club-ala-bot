<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Domain\UserRole;
use App\Domain\VoicePart;
use App\Text\TextRepository;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class PartKeyboard
{
    public static function registration(TextRepository $texts): InlineKeyboardMarkup
    {
        return self::partGrid($texts, 'rp:');
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function profile(TextRepository $texts, array $user): InlineKeyboardMarkup
    {
        $keyboard = self::partGrid($texts, 'prf:pt:');
        $role = (string) ($user['role'] ?? '');
        if (in_array($role, [UserRole::OWNER, UserRole::CONDUCTOR], true)) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $texts->get('profile.part_none_button'),
                    callback_data: 'prf:pt:none',
                ),
            );
        }

        return InlineMenuHelper::appendFooter($keyboard, $texts);
    }

    private static function partGrid(TextRepository $texts, string $callbackPrefix): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();
        $row = [];

        foreach (VoicePart::CODES as $code) {
            $row[] = InlineKeyboardButton::make(
                text: $texts->get('parts.' . $code),
                callback_data: $callbackPrefix . $code,
            );

            if (count($row) === 2) {
                $keyboard = $keyboard->addRow(...$row);
                $row = [];
            }
        }

        if ($row !== []) {
            $keyboard = $keyboard->addRow(...$row);
        }

        return $keyboard;
    }
}
