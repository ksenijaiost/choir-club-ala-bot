<?php

declare(strict_types=1);

namespace App\Telegram;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class SafeTelegram
{
    /**
     * @template T
     *
     * @param callable(): T $action
     * @return T
     */
    public static function call(callable $action): mixed
    {
        return TelegramApiRetry::run($action);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function answerCallbackQuery(Nutgram $bot, array $options = []): void
    {
        try {
            if ($options === []) {
                self::call(static fn (): mixed => $bot->answerCallbackQuery());
            } else {
                self::call(static fn (): mixed => $bot->answerCallbackQuery(...$options));
            }
        } catch (\Throwable $e) {
            error_log('[telegram] answerCallbackQuery warning: ' . $e->getMessage());
        }
    }

    public static function sendMessage(
        Nutgram $bot,
        string $text,
        ?int $chatId = null,
        ?InlineKeyboardMarkup $replyMarkup = null,
        ?ParseMode $parseMode = null,
    ): ?Message {
        try {
            return self::call(static function () use ($bot, $text, $chatId, $replyMarkup, $parseMode): ?Message {
                if ($chatId !== null) {
                    return $bot->sendMessage(
                        text: $text,
                        chat_id: $chatId,
                        reply_markup: $replyMarkup,
                        parse_mode: $parseMode,
                    );
                }

                return $bot->sendMessage(
                    text: $text,
                    reply_markup: $replyMarkup,
                    parse_mode: $parseMode,
                );
            });
        } catch (\Throwable $e) {
            error_log('[telegram] sendMessage warning: ' . $e->getMessage());

            return null;
        }
    }

    public static function editMessageText(
        Nutgram $bot,
        int $chatId,
        int $messageId,
        string $text,
        ?InlineKeyboardMarkup $replyMarkup = null,
        ?ParseMode $parseMode = null,
    ): void {
        try {
            self::call(static fn (): mixed => $bot->editMessageText(
                text: $text,
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: $replyMarkup,
                parse_mode: $parseMode,
            ));
        } catch (\Throwable $e) {
            error_log('[telegram] editMessageText warning: ' . $e->getMessage());
        }
    }

    public static function editMessageReplyMarkup(
        Nutgram $bot,
        int $chatId,
        int $messageId,
        ?InlineKeyboardMarkup $replyMarkup = null,
    ): void {
        try {
            self::call(static fn (): mixed => $bot->editMessageReplyMarkup(
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: $replyMarkup,
            ));
        } catch (\Throwable $e) {
            error_log('[telegram] editMessageReplyMarkup warning: ' . $e->getMessage());
        }
    }

    public static function deleteMessage(Nutgram $bot, int $chatId, int $messageId): void
    {
        try {
            self::call(static fn (): mixed => $bot->deleteMessage(chat_id: $chatId, message_id: $messageId));
        } catch (\Throwable $e) {
            error_log('[telegram] deleteMessage warning: ' . $e->getMessage());
        }
    }
}