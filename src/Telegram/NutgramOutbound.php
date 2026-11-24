<?php

declare(strict_types=1);

namespace App\Telegram;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class NutgramOutbound implements TelegramOutbound
{
    public function __construct(private readonly Nutgram $bot)
    {
    }

    public function sendMessage(
        int $chatId,
        string $text,
        ?InlineKeyboardMarkup $replyMarkup = null,
        ?ParseMode $parseMode = null,
    ): ?int {
        $message = SafeTelegram::sendMessage($this->bot, $text, $chatId, $replyMarkup, $parseMode);

        return $message?->message_id;
    }

    public function closeCheckinPrompt(int $chatId, int $messageId, string $questionText, string $closedSuffix): void
    {
        $text = $questionText;
        if (!str_contains($text, $closedSuffix)) {
            $text .= "\n\n" . $closedSuffix;
        }

        try {
            $this->bot->editMessageText(
                text: $text,
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: InlineKeyboardMarkup::make(),
            );
        } catch (\Throwable) {
            try {
                $this->bot->editMessageReplyMarkup(
                    chat_id: $chatId,
                    message_id: $messageId,
                    reply_markup: InlineKeyboardMarkup::make(),
                );
            } catch (\Throwable) {
            }
        }
    }
}
