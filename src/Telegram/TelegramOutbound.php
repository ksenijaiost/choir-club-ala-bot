<?php

declare(strict_types=1);

namespace App\Telegram;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

interface TelegramOutbound
{
    public function sendMessage(int $chatId, string $text, ?InlineKeyboardMarkup $replyMarkup = null): void;
}
