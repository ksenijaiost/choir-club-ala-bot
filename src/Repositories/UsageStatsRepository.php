<?php

declare(strict_types=1);

namespace App\Repositories;

final class UsageStatsRepository
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    public function recordHit(string $day, string $section, int $telegramId): void
    {
        $section = mb_substr(trim($section), 0, 50);
        if ($section === '' || $telegramId <= 0) {
            return;
        }

        $isSqlite = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';

        if ($isSqlite) {
            $this->pdo->prepare(
                'INSERT INTO usage_daily (day, section, uses, users) VALUES (?, ?, 1, 0)
                 ON CONFLICT(day, section) DO UPDATE SET uses = uses + 1',
            )->execute([$day, $section]);
        } else {
            $this->pdo->prepare(
                'INSERT INTO usage_daily (day, section, uses, users) VALUES (?, ?, 1, 0)
                 ON DUPLICATE KEY UPDATE uses = uses + 1',
            )->execute([$day, $section]);
        }

        $insertUserSql = $isSqlite
            ? 'INSERT OR IGNORE INTO usage_daily_users (day, section, telegram_id) VALUES (?, ?, ?)'
            : 'INSERT IGNORE INTO usage_daily_users (day, section, telegram_id) VALUES (?, ?, ?)';

        $stmt = $this->pdo->prepare($insertUserSql);
        $stmt->execute([$day, $section, $telegramId]);
        if ($stmt->rowCount() > 0) {
            if ($isSqlite) {
                $this->pdo->prepare(
                    'UPDATE usage_daily SET users = users + 1 WHERE day = ? AND section = ?',
                )->execute([$day, $section]);
            } else {
                $this->pdo->prepare(
                    'UPDATE usage_daily SET users = users + 1 WHERE day = ? AND section = ?',
                )->execute([$day, $section]);
            }
        }
    }

    /**
     * @return list<array{section: string, uses: int, users: int}>
     */
    public function aggregateBySection(string $fromDay, ?string $toDay = null): array
    {
        $sql = 'SELECT section, SUM(uses) AS uses_sum, SUM(users) AS users_sum
                FROM usage_daily WHERE day >= ?';
        $params = [$fromDay];
        if ($toDay !== null) {
            $sql .= ' AND day <= ?';
            $params[] = $toDay;
        }
        $sql .= ' GROUP BY section ORDER BY uses_sum DESC, section ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'section' => (string) ($row['section'] ?? ''),
                'uses' => (int) ($row['uses_sum'] ?? 0),
                'users' => (int) ($row['users_sum'] ?? 0),
            ];
        }

        return $out;
    }

    public function activeParticipantsCount(string $fromDay, ?string $toDay = null): int
    {
        $sql = 'SELECT COUNT(DISTINCT telegram_id) FROM usage_daily_users WHERE day >= ?';
        $params = [$fromDay];
        if ($toDay !== null) {
            $sql .= ' AND day <= ?';
            $params[] = $toDay;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function purgeUsersOlderThan(int $days): int
    {
        $days = max(1, $days);
        $isSqlite = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($isSqlite) {
            $stmt = $this->pdo->prepare(
                "DELETE FROM usage_daily_users WHERE day < date('now', '-' || ? || ' days')",
            );
        } else {
            $stmt = $this->pdo->prepare(
                'DELETE FROM usage_daily_users WHERE day < DATE_SUB(CURDATE(), INTERVAL ? DAY)',
            );
        }
        $stmt->execute([$days]);

        return $stmt->rowCount();
    }
}