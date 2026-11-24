<?php

declare(strict_types=1);

namespace App\Repositories;

final class RehearsalRsvpNudgeRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $rehearsalId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rehearsal_rsvp_nudges WHERE rehearsal_id = ? LIMIT 1'
        );
        $stmt->execute([$rehearsalId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function record(int $rehearsalId): void
    {
        $existing = $this->find($rehearsalId);
        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO rehearsal_rsvp_nudges (rehearsal_id) VALUES (?)'
            );
            $stmt->execute([$rehearsalId]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE rehearsal_rsvp_nudges SET last_nudged_at = CURRENT_TIMESTAMP WHERE rehearsal_id = ?'
        );
        $stmt->execute([$rehearsalId]);
    }
}