<?php

declare(strict_types=1);

namespace App\Repositories;

final class ProgramNotifyDeliveryRepository
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function recordSent(int $programId, int $userId): void
    {
        $this->upsert($programId, $userId, self::STATUS_SENT, null);
    }

    public function recordFailed(int $programId, int $userId, ?string $errorText): void
    {
        $this->upsert($programId, $userId, self::STATUS_FAILED, $errorText);
    }

    private function upsert(int $programId, int $userId, string $status, ?string $errorText): void
    {
        $driver = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO program_notify_deliveries (program_id, user_id, status, error_text)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT(program_id, user_id) DO UPDATE SET
                 status = excluded.status,
                 error_text = excluded.error_text,
                 notified_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([$programId, $userId, $status, $errorText]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO program_notify_deliveries (program_id, user_id, status, error_text)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
             status = VALUES(status),
             error_text = VALUES(error_text),
             notified_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$programId, $userId, $status, $errorText]);
    }
}