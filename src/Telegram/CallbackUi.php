<?php

declare(strict_types=1);

namespace App\Telegram;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class CallbackUi
{
    public static function callbackMessage(Nutgram $bot): ?Message
    {
        return $bot->callbackQuery()?->message;
    }

    public static function editCurrentMessage(
        Nutgram $bot,
        string $text,
        ?InlineKeyboardMarkup $replyMarkup = null,
    ): void {
        $message = self::callbackMessage($bot);
        if ($message === null) {
            return;
        }

        try {
            $bot->editMessageText(
                text: $text,
                chat_id: $message->chat->id,
                message_id: $message->message_id,
                reply_markup: $replyMarkup,
            );
        } catch (\Throwable) {
            self::clearKeyboard($bot, $message);
        }
    }

    public static function finalizeWithAnswer(Nutgram $bot, string $answerLine): void
    {
        $message = self::callbackMessage($bot);
        if ($message === null) {
            return;
        }

        $text = (string) ($message->text ?? '');
        if ($text !== '' && !str_contains($text, $answerLine)) {
            $text .= "\n\n" . $answerLine;
        }

        try {
            if ($text !== '') {
                $bot->editMessageText(
                    text: $text,
                    chat_id: $message->chat->id,
                    message_id: $message->message_id,
                    reply_markup: InlineKeyboardMarkup::make(),
                );
            } else {
                self::clearKeyboard($bot, $message);
            }
        } catch (\Throwable) {
            self::clearKeyboard($bot, $message);
        }
    }

    public static function clearKeyboard(Nutgram $bot, ?Message $message = null): void
    {
        $message ??= self::callbackMessage($bot);
        if ($message === null) {
            return;
        }

        try {
            $bot->editMessageReplyMarkup(
                chat_id: $message->chat->id,
                message_id: $message->message_id,
                reply_markup: InlineKeyboardMarkup::make(),
            );
        } catch (\Throwable) {
            // повторное нажатие или уже снятая клавиатура
        }
    }
}
