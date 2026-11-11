<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\MaterialType;

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

    public function archiveById(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE materials SET is_current = 0, archived_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function insert(
        int $songId,
        string $type,
        ?string $voicePart,
        string $fileId,
        string $fileUniqueId,
        int $uploadedByUserId,
        ?int $adminChatMessageId,
        bool $isCurrent = true,
    ): int {
        $storedPart = MaterialType::isSingleton($type) ? null : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO materials (
                song_id, type, voice_part, telegram_file_id, telegram_file_unique_id,
                is_current, uploaded_by_user_id, admin_chat_message_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $songId,
            $type,
            $storedPart,
            $fileId,
            $fileUniqueId,
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
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT mvp.voice_part FROM material_voice_parts mvp
             INNER JOIN materials m ON m.id = mvp.material_id
             WHERE m.song_id = ? AND m.type = ? AND m.is_current = 1
             ORDER BY mvp.voice_part ASC'
        );
        $stmt->execute([$songId, MaterialType::RECORDING]);
        $parts = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $parts[] = (string) $row['voice_part'];
        }

        return $parts;
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
    public function listCurrentForSong(int $songId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM materials WHERE song_id = ? AND is_current = 1 ORDER BY type, uploaded_at DESC'
        );
        $stmt->execute([$songId]);

        return $this->attachVoiceParts($stmt->fetchAll() ?: []);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachVoiceParts(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $rows[$i]['voice_parts'] = $this->listVoiceParts((int) $row['id']);
        }

        return $rows;
    }
}
