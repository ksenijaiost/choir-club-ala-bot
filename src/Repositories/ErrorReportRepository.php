<?php

declare(strict_types=1);

namespace App\Repositories;

final class ErrorReportRepository
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    /**
     * @param array{
     *     telegram_id: int,
     *     chat_id: int,
     *     update_id: ?int,
     *     update_type: ?string,
     *     action: ?string,
     *     exception_class: ?string,
     *     exception_message: ?string,
     * } $row
     */
    public function create(array $row): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO error_reports (
                telegram_id, chat_id, update_id, update_type, action,
                exception_class, exception_message, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
        );
        $stmt->execute([
            $row['telegram_id'],
            $row['chat_id'],
            $row['update_id'],
            $row['update_type'],
            $row['action'],
            $row['exception_class'],
            $row['exception_message'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM error_reports WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function markReported(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE error_reports SET reported_at = CURRENT_TIMESTAMP WHERE id = ? AND reported_at IS NULL',
        );
        $stmt->execute([$id]);
    }

    public function countReportedSince(int $telegramId, int $sinceUnix): int
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM error_reports
                 WHERE telegram_id = ? AND reported_at IS NOT NULL
                   AND reported_at >= datetime(?, \'unixepoch\')',
            );
            $stmt->execute([$telegramId, $sinceUnix]);

            return (int) $stmt->fetchColumn();
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM error_reports
             WHERE telegram_id = ? AND reported_at IS NOT NULL
               AND reported_at >= FROM_UNIXTIME(?)',
        );
        $stmt->execute([$telegramId, $sinceUnix]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentExceptions(int $telegramId, int $sinceUnix, int $limit, ?int $excludeId = null): array
    {
        $limit = max(1, min($limit, 10));
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = 'SELECT * FROM error_reports
                    WHERE telegram_id = ? AND exception_class IS NOT NULL
                      AND created_at >= datetime(?, \'unixepoch\')';
            $params = [$telegramId, $sinceUnix];
            if ($excludeId !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $excludeId;
            }
            $sql .= ' ORDER BY created_at DESC LIMIT ?';
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $i => $value) {
                $stmt->bindValue($i + 1, $value, \PDO::PARAM_INT);
            }
            $stmt->bindValue(count($params) + 1, $limit, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        $sql = 'SELECT * FROM error_reports
                WHERE telegram_id = ? AND exception_class IS NOT NULL
                  AND created_at >= FROM_UNIXTIME(?)';
        $params = [$telegramId, $sinceUnix];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, \PDO::PARAM_INT);
        }
        $stmt->bindValue(count($params) + 1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}