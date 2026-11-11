<?php

declare(strict_types=1);

namespace App\Tests\Support;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use App\Telegram\TelegramOutbound;

final class RecordingTelegramOutbound implements TelegramOutbound
{
    /** @var list<array{chat_id: int, text: string, message_id: int, has_keyboard: bool}> */
    public array $messages = [];

    /** @var list<array{chat_id: int, message_id: int, text: string}> */
    public array $checkinCloses = [];

    private int $nextMessageId = 1;

    public function sendMessage(int $chatId, string $text, ?InlineKeyboardMarkup $replyMarkup = null): ?int
    {
        $messageId = $this->nextMessageId++;
        $this->messages[] = [
            'chat_id' => $chatId,
            'text' => $text,
            'message_id' => $messageId,
            'has_keyboard' => $replyMarkup !== null,
        ];

        return $messageId;
    }

    public function closeCheckinPrompt(int $chatId, int $messageId, string $questionText, string $closedSuffix): void
    {
        $text = $questionText;
        if (!str_contains($text, $closedSuffix)) {
            $text .= "\n\n" . $closedSuffix;
        }

        $this->checkinCloses[] = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];
    }
}
