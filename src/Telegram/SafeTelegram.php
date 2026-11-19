<?php

declare(strict_types=1);

namespace App\Telegram;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\LinkPreviewOptions;
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
            TelegramApiErrors::logTelegramIssue('answerCallbackQuery', $e);
        }
    }

    public static function sendMessage(
        Nutgram $bot,
        string $text,
        ?int $chatId = null,
        ?InlineKeyboardMarkup $replyMarkup = null,
        ?ParseMode $parseMode = null,
        bool $disableLinkPreview = false,
    ): ?Message {
        try {
            $linkPreview = $disableLinkPreview ? LinkPreviewOptions::make(is_disabled: true) : null;

            return self::call(static function () use ($bot, $text, $chatId, $replyMarkup, $parseMode, $linkPreview): ?Message {
                if ($chatId !== null) {
                    return $bot->sendMessage(
                        text: $text,
                        chat_id: $chatId,
                        reply_markup: $replyMarkup,
                        parse_mode: $parseMode,
                        link_preview_options: $linkPreview,
                    );
                }

                return $bot->sendMessage(
                    text: $text,
                    reply_markup: $replyMarkup,
                    parse_mode: $parseMode,
                    link_preview_options: $linkPreview,
                );
            });
        } catch (\Throwable $e) {
            TelegramApiErrors::logTelegramIssue('sendMessage', $e);

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
            TelegramApiErrors::logTelegramIssue('editMessageText', $e);
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
            TelegramApiErrors::logTelegramIssue('editMessageReplyMarkup', $e);
        }
    }

    public static function deleteMessage(Nutgram $bot, int $chatId, int $messageId): void
    {
        try {
            self::call(static fn (): mixed => $bot->deleteMessage(chat_id: $chatId, message_id: $messageId));
        } catch (\Throwable $e) {
            TelegramApiErrors::logTelegramIssue('deleteMessage', $e);
        }
    }

    public static function sendAudio(
        Nutgram $bot,
        string $audio,
        ?string $caption = null,
        ?int $chatId = null,
        ?ParseMode $parseMode = null,
    ): string {
        try {
            self::call(static function () use ($bot, $audio, $caption, $chatId, $parseMode): mixed {
                if ($chatId !== null) {
                    return $bot->sendAudio(
                        audio: $audio,
                        caption: $caption !== null && $caption !== '' ? $caption : null,
                        chat_id: $chatId,
                        parse_mode: $parseMode,
                    );
                }

                return $bot->sendAudio(
                    audio: $audio,
                    caption: $caption !== null && $caption !== '' ? $caption : null,
                    parse_mode: $parseMode,
                );
            });

            return 'ok';
        } catch (\Throwable $e) {
            if (TelegramApiErrors::isVoiceMessagesForbidden($e)) {
                TelegramApiErrors::logTelegramIssue('sendAudio', $e);

                return 'voice_forbidden';
            }

            TelegramApiErrors::logTelegramIssue('sendAudio', $e);

            return 'failed';
        }
    }
}