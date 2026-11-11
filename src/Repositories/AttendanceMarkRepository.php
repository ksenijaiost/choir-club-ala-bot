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
}
