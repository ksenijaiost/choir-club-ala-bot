<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\MaterialType;
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
     */
    public function saveUpload(
        array $uploader,
        int $songId,
        string $type,
        ?string $voicePart,
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

        if (MaterialType::isSingleton($type)) {
            $voicePart = null;
            $existing = $this->materials->findCurrentSingleton($songId, $type);
            if ($existing !== null) {
                if (!$replaceConfirmed) {
                    throw new \InvalidArgumentException('replace_confirm_required');
                }
                $this->materials->archiveSingleton($songId, $type);
            }
        } else {
            if ($voicePart === null || $voicePart === '') {
                throw new \InvalidArgumentException('voice_part_required');
            }
        }

        $materialId = $this->materials->insert(
            $songId,
            $type,
            $voicePart,
            $fileId,
            $fileUniqueId,
            (int) $uploader['id'],
            $adminChatMessageId,
            true,
        );

        return [
            'material_id' => $materialId,
            'song' => $song,
            'type' => $type,
            'voice_part' => $voicePart,
            'archived_previous' => $existing ?? null,
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
        $this->materials->archiveById((int) $targetMaterial['id']);
        $this->materials->insert(
            (int) $targetMaterial['song_id'],
            MaterialType::RECORDING,
            (string) $targetMaterial['voice_part'],
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
        $partCode = (string) ($row['voice_part'] ?? '');
        $part = $partCode !== '' ? $this->texts->get('parts.' . $partCode) : '—';

        return $this->texts->get('materials.duplicate_card', [
            'song' => (string) ($row['song_title'] ?? ''),
            'type' => $this->texts->get('material_types.' . $type),
            'part' => $part,
            'uploader' => (string) ($row['uploader_name'] ?? ''),
            'uploaded_at' => (string) ($row['uploaded_at'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $song
     */
    public function uploadSuccessText(array $song, string $type, ?string $voicePart): string
    {
        $partSuffix = '';
        if ($voicePart !== null && $voicePart !== '') {
            $partSuffix = ' (' . $this->texts->get('parts.' . $voicePart) . ')';
        }

        return $this->texts->get('materials.upload_success', [
            'song' => (string) ($song['title'] ?? ''),
            'type' => $this->texts->get('material_types.' . $type),
            'part_suffix' => $partSuffix,
        ]);
    }
}
