<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BotSettingsRepository;
use App\Telegram\SafeTelegram;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

final class AdminChatKeyboardRemoval
{
    public const SETTING_KEY = 'admin_chat_keyboard_removed';

    public static function removeReplyKeyboardOnce(
        Nutgram $bot,
        TextRepository $texts,
        BotSettingsRepository $settings,
        int $adminChatId,
    ): void {
        if ($settings->get(self::SETTING_KEY) === '1') {
            return;
        }

        $message = SafeTelegram::sendMessage(
            $bot,
            $texts->get('admin_chat.commands_updated'),
            chatId: $adminChatId,
            replyMarkup: ReplyKeyboardRemove::make(remove_keyboard: true, selective: false),
        );

        if ($message !== null) {
            $settings->set(self::SETTING_KEY, '1');
        }
    }
}