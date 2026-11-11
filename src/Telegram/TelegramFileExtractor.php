<?php

declare(strict_types=1);

namespace App\Telegram;

use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class TelegramFileExtractor
{
    /**
     * @return array{file_id: string, file_unique_id: string}|null
     */
    public static function fromMessage(Message $message): ?array
    {
        if ($message->document !== null) {
            return [
                'file_id' => $message->document->file_id,
                'file_unique_id' => $message->document->file_unique_id,
            ];
        }

        if ($message->audio !== null) {
            return [
                'file_id' => $message->audio->file_id,
                'file_unique_id' => $message->audio->file_unique_id,
            ];
        }

        if ($message->video !== null) {
            return [
                'file_id' => $message->video->file_id,
                'file_unique_id' => $message->video->file_unique_id,
            ];
        }

        if ($message->voice !== null) {
            return [
                'file_id' => $message->voice->file_id,
                'file_unique_id' => $message->voice->file_unique_id,
            ];
        }

        if ($message->video_note !== null) {
            return [
                'file_id' => $message->video_note->file_id,
                'file_unique_id' => $message->video_note->file_unique_id,
            ];
        }

        return null;
    }
}
