<?php

declare(strict_types=1);

namespace App\Services;

use App\Telegram\SafeTelegram;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class AdminChatCommandRedirect
{
    public static function notifyOpenedInPrivate(
        Nutgram $bot,
        TextRepository $texts,
        int $adminChatId,
        int $privateTelegramId,
        string $privateTextKey,
        string $buttonLabel,
        string $callbackData,
    ): void {
        SafeTelegram::sendMessage(
            $bot,
            $texts->get('admin_chat.opened_in_private'),
            chatId: $adminChatId,
        );

        if ($privateTelegramId <= 0) {
            return;
        }

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make($buttonLabel, callback_data: $callbackData),
        );

        SafeTelegram::sendMessage(
            $bot,
            $texts->get($privateTextKey),
            chatId: $privateTelegramId,
            replyMarkup: $keyboard,
        );
    }
}