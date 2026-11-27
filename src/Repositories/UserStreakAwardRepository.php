<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserStreakAwardRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function tryAward(int $userId, int $milestone): bool
    {
        $sql = $this->driverName() === 'mysql'
            ? 'INSERT IGNORE INTO user_streak_awards (user_id, milestone, awarded_at) VALUES (?, ?, CURRENT_TIMESTAMP)'
            : 'INSERT OR IGNORE INTO user_streak_awards (user_id, milestone, awarded_at) VALUES (?, ?, CURRENT_TIMESTAMP)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $milestone]);

        return $stmt->rowCount() > 0;
    }

    public function hasAward(int $userId, int $milestone): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM user_streak_awards WHERE user_id = ? AND milestone = ? LIMIT 1'
        );
        $stmt->execute([$userId, $milestone]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return list<int>
     */
    public function listMilestonesForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT milestone FROM user_streak_awards WHERE user_id = ? ORDER BY milestone ASC'
        );
        $stmt->execute([$userId]);

        return array_map(static fn (array $row): int => (int) $row['milestone'], $stmt->fetchAll() ?: []);
    }

    /**
     * @return list<int>
     */
    public function listMilestonesAwardedBetween(int $userId, string $fromUtc, string $toUtc): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT milestone FROM user_streak_awards
             WHERE user_id = ? AND awarded_at >= ? AND awarded_at <= ?
             ORDER BY milestone ASC'
        );
        $stmt->execute([$userId, $fromUtc, $toUtc]);

        return array_map(static fn (array $row): int => (int) $row['milestone'], $stmt->fetchAll() ?: []);
    }

    private function driverName(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }
}