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
        ?InteractiveMenuService $menus = null,
        ?int $ownerTelegramId = null,
    ): void {
        $message = self::callbackMessage($bot);
        if ($message === null) {
            return;
        }

        try {
            SafeTelegram::call(static fn (): mixed => $bot->editMessageText(
                text: $text,
                chat_id: $message->chat->id,
                message_id: $message->message_id,
                reply_markup: $replyMarkup,
            ));
            if ($menus !== null && $ownerTelegramId !== null) {
                InlineMenuHelper::trackEditedMenu(
                    $menus,
                    $ownerTelegramId,
                    (int) $message->chat->id,
                    (int) $message->message_id,
                    $replyMarkup,
                );
            }
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
                SafeTelegram::editMessageText(
                    $bot,
                    (int) $message->chat->id,
                    (int) $message->message_id,
                    $text,
                    InlineKeyboardMarkup::make(),
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
            SafeTelegram::editMessageReplyMarkup(
                $bot,
                (int) $message->chat->id,
                (int) $message->message_id,
                InlineKeyboardMarkup::make(),
            );
        } catch (\Throwable $e) {
            TelegramApiErrors::logTelegramIssue('clearKeyboard', $e);
        }
    }
}