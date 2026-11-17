<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\MaterialType;
use App\Domain\VoicePart;
use App\Support\SqlPagination;

final class SongRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public static function normalizeTitle(string $title): string
    {
        return mb_strtolower(trim($title), 'UTF-8');
    }

    public function create(string $title): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO songs (title, title_normalized) VALUES (?, ?)'
        );
        $stmt->execute([trim($title), self::normalizeTitle($title)]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNormalizedTitle(string $title, ?int $excludeSongId = null): ?array
    {
        $normalized = self::normalizeTitle($title);
        if ($excludeSongId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM songs WHERE title_normalized = ? AND id != ? LIMIT 1'
            );
            $stmt->execute([$normalized, $excludeSongId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM songs WHERE title_normalized = ? LIMIT 1');
            $stmt->execute([$normalized]);
        }

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return 'ok'|'duplicate'|'not_found'
     */
    public function rename(int $songId, string $newTitle): string
    {
        $song = $this->findById($songId);
        if ($song === null) {
            return 'not_found';
        }

        $trimmed = trim($newTitle);
        if ($this->findByNormalizedTitle($trimmed, $songId) !== null) {
            return 'duplicate';
        }

        $stmt = $this->pdo->prepare(
            'UPDATE songs SET title = ?, title_normalized = ? WHERE id = ?'
        );
        $stmt->execute([$trimmed, self::normalizeTitle($trimmed), $songId]);

        return 'ok';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM songs WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 25): array
    {
        return $this->listAlphabetical(0, SqlPagination::clampLimit($limit));
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM songs');

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAlphabetical(int $offset, int $limit): array
    {
        $limit = SqlPagination::clampLimit($limit);
        $offset = SqlPagination::clampOffset($offset);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM songs ORDER BY title_normalized ASC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchByTitleFragment(string $query, int $limit = 10): array
    {
        $limit = SqlPagination::clampLimit($limit);
        $norm = '%' . self::normalizeTitle($query) . '%';
        $stmt = $this->pdo->prepare(
            'SELECT * FROM songs WHERE title_normalized LIKE ? ORDER BY title_normalized ASC LIMIT ?'
        );
        $stmt->bindValue(1, $norm);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function countWithCurrentMaterials(): int
    {
        $stmt = $this->pdo->query(
            'SELECT COUNT(DISTINCT s.id) FROM songs s
             INNER JOIN materials m ON m.song_id = s.id AND m.is_current = 1'
        );

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWithCurrentMaterials(int $offset, int $limit): array
    {
        $limit = SqlPagination::clampLimit($limit);
        $offset = SqlPagination::clampOffset($offset);
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT s.* FROM songs s
             INNER JOIN materials m ON m.song_id = s.id AND m.is_current = 1
             ORDER BY s.title_normalized ASC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array{id: int, title: string, notes_count: int, has_translit: int}>
     */
    public function listAllWithCurrentSingletonFlags(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.title,
                (SELECT COUNT(*) FROM materials m
                    WHERE m.song_id = s.id AND m.type = ? AND m.is_current = 1) AS notes_count,
                EXISTS(
                    SELECT 1 FROM materials m
                    WHERE m.song_id = s.id AND m.type = ? AND m.is_current = 1
                      AND (
                        (m.text_content IS NOT NULL AND TRIM(m.text_content) <> \'\')
                        OR (m.telegram_file_id IS NOT NULL AND TRIM(m.telegram_file_id) <> \'\')
                      )
                ) AS has_translit
             FROM songs s
             ORDER BY s.title_normalized ASC'
        );
        $stmt->execute([MaterialType::NOTES, MaterialType::TRANSLIT]);

        $rows = $stmt->fetchAll() ?: [];

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'notes_count' => (int) $row['notes_count'],
                'has_translit' => (int) $row['has_translit'],
            ];
        }, $rows);
    }

    /**
     * @return array{
     *     notes_count: int,
     *     has_translit: bool,
     *     recordings_total: int,
     *     recordings_for_part: int
     * }
     */
    public function materialsSummary(int $songId, ?string $voicePart = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM materials m
                    WHERE m.song_id = ? AND m.type = ? AND m.is_current = 1) AS notes_count,
                EXISTS(
                    SELECT 1 FROM materials m
                    WHERE m.song_id = ? AND m.type = ? AND m.is_current = 1
                      AND (
                        (m.text_content IS NOT NULL AND TRIM(m.text_content) <> \'\')
                        OR (m.telegram_file_id IS NOT NULL AND TRIM(m.telegram_file_id) <> \'\')
                      )
                ) AS has_translit,
                (
                    SELECT COUNT(DISTINCT m.id) FROM materials m
                    WHERE m.song_id = ? AND m.type = ? AND m.is_current = 1
                ) AS recordings_total'
        );
        $stmt->execute([
            $songId,
            MaterialType::NOTES,
            $songId,
            MaterialType::TRANSLIT,
            $songId,
            MaterialType::RECORDING,
        ]);
        $row = $stmt->fetch();
        if ($row === false) {
            return [
                'notes_count' => 0,
                'has_translit' => false,
                'recordings_total' => 0,
                'recordings_for_part' => 0,
            ];
        }

        $recordingsForPart = 0;
        if ($voicePart !== null && $voicePart !== '' && VoicePart::isValid($voicePart)) {
            $tuttiPartCount = count(VoicePart::CODES);
            $partStmt = $this->pdo->prepare(
                'SELECT COUNT(DISTINCT m.id) FROM materials m
                 WHERE m.song_id = ? AND m.type = ? AND m.is_current = 1
                   AND (
                     EXISTS (
                         SELECT 1 FROM material_voice_parts mvp
                         WHERE mvp.material_id = m.id AND mvp.voice_part = ?
                     )
                     OR (
                         (SELECT COUNT(DISTINCT mvp2.voice_part)
                          FROM material_voice_parts mvp2
                          WHERE mvp2.material_id = m.id) >= ?
                     )
                   )'
            );
            $partStmt->execute([$songId, MaterialType::RECORDING, $voicePart, $tuttiPartCount]);
            $recordingsForPart = (int) $partStmt->fetchColumn();
        }

        return [
            'notes_count' => (int) $row['notes_count'],
            'has_translit' => (bool) $row['has_translit'],
            'recordings_total' => (int) $row['recordings_total'],
            'recordings_for_part' => $recordingsForPart,
        ];
    }
}
