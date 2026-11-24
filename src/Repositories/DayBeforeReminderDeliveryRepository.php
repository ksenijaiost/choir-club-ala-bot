<?php

declare(strict_types=1);

namespace App\Repositories;

final class DayBeforeReminderDeliveryRepository
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function recordSent(int $rehearsalId, int $userId): void
    {
        $this->upsert($rehearsalId, $userId, self::STATUS_SENT, null);
    }

    public function recordFailed(int $rehearsalId, int $userId, string $errorText): void
    {
        $this->upsert($rehearsalId, $userId, self::STATUS_FAILED, $errorText);
    }

    private function upsert(int $rehearsalId, int $userId, string $status, ?string $errorText): void
    {
        $existing = $this->find($rehearsalId, $userId);
        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO day_before_reminder_deliveries (rehearsal_id, user_id, status, error_text) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$rehearsalId, $userId, $status, $errorText]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE day_before_reminder_deliveries
             SET status = ?, error_text = ?, delivered_at = CURRENT_TIMESTAMP
             WHERE rehearsal_id = ? AND user_id = ?'
        );
        $stmt->execute([$status, $errorText, $rehearsalId, $userId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $rehearsalId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM day_before_reminder_deliveries WHERE rehearsal_id = ? AND user_id = ? LIMIT 1'
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
            'SELECT * FROM day_before_reminder_deliveries WHERE rehearsal_id = ? ORDER BY user_id ASC'
        );
        $stmt->execute([$rehearsalId]);

        return $stmt->fetchAll() ?: [];
    }

    public function hasAnyForRehearsal(int $rehearsalId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM day_before_reminder_deliveries WHERE rehearsal_id = ? LIMIT 1'
        );
        $stmt->execute([$rehearsalId]);

        return (bool) $stmt->fetchColumn();
    }
}