<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\MaterialType;
use App\Domain\VoicePart;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Text\TextRepository;

final class MaterialsService
{
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
        if (!MaterialType::isSingleton($type)) {
            return false;
        }

        return $this->materials->findCurrentSingleton($songId, $type) !== null;
    }

    /**
     * @param array<string, mixed> $uploader
     * @param list<string>|null $voiceParts
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
    ): array {
        $song = $this->songs->findById($songId);
        if ($song === null) {
            throw new \InvalidArgumentException('Song not found');
        }

        $existing = null;
        $savedVoiceParts = [];

        if (MaterialType::isSingleton($type)) {
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
    public function recordingCaption(array $material): string
    {
        $parts = $material['voice_parts'] ?? $this->materials->listVoiceParts((int) ($material['id'] ?? 0));

        return $this->texts->get('songs.recording_caption', [
            'parts' => $this->formatVoicePartsLabel(is_array($parts) ? $parts : []),
        ]);
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
