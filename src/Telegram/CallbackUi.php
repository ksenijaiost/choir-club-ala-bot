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

    public static function finalizeCheckinAnswer(
        Nutgram $bot,
        string $questionText,
        string $answerLine,
        InlineKeyboardMarkup $replyMarkup,
    ): void
    {
        $message = self::callbackMessage($bot);
        if ($message === null) {
            return;
        }

        $text = $questionText;
        if (!str_contains($text, $answerLine)) {
            $text .= "\n\n" . $answerLine;
        }

        self::editMessageAt($bot, (int) $message->chat->id, (int) $message->message_id, $text, $replyMarkup);
    }

    public static function editCheckinPrompt(
        Nutgram $bot,
        string $text,
        ?InlineKeyboardMarkup $replyMarkup = null,
    ): void
    {
        $message = self::callbackMessage($bot);
        if ($message === null) {
            return;
        }

        self::editMessageAt($bot, (int) $message->chat->id, (int) $message->message_id, $text, $replyMarkup);
    }

    public static function finalizeCheckinAt(
        Nutgram $bot,
        int $chatId,
        int $messageId,
        string $questionText,
        string $answerLine,
        InlineKeyboardMarkup $replyMarkup,
    ): void
    {
        $text = $questionText;
        if (!str_contains($text, $answerLine)) {
            $text .= "\n\n" . $answerLine;
        }

        self::editMessageAt($bot, $chatId, $messageId, $text, $replyMarkup);
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

    private static function editMessageAt(
        Nutgram $bot,
        int $chatId,
        int $messageId,
        string $text,
        ?InlineKeyboardMarkup $replyMarkup = null,
    ): void
    {
        try {
            SafeTelegram::editMessageText(
                $bot,
                $chatId,
                $messageId,
                $text,
                $replyMarkup,
            );
        } catch (\Throwable) {
            if ($replyMarkup !== null) {
                try {
                    SafeTelegram::editMessageReplyMarkup($bot, $chatId, $messageId, $replyMarkup);
                } catch (\Throwable) {
                }
            }
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