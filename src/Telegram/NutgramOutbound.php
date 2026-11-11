<?php

declare(strict_types=1);

namespace App\Telegram;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class NutgramOutbound implements TelegramOutbound
{
    public function __construct(private readonly Nutgram $bot)
    {
    }

    public function sendMessage(int $chatId, string $text, ?InlineKeyboardMarkup $replyMarkup = null): void
    {
        $this->bot->sendMessage(
            text: $text,
            chat_id: $chatId,
            reply_markup: $replyMarkup,
        );
    }
}
