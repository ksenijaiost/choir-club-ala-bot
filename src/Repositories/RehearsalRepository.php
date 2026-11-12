<?php

declare(strict_types=1);

namespace App\Repositories;

final class RehearsalRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rehearsals WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function existsForTemplateSlot(int $templateId, string $startsAtLocal): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM rehearsals WHERE template_id = ? AND starts_at = ? LIMIT 1'
        );
        $stmt->execute([$templateId, $startsAtLocal]);

        return (bool) $stmt->fetchColumn();
    }

    public function create(
        ?int $templateId,
        string $startsAt,
        string $endsAt,
        string $address,
        string $status = 'scheduled',
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$templateId, $startsAt, $endsAt, $address, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFrom(string $fromLocal, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rehearsals WHERE starts_at >= ? ORDER BY starts_at ASC LIMIT ?'
        );
        $stmt->bindValue(1, $fromLocal);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function countFrom(string $fromLocal): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rehearsals WHERE starts_at >= ?');
        $stmt->execute([$fromLocal]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFromNearest(string $fromLocal, string $pivotLocal, int $offset, int $limit): array
    {
        $isSqlite = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $order = $isSqlite
            ? 'ABS(strftime(\'%s\', starts_at) - strftime(\'%s\', ?)) ASC, starts_at ASC'
            : 'ABS(TIMESTAMPDIFF(SECOND, starts_at, ?)) ASC, starts_at ASC';

        $stmt = $this->pdo->prepare(
            "SELECT * FROM rehearsals WHERE starts_at >= ? ORDER BY {$order} LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $fromLocal);
        $stmt->bindValue(2, $pivotLocal);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(4, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function updateSchedule(int $id, string $startsAt, string $endsAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rehearsals SET starts_at = ?, ends_at = ?, template_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$startsAt, $endsAt, $id]);
    }

    public function updateAddress(int $id, string $address): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rehearsals SET address = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$address, $id]);
    }

    public function cancel(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rehearsals SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute(['cancelled', $id]);
    }

    public function deleteFutureScheduledFromTemplates(string $fromLocalDateTime): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rehearsals WHERE status = ? AND template_id IS NOT NULL AND starts_at >= ?'
        );
        $stmt->execute(['scheduled', $fromLocalDateTime]);

        return $stmt->rowCount();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listScheduledOnLocalDay(string $dayYmd): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rehearsals WHERE status = ? AND starts_at >= ? AND starts_at <= ? ORDER BY starts_at ASC'
        );
        $stmt->execute(['scheduled', $dayYmd . ' 00:00:00', $dayYmd . ' 23:59:59']);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUpcomingScheduled(string $fromLocal, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rehearsals WHERE status = ? AND starts_at > ? ORDER BY starts_at ASC LIMIT ?'
        );
        $stmt->bindValue(1, 'scheduled');
        $stmt->bindValue(2, $fromLocal);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOngoingScheduled(string $nowLocal): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rehearsals WHERE status = ? AND starts_at <= ? AND ends_at > ? ORDER BY starts_at ASC'
        );
        $stmt->execute(['scheduled', $nowLocal, $nowLocal]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listScheduledEnded(string $nowLocal): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rehearsals WHERE status = ? AND ends_at <= ? ORDER BY ends_at ASC'
        );
        $stmt->execute(['scheduled', $nowLocal]);

        return $stmt->fetchAll() ?: [];
    }
}
