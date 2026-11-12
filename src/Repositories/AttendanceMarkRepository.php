<?php

declare(strict_types=1);

namespace App\Repositories;

final class AttendanceMarkRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $rehearsalId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM attendance_marks WHERE rehearsal_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$rehearsalId, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function upsert(
        int $rehearsalId,
        int $userId,
        string $mark,
        string $source,
        ?string $reasonText = null,
    ): void {
        $existing = $this->find($rehearsalId, $userId);

        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO attendance_marks (rehearsal_id, user_id, mark, source, reason_text) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$rehearsalId, $userId, $mark, $source, $reasonText]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE attendance_marks SET mark = ?, source = ?, reason_text = COALESCE(?, reason_text), marked_at = CURRENT_TIMESTAMP
             WHERE rehearsal_id = ? AND user_id = ?'
        );
        $stmt->execute([$mark, $source, $reasonText, $rehearsalId, $userId]);
    }

    public function appendReason(int $rehearsalId, int $userId, string $reasonText): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE attendance_marks SET reason_text = ?, marked_at = CURRENT_TIMESTAMP
             WHERE rehearsal_id = ? AND user_id = ?'
        );
        $stmt->execute([$reasonText, $rehearsalId, $userId]);
    }

    public function hasMark(int $rehearsalId, int $userId): bool
    {
        return $this->find($rehearsalId, $userId) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listChronologicalForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT am.*, r.starts_at, r.ends_at, r.address, r.status AS rehearsal_status
             FROM attendance_marks am
             INNER JOIN rehearsals r ON r.id = am.rehearsal_id
             WHERE am.user_id = ?
             ORDER BY r.starts_at ASC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUserRecent(int $userId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT am.*, r.starts_at, r.ends_at, r.address, r.status AS rehearsal_status
             FROM attendance_marks am
             INNER JOIN rehearsals r ON r.id = am.rehearsal_id
             WHERE am.user_id = ?
             ORDER BY r.starts_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForRehearsal(int $rehearsalId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT am.*, u.display_name, u.voice_part, u.telegram_id
             FROM attendance_marks am
             INNER JOIN users u ON u.id = am.user_id
             WHERE am.rehearsal_id = ?
             ORDER BY u.display_name ASC'
        );
        $stmt->execute([$rehearsalId]);

        return $stmt->fetchAll() ?: [];
    }

    public function findMarkFor(int $rehearsalId, int $userId): ?string
    {
        $row = $this->find($rehearsalId, $userId);

        return $row === null ? null : (string) ($row['mark'] ?? '');
    }

    public function delete(int $rehearsalId, int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM attendance_marks WHERE rehearsal_id = ? AND user_id = ?');
        $stmt->execute([$rehearsalId, $userId]);
    }
}
