<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\RehearsalRsvpAnswer;

final class RehearsalRsvpRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function upsert(int $rehearsalId, int $userId, string $answer): void
    {
        if (!RehearsalRsvpAnswer::isValid($answer)) {
            throw new \InvalidArgumentException('Invalid RSVP answer: ' . $answer);
        }

        $existing = $this->find($rehearsalId, $userId);
        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO rehearsal_rsvp (rehearsal_id, user_id, answer) VALUES (?, ?, ?)'
            );
            $stmt->execute([$rehearsalId, $userId, $answer]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE rehearsal_rsvp SET answer = ?, updated_at = CURRENT_TIMESTAMP WHERE rehearsal_id = ? AND user_id = ?'
        );
        $stmt->execute([$answer, $rehearsalId, $userId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $rehearsalId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rehearsal_rsvp WHERE rehearsal_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$rehearsalId, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForRehearsal(int $rehearsalId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rehearsal_rsvp WHERE rehearsal_id = ? ORDER BY user_id ASC'
        );
        $stmt->execute([$rehearsalId]);

        return $stmt->fetchAll() ?: [];
    }
}