<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserAchievementRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function tryAward(int $userId, string $code): bool
    {
        $sql = $this->driverName() === 'mysql'
            ? 'INSERT IGNORE INTO user_achievements (user_id, code, awarded_at) VALUES (?, ?, CURRENT_TIMESTAMP)'
            : 'INSERT OR IGNORE INTO user_achievements (user_id, code, awarded_at) VALUES (?, ?, CURRENT_TIMESTAMP)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $code]);

        return $stmt->rowCount() > 0;
    }

    public function hasAward(int $userId, string $code): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM user_achievements WHERE user_id = ? AND code = ? LIMIT 1'
        );
        $stmt->execute([$userId, $code]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return list<string>
     */
    public function listCodesForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT code FROM user_achievements WHERE user_id = ? ORDER BY awarded_at ASC, id ASC'
        );
        $stmt->execute([$userId]);

        return array_map(
            static fn (array $row): string => (string) $row['code'],
            $stmt->fetchAll() ?: [],
        );
    }

    private function driverName(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }
}