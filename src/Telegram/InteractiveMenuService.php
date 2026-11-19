<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Repositories\UserOpenMenuRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class InteractiveMenuService
{
    private const LOG_PREFIX = '[interactive-menu]';

    public function __construct(
        private readonly UserOpenMenuRepository $openMenus,
    ) {
    }

    public function dismissPrevious(
        Nutgram $bot,
        int $telegramId,
        int $chatId,
        ?int $exceptMessageId = null,
    ): void {
        $stored = $this->openMenus->find($telegramId, $chatId);
        if ($stored === null) {
            return;
        }

        $previousId = (int) $stored['message_id'];
        if ($exceptMessageId !== null && $previousId === $exceptMessageId) {
            return;
        }

        $this->removeMessage($bot, $chatId, $previousId);
        $this->openMenus->delete($telegramId, $chatId);
    }

    public function recordOpen(int $telegramId, int $chatId, int $messageId): void
    {
        $this->openMenus->upsert($telegramId, $chatId, $messageId);
    }

    public function clearOpen(int $telegramId, int $chatId): void
    {
        $this->openMenus->delete($telegramId, $chatId);
    }

    public function removeMessage(Nutgram $bot, int $chatId, int $messageId): void
    {
        try {
            SafeTelegram::call(static fn (): mixed => $bot->deleteMessage(chat_id: $chatId, message_id: $messageId));
        } catch (\Throwable $e) {
            TelegramApiErrors::logTelegramIssue(self::LOG_PREFIX . ' delete', $e);
            SafeTelegram::editMessageReplyMarkup($bot, $chatId, $messageId, InlineKeyboardMarkup::make());
        }
    }
}