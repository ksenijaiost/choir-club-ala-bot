<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\MaterialType;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Telegram\InlineMenuHelper;
use App\Telegram\TelegramHtml;
use App\Telegram\TelegramMessageChunker;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaDocument;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class SongDeliveryService
{
    private const MEDIA_GROUP_SIZE = 10;

    public function __construct(
        private readonly MaterialRepository $materials,
        private readonly SongRepository $songs,
        private readonly TextRepository $texts,
        private readonly MaterialsService $materialsService,
    ) {
    }

    /**
     * @param array<string, mixed> $song
     * @param array<string, mixed>|null $viewer
     */
    public function sendSongCard(Nutgram $bot, array $song, ?array $viewer = null): void
    {
        $title = (string) ($song['title'] ?? '');
        $songId = (int) $song['id'];

        $voicePart = null;
        $anyRecordings = true;
        if ($viewer !== null) {
            $anyRecordings = VoicePartRules::seesAnyRecordingsOnSongMenu($viewer);
            if (VoicePartRules::hasAssignedVoicePart($viewer)) {
                $voicePart = (string) $viewer['voice_part'];
            }
        }

        $summary = $this->songs->materialsSummary($songId, $voicePart);
        $translitCard = $this->resolveTranslitCardState($songId, $summary);
        $keyboard = $this->buildSongCardKeyboard($songId, $summary, $anyRecordings, $translitCard);
        $text = '🎵 ' . TelegramHtml::escape($title);
        $parseMode = ParseMode::HTML;
        if ($translitCard['preview'] !== null && $translitCard['preview'] !== '') {
            $text .= "\n\n" . TelegramHtml::escape($translitCard['preview']);
        }
        if ($this->songCardHasNoMaterials($summary, $anyRecordings)) {
            $text .= "\n\n" . TelegramHtml::escape($this->texts->get('songs.card_no_materials'));
        }

        $bot->sendMessage($text, reply_markup: $keyboard, parse_mode: $parseMode);
    }

    /**
     * @param array{
     *     notes_count: int,
     *     has_translit: bool,
     *     recordings_total: int,
     *     recordings_for_part: int
     * } $summary
     * @param array{preview: ?string, show_file_button: bool, show_full_button: bool} $translitCard
     */
    public function buildSongCardKeyboard(int $songId, array $summary, bool $anyRecordings, ?array $translitCard = null): InlineKeyboardMarkup
    {
        if ($translitCard === null) {
            $translitCard = $this->resolveTranslitCardState($songId, $summary);
        }

        $showNotes = $summary['notes_count'] > 0;
        $showRecordings = $anyRecordings
            ? $summary['recordings_total'] > 0
            : $summary['recordings_for_part'] > 0;

        $keyboard = InlineKeyboardMarkup::make();
        $row = [];
        if ($showNotes) {
            $row[] = InlineKeyboardButton::make(
                $this->texts->get('buttons.notes'),
                callback_data: 'snd:nt:' . $songId,
            );
        }
        if ($translitCard['show_file_button']) {
            $row[] = InlineKeyboardButton::make(
                $this->texts->get('buttons.translit'),
                callback_data: 'snd:tr:' . $songId,
            );
        }
        if ($row !== []) {
            $keyboard = $keyboard->addRow(...$row);
        }
        if ($translitCard['show_full_button']) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $this->texts->get('buttons.translit_full'),
                    callback_data: 'snd:tr:' . $songId,
                ),
            );
        }
        if ($showRecordings) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $this->texts->get('buttons.party_recordings'),
                    callback_data: 'snd:pr:' . $songId,
                ),
            );
        }

        return InlineMenuHelper::appendFooter($keyboard, $this->texts);
    }

    /**
     * @param array{
     *     notes_count: int,
     *     has_translit: bool,
     *     recordings_total: int,
     *     recordings_for_part: int
     * } $summary
     *
     * @return array{preview: ?string, show_file_button: bool, show_full_button: bool}
     */
    private function resolveTranslitCardState(int $songId, array $summary): array
    {
        if (!$summary['has_translit']) {
            return [
                'preview' => null,
                'show_file_button' => false,
                'show_full_button' => false,
            ];
        }

        $row = $this->materials->findCurrentSingleton($songId, MaterialType::TRANSLIT);
        if ($row === null) {
            return [
                'preview' => null,
                'show_file_button' => false,
                'show_full_button' => false,
            ];
        }

        $textContent = trim((string) ($row['text_content'] ?? ''));
        $fileId = trim((string) ($row['telegram_file_id'] ?? ''));
        if ($textContent === '') {
            return [
                'preview' => null,
                'show_file_button' => $fileId !== '',
                'show_full_button' => false,
            ];
        }

        $preview = SongTranslitPreview::build($textContent);

        return [
            'preview' => $preview['text'],
            'show_file_button' => false,
            'show_full_button' => !$preview['complete'],
        ];
    }

    /**
     * @param array{
     *     notes_count: int,
     *     has_translit: bool,
     *     recordings_total: int,
     *     recordings_for_part: int
     * } $summary
     */
    private function songCardHasNoMaterials(array $summary, bool $anyRecordings): bool
    {
        $showRecordings = $anyRecordings
            ? $summary['recordings_total'] > 0
            : $summary['recordings_for_part'] > 0;

        return $summary['notes_count'] === 0
            && !$summary['has_translit']
            && !$showRecordings;
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