<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\MaterialType;
use App\Domain\VoicePart;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Telegram\TelegramHtml;
use App\Text\TextRepository;

final class MaterialsService
{
    public const TEXT_NOTES_SEPARATOR = "\n\n__________\n\n";

    public function __construct(
        private readonly MaterialRepository $materials,
        private readonly SongRepository $songs,
        private readonly TextRepository $texts,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDuplicate(string $fileUniqueId): ?array
    {
        return $this->materials->findByFileUniqueId($fileUniqueId);
    }

    public function needsReplaceConfirm(int $songId, string $type): bool
    {
        if ($type === MaterialType::NOTES) {
            return $this->materials->countCurrentNotes($songId) > 0;
        }

        if (!MaterialType::isSingleton($type)) {
            return false;
        }

        return $this->materials->findCurrentSingleton($songId, $type) !== null;
    }

    /**
     * @param array<string, mixed> $uploader
     * @param list<string>|null $voiceParts
     * @param 'add'|'replace_all'|null $notesUploadMode
     */
    public function saveUpload(
        array $uploader,
        int $songId,
        string $type,
        ?array $voiceParts,
        string $fileId,
        string $fileUniqueId,
        ?int $adminChatMessageId,
        bool $replaceConfirmed = false,
        ?string $notesUploadMode = null,
    ): array {
        $song = $this->songs->findById($songId);
        if ($song === null) {
            throw new \InvalidArgumentException('Song not found');
        }

        $existing = null;
        $savedVoiceParts = [];

        if ($type === MaterialType::NOTES) {
            $notesCount = $this->materials->countCurrentNotes($songId);
            if ($notesCount > 0) {
                if ($notesUploadMode === null) {
                    throw new \InvalidArgumentException('notes_choice_required');
                }
                if ($notesUploadMode === 'replace_all') {
                    $existing = $this->materials->listCurrentNotes($songId)[0] ?? [];
                    $this->materials->archiveAllCurrentByType($songId, MaterialType::NOTES);
                }
            }

            $materialId = $this->materials->insert(
                $songId,
                $type,
                null,
                $fileId,
                $fileUniqueId,
                (int) $uploader['id'],
                $adminChatMessageId,
                true,
            );
        } elseif (MaterialType::isSingleton($type)) {
            $existing = $this->materials->findCurrentSingleton($songId, $type);
            if ($existing !== null) {
                if (!$replaceConfirmed) {
                    throw new \InvalidArgumentException('replace_confirm_required');
                }
                $this->materials->archiveSingleton($songId, $type);
            }

            $materialId = $this->materials->insert(
                $songId,
                $type,
                null,
                $fileId,
                $fileUniqueId,
                (int) $uploader['id'],
                $adminChatMessageId,
                true,
            );
        } else {
            $savedVoiceParts = $this->normalizeVoiceParts($voiceParts);
            if ($savedVoiceParts === []) {
                throw new \InvalidArgumentException('voice_part_required');
            }

            $materialId = $this->materials->insertRecording(
                $songId,
                $savedVoiceParts,
                $fileId,
                $fileUniqueId,
                (int) $uploader['id'],
                $adminChatMessageId,
            );
        }

        return [
            'material_id' => $materialId,
            'song' => $song,
            'type' => $type,
            'voice_parts' => $savedVoiceParts,
            'archived_previous' => $existing,
        ];
    }

    /**
     * @param array<string, mixed> $uploader
     * @param 'append_end'|'append_start'|'replace'|null $textNotesMode
     */
    public function saveTextUpload(
        array $uploader,
        int $songId,
        string $type,
        string $textContent,
        ?int $adminChatMessageId,
        bool $replaceConfirmed = false,
        ?string $textNotesMode = null,
    ): array {
        if ($type !== MaterialType::TRANSLIT) {
            throw new \InvalidArgumentException('text_upload_type');
        }

        $textContent = str_replace(["\r\n", "\r"], "\n", $textContent);
        if (trim($textContent) === '') {
            throw new \InvalidArgumentException('text_content_empty');
        }

        $song = $this->songs->findById($songId);
        if ($song === null) {
            throw new \InvalidArgumentException('Song not found');
        }

        $existing = null;
        $textNotesAction = 'created';
        $row = $this->materials->findCurrentSingleton($songId, $type);
        if ($row !== null) {
            $existing = $row;
            $oldText = trim((string) ($row['text_content'] ?? ''));
            $hasLegacyFile = trim((string) ($row['telegram_file_id'] ?? '')) !== '';

            if ($oldText === '' && $hasLegacyFile) {
                $mode = $textNotesMode ?? ($replaceConfirmed ? 'replace' : null);
                if ($mode !== 'replace') {
                    throw new \InvalidArgumentException('text_notes_file_only');
                }
                $this->materials->archiveSingleton($songId, $type);
                $textNotesAction = 'replace';
            } elseif ($oldText !== '') {
                $mode = $textNotesMode ?? ($replaceConfirmed ? 'replace' : null);
                if ($mode === null) {
                    throw new \InvalidArgumentException('text_notes_choice_required');
                }

                $textNotesAction = $mode;
                $textContent = match ($mode) {
                    'append_end' => $oldText . self::TEXT_NOTES_SEPARATOR . $textContent,
                    'append_start' => $textContent . self::TEXT_NOTES_SEPARATOR . $oldText,
                    'replace' => $textContent,
                    default => throw new \InvalidArgumentException('invalid_text_notes_mode'),
                };
                $this->materials->archiveSingleton($songId, $type);
            } else {
                $this->materials->archiveSingleton($songId, $type);
            }
        }

        $materialId = $this->materials->insert(
            $songId,
            $type,
            null,
            null,
            null,
            (int) $uploader['id'],
            $adminChatMessageId,
            true,
            $textContent,
        );

        return [
            'material_id' => $materialId,
            'song' => $song,
            'type' => $type,
            'voice_parts' => [],
            'archived_previous' => $existing,
            'text_notes_action' => $textNotesAction,
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    public function textNotesSavedMessage(array $result): string
    {
        $song = (string) ($result['song']['title'] ?? '');
        $action = (string) ($result['text_notes_action'] ?? 'created');

        return match ($action) {
            'append_end' => $this->texts->get('materials.text_notes_saved_append_end', ['song' => $song]),
            'append_start' => $this->texts->get('materials.text_notes_saved_append_start', ['song' => $song]),
            'replace' => $this->texts->get('materials.text_notes_saved_replace', ['song' => $song]),
            default => $this->texts->get('materials.text_notes_saved_new', ['song' => $song]),
        };
    }

    /**
     * @param array<string, mixed> $uploader
     * @param array<string, mixed> $targetMaterial
     */
    public function replaceRecordingFile(
        array $uploader,
        array $targetMaterial,
        string $fileId,
        string $fileUniqueId,
        ?int $adminChatMessageId,
    ): void {
        $parts = $targetMaterial['voice_parts'] ?? $this->materials->listVoiceParts((int) $targetMaterial['id']);
        $this->materials->archiveById((int) $targetMaterial['id']);
        $this->materials->insertRecording(
            (int) $targetMaterial['song_id'],
            is_array($parts) ? $parts : [],
            $fileId,
            $fileUniqueId,
            (int) $uploader['id'],
            $adminChatMessageId,
        );
    }

    /**
     * @param array<string, mixed> $uploader
     * @param array<string, mixed> $targetMaterial
     */
    public function replaceNotesFile(
        array $uploader,
        array $targetMaterial,
        string $fileId,
        string $fileUniqueId,
        ?int $adminChatMessageId,
    ): void {
        $this->materials->archiveById((int) $targetMaterial['id']);
        $this->materials->insert(
            (int) $targetMaterial['song_id'],
            MaterialType::NOTES,
            null,
            $fileId,
            $fileUniqueId,
            (int) $uploader['id'],
            $adminChatMessageId,
            true,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function duplicateCardText(array $row): string
    {
        $type = (string) ($row['type'] ?? '');
        $parts = $row['voice_parts'] ?? $this->materials->listVoiceParts((int) ($row['id'] ?? 0));

        return $this->texts->get('materials.duplicate_card', [
            'song' => (string) ($row['song_title'] ?? ''),
            'type' => $this->texts->get('material_types.' . $type),
            'part' => $this->formatVoicePartsLabel(is_array($parts) ? $parts : []),
            'uploader' => (string) ($row['uploader_name'] ?? ''),
            'uploaded_at' => (string) ($row['uploaded_at'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $song
     * @param list<string> $voiceParts
     */
    public function uploadSuccessText(array $song, string $type, array $voiceParts): string
    {
        $partSuffix = '';
        if ($voiceParts !== []) {
            $partSuffix = ' (' . $this->formatVoicePartsLabel($voiceParts) . ')';
        }

        return $this->texts->get('materials.upload_success', [
            'song' => (string) ($song['title'] ?? ''),
            'type' => $this->texts->get('material_types.' . $type),
            'part_suffix' => $partSuffix,
        ]);
    }

    /**
     * @param array<string, mixed> $material
     */
    public function recordingCaption(string $songTitle, array $material): string
    {
        $parts = $material['voice_parts'] ?? $this->materials->listVoiceParts((int) ($material['id'] ?? 0));
        $partsLabel = $this->formatRecordingPartsLabel(is_array($parts) ? $parts : []);

        return '🎵 <b>' . TelegramHtml::escape($songTitle) . '</b>'
            . "\n"
            . $this->texts->get('songs.recording_caption', [
                'parts' => $partsLabel,
            ]);
    }

    /**
     * @param list<string> $parts
     */
    public function formatRecordingPartsLabel(array $parts): string
    {
        if ($this->isTuttiRecording($parts)) {
            return $this->texts->get('admin_chat.recording_tutti');
        }

        return $this->formatVoicePartsLabel($parts);
    }

    /**
     * @param list<string> $parts
     */
    private function isTuttiRecording(array $parts): bool
    {
        if (count($parts) !== count(VoicePart::CODES)) {
            return false;
        }

        foreach (VoicePart::CODES as $code) {
            if (!in_array($code, $parts, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string>|null $parts
     * @return list<string>
     */
    public function normalizeVoiceParts(?array $parts): array
    {
        if ($parts === null) {
            return [];
        }

        $normalized = [];
        foreach ($parts as $part) {
            $code = (string) $part;
            if (VoicePart::isValid($code) && !in_array($code, $normalized, true)) {
                $normalized[] = $code;
            }
        }

        sort($normalized);

        return $normalized;
    }

    /**
     * @param list<string> $parts
     */
    public function formatVoicePartsLabel(array $parts): string
    {
        if ($parts === []) {
            return '—';
        }

        $labels = array_map(
            fn (string $code): string => $this->texts->get('parts.' . $code),
            $parts,
        );

        return implode(', ', $labels);
    }
}