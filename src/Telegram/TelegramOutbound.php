<?php

declare(strict_types=1);

namespace App\Telegram;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

interface TelegramOutbound
{
    /**
     * @return int|null Telegram message_id when the transport provides it
     */
    public function sendMessage(int $chatId, string $text, ?InlineKeyboardMarkup $replyMarkup = null): ?int;

    public function closeCheckinPrompt(int $chatId, int $messageId, string $questionText, string $closedSuffix): void;
}
