<?php

declare(strict_types=1);

namespace App\Repositories;

final class ProgramRepository
{
    public const TITLE_MAX = 100;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM programs WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(string $title, int $createdBy, ?string $eventDateIso): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO programs (title, event_date, created_by) VALUES (?, ?, ?)'
        );
        $stmt->execute([mb_substr(trim($title), 0, self::TITLE_MAX), $eventDateIso, $createdBy]);

        return (int) $this->pdo->lastInsertId();
    }

    public function rename(int $id, string $title): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE programs SET title = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([mb_substr(trim($title), 0, self::TITLE_MAX), $id]);
        $this->markChanged($id);
    }

    public function setDate(int $id, ?string $eventDateIso): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE programs SET event_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$eventDateIso, $id]);
        $this->markChanged($id);
    }

    public function archive(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE programs SET is_archived = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function unarchive(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE programs SET is_archived = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM programs WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(): array
    {
        $stmt = $this->pdo->query(
            'SELECT p.*, (SELECT COUNT(*) FROM program_songs ps WHERE ps.program_id = p.id) AS song_count
             FROM programs p
             WHERE p.is_archived = 0
             ORDER BY p.event_date IS NULL, p.event_date ASC, p.updated_at DESC'
        );

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listArchived(): array
    {
        $stmt = $this->pdo->query(
            'SELECT p.*, (SELECT COUNT(*) FROM program_songs ps WHERE ps.program_id = p.id) AS song_count
             FROM programs p
             WHERE p.is_archived = 1
             ORDER BY p.event_date DESC, p.updated_at DESC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Активные для участника: не в архиве и дата не прошла.
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveForMembers(string $todayIso): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, (SELECT COUNT(*) FROM program_songs ps WHERE ps.program_id = p.id) AS song_count
             FROM programs p
             WHERE p.is_archived = 0
               AND (p.event_date IS NULL OR p.event_date >= ?)
             ORDER BY p.event_date IS NULL, p.event_date ASC, p.updated_at DESC'
        );
        $stmt->execute([$todayIso]);

        return $stmt->fetchAll();
    }

    public function countActiveForMembers(string $todayIso): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM programs p
             WHERE p.is_archived = 0
               AND (p.event_date IS NULL OR p.event_date >= ?)'
        );
        $stmt->execute([$todayIso]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSongs(int $programId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ps.song_id, ps.position, s.title
             FROM program_songs ps
             INNER JOIN songs s ON s.id = ps.song_id
             WHERE ps.program_id = ?
             ORDER BY ps.position ASC'
        );
        $stmt->execute([$programId]);

        return $stmt->fetchAll();
    }

    public function songCount(int $programId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM program_songs WHERE program_id = ?');
        $stmt->execute([$programId]);

        return (int) $stmt->fetchColumn();
    }

    public function isSongInProgram(int $programId, int $songId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM program_songs WHERE program_id = ? AND song_id = ? LIMIT 1'
        );
        $stmt->execute([$programId, $songId]);

        return $stmt->fetchColumn() !== false;
    }

      public function toggleSong(int $programId, int $songId): bool
    {
        if ($this->isSongInProgram($programId, $songId)) {
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM program_songs WHERE program_id = ? AND song_id = ?'
                );
                $stmt->execute([$programId, $songId]);
                $this->reindexPositions($programId);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();

                throw $e;
            }
            $this->markChanged($programId);

            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM program_songs WHERE program_id = ?'
        );
        $stmt->execute([$programId]);
        $next = (int) $stmt->fetchColumn();

        $insert = $this->pdo->prepare(
            'INSERT INTO program_songs (program_id, song_id, position) VALUES (?, ?, ?)'
        );
        $insert->execute([$programId, $songId, $next]);
        $this->markChanged($programId);

        return true;
    }

    public function removeSong(int $programId, int $songId): void
    {
        if (!$this->isSongInProgram($programId, $songId)) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM program_songs WHERE program_id = ? AND song_id = ?'
            );
            $stmt->execute([$programId, $songId]);
            $this->reindexPositions($programId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
        $this->markChanged($programId);
    }

    public function moveUp(int $programId, int $songId): void
    {
        $this->swapWithNeighbor($programId, $songId, -1);
    }

    public function moveDown(int $programId, int $songId): void
    {
        $this->swapWithNeighbor($programId, $songId, 1);
    }

    private function swapWithNeighbor(int $programId, int $songId, int $direction): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT position FROM program_songs WHERE program_id = ? AND song_id = ?'
        );
        $stmt->execute([$programId, $songId]);
        $pos = $stmt->fetchColumn();
        if ($pos === false) {
            return;
        }
        $position = (int) $pos;
        $neighborPos = $position + $direction;

        $neighbor = $this->pdo->prepare(
            'SELECT song_id FROM program_songs WHERE program_id = ? AND position = ?'
        );
        $neighbor->execute([$programId, $neighborPos]);
        $neighborSongId = $neighbor->fetchColumn();
        if ($neighborSongId === false) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $tmp = $this->pdo->prepare(
                'UPDATE program_songs SET position = 0 WHERE program_id = ? AND song_id = ?'
            );
            $tmp->execute([$programId, $songId]);
            $upd = $this->pdo->prepare(
                'UPDATE program_songs SET position = ? WHERE program_id = ? AND song_id = ?'
            );
            $upd->execute([$position, $programId, (int) $neighborSongId]);
            $upd->execute([$neighborPos, $programId, $songId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
        $this->markChanged($programId);
    }

    private function reindexPositions(int $programId): void
    {
        $rows = $this->pdo->prepare(
            'SELECT song_id FROM program_songs WHERE program_id = ? ORDER BY position ASC'
        );
        $rows->execute([$programId]);
        $songIds = $rows->fetchAll(\PDO::FETCH_COLUMN);
        $upd = $this->pdo->prepare(
            'UPDATE program_songs SET position = ? WHERE program_id = ? AND song_id = ?'
        );
        $pos = 1;
        foreach ($songIds as $songId) {
            $upd->execute([$pos, $programId, (int) $songId]);
            $pos++;
        }
    }

    /**
     * @return list<string> titles of active programs containing song
     */
    public function activeProgramTitlesForSong(int $songId, string $todayIso): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT p.title
             FROM programs p
             INNER JOIN program_songs ps ON ps.program_id = p.id
             WHERE ps.song_id = ?
               AND p.is_archived = 0
               AND (p.event_date IS NULL OR p.event_date >= ?)
             ORDER BY p.title ASC'
        );
        $stmt->execute([$songId, $todayIso]);

        return array_map(static fn ($row): string => (string) $row, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Программы с event_date в диапазоне (включительно), любой статус архива.
     *
     * @return list<array<string, mixed>>
     */
    public function listByEventDateBetween(string $fromIso, string $toIso): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, (SELECT COUNT(*) FROM program_songs ps WHERE ps.program_id = p.id) AS song_count
             FROM programs p
             WHERE p.event_date IS NOT NULL
               AND p.event_date >= ?
               AND p.event_date <= ?
             ORDER BY p.event_date ASC, p.id ASC'
        );
        $stmt->execute([$fromIso, $toIso]);

        return $stmt->fetchAll() ?: [];
    }

    public function markNotified(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE programs SET notified_at = CURRENT_TIMESTAMP, changed_since_notify = 0, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    private function markChanged(int $id): void
    {
        $row = $this->find($id);
        if ($row === null) {
            return;
        }
        if (($row['notified_at'] ?? null) === null || ($row['notified_at'] ?? '') === '') {
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE programs SET changed_since_notify = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * @return list<int> program ids archived
     */
    public function archivePastEvents(string $todayIso): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM programs
             WHERE is_archived = 0 AND event_date IS NOT NULL AND event_date < ?'
        );
        $stmt->execute([$todayIso]);
        $ids = array_map(static fn ($id): int => (int) $id, $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $upd = $this->pdo->prepare(
            'UPDATE programs SET is_archived = 1, updated_at = CURRENT_TIMESTAMP WHERE id IN (' . $placeholders . ')'
        );
        $upd->execute($ids);

        return $ids;
    }

    public function needsFirstNotify(array $program): bool
    {
        return ($program['notified_at'] ?? null) === null || ($program['notified_at'] ?? '') === '';
    }

    public function needsUpdateNotify(array $program): bool
    {
        return (int) ($program['changed_since_notify'] ?? 0) === 1
            && ($program['notified_at'] ?? null) !== null
            && ($program['notified_at'] ?? '') !== '';
    }
}