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

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM materials WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
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
        $stmt = $this->pdo->prepare(
            'INSERT INTO materials (
                song_id, type, voice_part, telegram_file_id, telegram_file_unique_id,
                is_current, uploaded_by_user_id, admin_chat_message_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $songId,
            $type,
            $voicePart,
            $fileId,
            $fileUniqueId,
            $isCurrent ? 1 : 0,
            $uploadedByUserId,
            $adminChatMessageId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function replaceFile(
        int $materialId,
        string $fileId,
        string $fileUniqueId,
        int $uploadedByUserId,
        ?int $adminChatMessageId,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE materials SET
                telegram_file_id = ?,
                telegram_file_unique_id = ?,
                uploaded_by_user_id = ?,
                uploaded_at = CURRENT_TIMESTAMP,
                admin_chat_message_id = ?,
                is_current = 1,
                archived_at = NULL
             WHERE id = ?'
        );
        $stmt->execute([$fileId, $fileUniqueId, $uploadedByUserId, $adminChatMessageId, $materialId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCurrentRecordings(int $songId, string $voicePart): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM materials
             WHERE song_id = ? AND type = ? AND voice_part = ? AND is_current = 1
             ORDER BY uploaded_at DESC'
        );
        $stmt->execute([$songId, MaterialType::RECORDING, $voicePart]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCurrentForSong(int $songId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM materials WHERE song_id = ? AND is_current = 1 ORDER BY type, voice_part, uploaded_at DESC'
        );
        $stmt->execute([$songId]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSongsWithRecordingsForPart(string $voicePart): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.title, COUNT(m.id) AS recording_count
             FROM materials m
             INNER JOIN songs s ON s.id = m.song_id
             WHERE m.type = ? AND m.voice_part = ? AND m.is_current = 1
             GROUP BY s.id, s.title
             ORDER BY s.title_normalized ASC'
        );
        $stmt->execute([MaterialType::RECORDING, $voicePart]);

        return $stmt->fetchAll() ?: [];
    }
}
