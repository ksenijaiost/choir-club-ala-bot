<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Telegram\TelegramOutbound;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class ThrowingRecordingTelegramOutbound implements TelegramOutbound
{
    private readonly RecordingTelegramOutbound $inner;

    public function __construct(
        private readonly int $failChatId,
        private readonly string $message = 'Forbidden: bot was blocked by the user',
        private readonly int $code = 403,
    ) {
        $this->inner = new RecordingTelegramOutbound();
    }

    /**
     * @return list<array{chat_id: int, text: string, message_id: int, has_keyboard: bool}>
     */
    public function recordedMessages(): array
    {
        return $this->inner->messages;
    }

    public function sendMessage(int $chatId, string $text, ?InlineKeyboardMarkup $replyMarkup = null): ?int
    {
        if ($chatId === $this->failChatId) {
            throw new TelegramException($this->message, $this->code);
        }

        return $this->inner->sendMessage($chatId, $text, $replyMarkup);
    }

    public function closeCheckinPrompt(int $chatId, int $messageId, string $questionText, string $closedSuffix): void
    {
        $this->inner->closeCheckinPrompt($chatId, $messageId, $questionText, $closedSuffix);
    }
}