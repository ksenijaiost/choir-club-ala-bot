<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\MaterialType;
use App\Repositories\MaterialRepository;
use App\Telegram\InlineMenuHelper;
use App\Telegram\TelegramMessageChunker;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaDocument;

final class SongDeliveryService
{
    private const MEDIA_GROUP_SIZE = 10;

    public function __construct(
        private readonly MaterialRepository $materials,
        private readonly TextRepository $texts,
        private readonly MaterialsService $materialsService,
    ) {
    }

    /**
     * @param array<string, mixed> $song
     */
    public function sendSongCard(Nutgram $bot, array $song): void
    {
        $title = (string) ($song['title'] ?? '');
        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    $this->texts->get('buttons.notes'),
                    callback_data: 'snd:nt:' . (int) $song['id'],
                ),
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    $this->texts->get('buttons.translit'),
                    callback_data: 'snd:tr:' . (int) $song['id'],
                ),
            )
            ->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    $this->texts->get('buttons.party_recordings'),
                    callback_data: 'snd:pr:' . (int) $song['id'],
                ),
            );

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $this->texts);

        $bot->sendMessage(
            $this->texts->get('songs.card_header', ['title' => $title]),
            reply_markup: $keyboard,
        );
    }

    public function sendSingletonFile(Nutgram $bot, int $songId, string $type): void
    {
        if ($type === MaterialType::NOTES) {
            $this->sendNotes($bot, $songId);

            return;
        }

        $row = $this->materials->findCurrentSingleton($songId, $type);
        if ($row === null) {
            $bot->sendMessage($this->texts->get('songs.no_material'));

            return;
        }

        $textContent = (string) ($row['text_content'] ?? '');
        if ($type === MaterialType::TRANSLIT && $textContent !== '') {
            $this->sendTextChunks($bot, $textContent);

            return;
        }

        $this->sendMaterialFile($bot, $row);
    }

    public function sendNotes(Nutgram $bot, int $songId, ?int $chatId = null): void
    {
        $rows = $this->materials->listCurrentNotes($songId);
        if ($rows === []) {
            if ($chatId !== null) {
                $bot->sendMessage($this->texts->get('songs.no_material'), chat_id: $chatId);
            } else {
                $bot->sendMessage($this->texts->get('songs.no_material'));
            }

            return;
        }

        $this->sendNoteFiles($bot, $rows, $chatId);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function sendNoteFiles(Nutgram $bot, array $rows, ?int $chatId = null): void
    {
        $fileIds = [];
        foreach ($rows as $row) {
            $fileId = trim((string) ($row['telegram_file_id'] ?? ''));
            if ($fileId !== '') {
                $fileIds[] = $fileId;
            }
        }

        if ($fileIds === []) {
            if ($chatId !== null) {
                $bot->sendMessage($this->texts->get('songs.no_material'), chat_id: $chatId);
            } else {
                $bot->sendMessage($this->texts->get('songs.no_material'));
            }

            return;
        }

        if (count($fileIds) === 1) {
            if ($chatId !== null) {
                $bot->sendDocument(document: $fileIds[0], chat_id: $chatId);
            } else {
                $bot->sendDocument(document: $fileIds[0]);
            }

            return;
        }

        $offset = 0;
        $total = count($fileIds);
        while ($offset < $total) {
            $chunk = array_slice($fileIds, $offset, self::MEDIA_GROUP_SIZE);
            $offset += count($chunk);

            if (count($chunk) === 1 && $offset >= $total) {
                if ($chatId !== null) {
                    $bot->sendDocument(document: $chunk[0], chat_id: $chatId);
                } else {
                    $bot->sendDocument(document: $chunk[0]);
                }

                continue;
            }

            if (count($chunk) >= 2 && count($chunk) <= self::MEDIA_GROUP_SIZE) {
                $media = array_map(
                    static fn (string $id): InputMediaDocument => InputMediaDocument::make(media: $id),
                    $chunk,
                );
                if ($chatId !== null) {
                    $bot->sendMediaGroup(media: $media, chat_id: $chatId);
                } else {
                    $bot->sendMediaGroup(media: $media);
                }

                continue;
            }

            foreach ($chunk as $fileId) {
                if ($chatId !== null) {
                    $bot->sendDocument(document: $fileId, chat_id: $chatId);
                } else {
                    $bot->sendDocument(document: $fileId);
                }
            }
        }
    }

    public function sendTextChunks(Nutgram $bot, string $text, ?int $chatId = null): void
    {
        $chunks = TelegramMessageChunker::splitByLines($text);
        if ($chunks === []) {
            $bot->sendMessage($this->texts->get('songs.no_material'), chat_id: $chatId);

            return;
        }

        foreach ($chunks as $chunk) {
            if ($chatId !== null) {
                $bot->sendMessage($chunk, chat_id: $chatId);
            } else {
                $bot->sendMessage($chunk);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function sendRecordingFiles(Nutgram $bot, array $rows): void
    {
        if ($rows === []) {
            $bot->sendMessage($this->texts->get('songs.no_material'));

            return;
        }

        foreach ($rows as $row) {
            $this->sendMaterialFile($bot, $row);
        }
    }

    /**
     * @param array<string, mixed> $material
     */
    private function sendMaterialFile(Nutgram $bot, array $material): void
    {
        $fileId = (string) ($material['telegram_file_id'] ?? '');
        if ($fileId === '') {
            return;
        }

        $type = (string) ($material['type'] ?? '');
        if ($type === MaterialType::RECORDING) {
            $caption = $this->materialsService->recordingCaption($material);
            $bot->sendAudio(audio: $fileId, caption: $caption !== '' ? $caption : null);

            return;
        }

        $bot->sendDocument(document: $fileId);
    }
}