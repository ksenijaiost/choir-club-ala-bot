<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\MaterialType;
use App\Domain\VoicePart;

final class MaterialRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByFileUniqueId(string $fileUniqueId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, s.title AS song_title, u.display_name AS uploader_name
             FROM materials m
             INNER JOIN songs s ON s.id = m.song_id
             INNER JOIN users u ON u.id = m.uploaded_by_user_id
             WHERE m.telegram_file_unique_id = ?
             LIMIT 1'
        );
        $stmt->execute([$fileUniqueId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['voice_parts'] = $this->listVoiceParts((int) $row['id']);

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM materials WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['voice_parts'] = $this->listVoiceParts($id);

        return $row;
    }

    /**
     * @return list<string>
     */
    public function listVoiceParts(int $materialId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT voice_part FROM material_voice_parts WHERE material_id = ? ORDER BY voice_part ASC'
        );
        $stmt->execute([$materialId]);
        $parts = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $parts[] = (string) $row['voice_part'];
        }

        return $parts;
    }

    /**
     * @param list<string> $parts
     */
    public function setVoiceParts(int $materialId, array $parts): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM material_voice_parts WHERE material_id = ?');
        $stmt->execute([$materialId]);

        if ($parts === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO material_voice_parts (material_id, voice_part) VALUES (?, ?)'
        );
        foreach ($parts as $part) {
            $insert->execute([$materialId, $part]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCurrentSingleton(int $songId, string $type): ?array
    {
        if (!MaterialType::isSingleton($type)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM materials
             WHERE song_id = ? AND type = ? AND is_current = 1
             LIMIT 1'
        );
        $stmt->execute([$songId, $type]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function archiveSingleton(int $songId, string $type): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE materials SET is_current = 0, archived_at = CURRENT_TIMESTAMP
             WHERE song_id = ? AND type = ? AND is_current = 1'
        );
        $stmt->execute([$songId, $type]);
    }

    public function archiveAllCurrentByType(int $songId, string $type): void
    {
        $this->archiveSingleton($songId, $type);
    }

  /**
   * @return list<array<string, mixed>>
   */
    public function listCurrentNotes(int $songId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM materials
             WHERE song_id = ? AND type = ? AND is_current = 1
             ORDER BY uploaded_at ASC, id ASC'
        );
        $stmt->execute([$songId, MaterialType::NOTES]);

        return $stmt->fetchAll() ?: [];
    }

    public function countCurrentNotes(int $songId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM materials
             WHERE song_id = ? AND type = ? AND is_current = 1'
        );
        $stmt->execute([$songId, MaterialType::NOTES]);

        return (int) $stmt->fetchColumn();
    }

    public function archiveById(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE materials SET is_current = 0, archived_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function setSourceMessageUrl(int $materialId, ?string $url): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE materials SET source_message_url = ? WHERE id = ?'
        );
        $stmt->execute([$url, $materialId]);
    }

    public function insert(
        int $songId,
        string $type,
        ?string $voicePart,
        ?string $fileId,
        ?string $fileUniqueId,
        int $uploadedByUserId,
        ?int $adminChatMessageId,
        bool $isCurrent = true,
        ?string $textContent = null,
    ): int {
        $storedPart = MaterialType::isSingleton($type) ? null : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO materials (
                song_id, type, voice_part, telegram_file_id, telegram_file_unique_id, text_content,
                is_current, uploaded_by_user_id, admin_chat_message_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $songId,
            $type,
            $storedPart,
            $fileId,
            $fileUniqueId,
            $textContent,
            $isCurrent ? 1 : 0,
            $uploadedByUserId,
            $adminChatMessageId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<string> $voiceParts
     */
    public function insertRecording(
        int $songId,
        array $voiceParts,
        string $fileId,
        string $fileUniqueId,
        int $uploadedByUserId,
        ?int $adminChatMessageId,
    ): int {
        $id = $this->insert(
            $songId,
            MaterialType::RECORDING,
            null,
            $fileId,
            $fileUniqueId,
            $uploadedByUserId,
            $adminChatMessageId,
            true,
        );
        $this->setVoiceParts($id, $voiceParts);

        return $id;
    }

    /**
     * @return list<string>
     */
    public function listPartsWithRecordingsForSong(int $songId): array
    {
        return $this->listPartsWithRecordingsForSongOrdered($songId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTuttiRecordings(int $songId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM materials WHERE song_id = ? AND type = ? AND is_current = 1 ORDER BY uploaded_at DESC'
        );
        $stmt->execute([$songId, MaterialType::RECORDING]);
        $rows = $this->attachVoiceParts($stmt->fetchAll() ?: []);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => VoicePart::isTuttiParts(
                is_array($row['voice_parts'] ?? null) ? $row['voice_parts'] : [],
            ),
        ));
    }

    public function countTuttiRecordings(int $songId): int
    {
        return count($this->listTuttiRecordings($songId));
    }

    /**
     * @return list<string>
     */
    public function listPartsWithRecordingsForSongOrdered(int $songId): array
    {
        $codes = [];
        foreach (VoicePart::CODES as $code) {
            if ($this->countRecordingsForPart($songId, $code) > 0) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function countRecordingsForPart(int $songId, string $voicePart): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT m.id) FROM materials m
             INNER JOIN material_voice_parts mvp ON mvp.material_id = m.id
             WHERE m.song_id = ? AND m.type = ? AND mvp.voice_part = ? AND m.is_current = 1'
        );
        $stmt->execute([$songId, MaterialType::RECORDING, $voicePart]);

        return (int) $stmt->fetchColumn();
    }

    public function songHasRecordingsForPart(int $songId, string $voicePart): bool
    {
        return $this->countRecordingsForPart($songId, $voicePart) > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCurrentRecordings(int $songId, string $voicePart): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT m.* FROM materials m
             INNER JOIN material_voice_parts mvp ON mvp.material_id = m.id
             WHERE m.song_id = ? AND m.type = ? AND mvp.voice_part = ? AND m.is_current = 1
             ORDER BY m.uploaded_at DESC'
        );
        $stmt->execute([$songId, MaterialType::RECORDING, $voicePart]);
        $rows = $stmt->fetchAll() ?: [];

        return $this->attachVoiceParts($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSongsWithRecordingsForPart(string $voicePart): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.title, COUNT(DISTINCT m.id) AS recording_count
             FROM materials m
             INNER JOIN material_voice_parts mvp ON mvp.material_id = m.id
             INNER JOIN songs s ON s.id = m.song_id
             WHERE m.type = ? AND mvp.voice_part = ? AND m.is_current = 1
             GROUP BY s.id, s.title
             ORDER BY s.title_normalized ASC'
        );
        $stmt->execute([MaterialType::RECORDING, $voicePart]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSongsWithRecordingsAnyPart(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.title, COUNT(DISTINCT m.id) AS recording_count
             FROM materials m
             INNER JOIN material_voice_parts mvp ON mvp.material_id = m.id
             INNER JOIN songs s ON s.id = m.song_id
             WHERE m.type = ? AND m.is_current = 1
             GROUP BY s.id, s.title
             ORDER BY s.title_normalized ASC'
        );
        $stmt->execute([MaterialType::RECORDING]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCurrentRecordingsAllParts(int $songId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT m.* FROM materials m
             INNER JOIN material_voice_parts mvp ON mvp.material_id = m.id
             WHERE m.song_id = ? AND m.type = ? AND m.is_current = 1
             ORDER BY m.uploaded_at DESC'
        );
        $stmt->execute([$songId, MaterialType::RECORDING]);
        $rows = $stmt->fetchAll() ?: [];

        return $this->attachVoiceParts($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCurrentForSong(int $songId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM materials WHERE song_id = ? AND is_current = 1 ORDER BY type, uploaded_at DESC'
        );
        $stmt->execute([$songId]);

        return $this->attachVoiceParts($stmt->fetchAll() ?: []);
    }

    /**
     * @return array<int, list<array{parts: list<string>, has_link: bool}>>
     */
    public function listCurrentRecordingPartSetsBySong(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.song_id, m.id AS material_id, GROUP_CONCAT(mvp.voice_part) AS parts,
                    CASE WHEN m.source_message_url IS NOT NULL AND TRIM(m.source_message_url) <> \'\' THEN 1 ELSE 0 END AS has_link
             FROM materials m
             INNER JOIN material_voice_parts mvp ON mvp.material_id = m.id
             WHERE m.type = ? AND m.is_current = 1
             GROUP BY m.id
             ORDER BY m.song_id ASC, m.uploaded_at ASC'
        );
        $stmt->execute([MaterialType::RECORDING]);

        $map = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $songId = (int) $row['song_id'];
            $rawParts = (string) ($row['parts'] ?? '');
            $parts = $rawParts === ''
                ? []
                : array_values(array_unique(explode(',', $rawParts)));
            sort($parts);
            if ($parts === []) {
                continue;
            }

            $map[$songId] ??= [];
            $map[$songId][] = [
                'parts' => $parts,
                'has_link' => ((int) ($row['has_link'] ?? 0)) === 1,
            ];
        }

        return $map;
    }

    private function attachVoiceParts(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $rows[$i]['voice_parts'] = $this->listVoiceParts((int) $row['id']);
        }

        return $rows;
    }
}
