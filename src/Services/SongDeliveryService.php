<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\MaterialType;
use App\Repositories\MaterialRepository;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class SongDeliveryService
{
    public function __construct(
        private readonly MaterialRepository $materials,
        private readonly TextRepository $texts,
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

        $bot->sendMessage(
            $this->texts->get('songs.card_header', ['title' => $title]),
            reply_markup: $keyboard,
        );
    }

    public function sendSingletonFile(Nutgram $bot, int $songId, string $type): void
    {
        $row = $this->materials->findCurrentSingleton($songId, $type);
        if ($row === null) {
            $bot->sendMessage($this->texts->get('songs.no_material'));

            return;
        }

        $this->sendMaterialFile($bot, $row);
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
            $bot->sendAudio(audio: $fileId);

            return;
        }

        $bot->sendDocument(document: $fileId);
    }
}
