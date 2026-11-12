<?php

declare(strict_types=1);

namespace App\Repositories;

final class ScheduleTemplateRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM schedule_templates WHERE is_active = 1 ORDER BY sort_order ASC, weekday ASC'
        );

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM schedule_templates ORDER BY sort_order ASC, weekday ASC');

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM schedule_templates WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function update(int $id, int $weekday, string $startTime, int $durationMinutes, string $address, bool $isActive): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE schedule_templates SET weekday = ?, start_time = ?, duration_minutes = ?, address = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([$weekday, $startTime, $durationMinutes, $address, $isActive ? 1 : 0, $id]);
    }
}
