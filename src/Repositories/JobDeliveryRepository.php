<?php

declare(strict_types=1);

namespace App\Repositories;

final class JobDeliveryRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function wasDelivered(string $jobKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM job_deliveries WHERE job_key = ? LIMIT 1');
        $stmt->execute([$jobKey]);

        return (bool) $stmt->fetchColumn();
    }

    public function record(string $jobKey, string $jobType, ?int $relatedId = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO job_deliveries (job_key, job_type, related_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$jobKey, $jobType, $relatedId]);
    }

    public function tryClaim(string $jobKey, string $jobType, ?int $relatedId = null): bool
    {
        if ($this->wasDelivered($jobKey)) {
            return false;
        }

        try {
            $this->record($jobKey, $jobType, $relatedId);

            return true;
        } catch (\PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }

            throw $e;
        }
    }

    private function isUniqueViolation(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        $message = $e->getMessage();

        return $code === '23000'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }
}
