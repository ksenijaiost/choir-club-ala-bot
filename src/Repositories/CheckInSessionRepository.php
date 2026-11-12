<?php

declare(strict_types=1);

namespace App\Repositories;

final class CheckInSessionRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function ensureOpen(int $rehearsalId): void
    {
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo->prepare(
                'INSERT OR IGNORE INTO check_in_sessions (rehearsal_id) VALUES (?)'
            );
            $stmt->execute([$rehearsalId]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO check_in_sessions (rehearsal_id) VALUES (?)'
        );
        $stmt->execute([$rehearsalId]);
    }

    public function close(int $rehearsalId): void
    {
        $this->ensureOpen($rehearsalId);
        $stmt = $this->pdo->prepare(
            'UPDATE check_in_sessions SET closed_at = CURRENT_TIMESTAMP WHERE rehearsal_id = ? AND closed_at IS NULL'
        );
        $stmt->execute([$rehearsalId]);
    }

    public function isClosed(int $rehearsalId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT closed_at FROM check_in_sessions WHERE rehearsal_id = ? LIMIT 1'
        );
        $stmt->execute([$rehearsalId]);
        $closed = $stmt->fetchColumn();

        return $closed !== false && $closed !== null && $closed !== '';
    }
}
