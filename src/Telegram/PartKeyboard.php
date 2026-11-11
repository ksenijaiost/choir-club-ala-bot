<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Domain\VoicePart;
use App\Text\TextRepository;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class PartKeyboard
{
    public static function registration(TextRepository $texts): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();
        $row = [];

        foreach (VoicePart::CODES as $index => $code) {
            $row[] = InlineKeyboardButton::make(
                text: $texts->get('parts.' . $code),
                callback_data: 'rp:' . $code,
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
