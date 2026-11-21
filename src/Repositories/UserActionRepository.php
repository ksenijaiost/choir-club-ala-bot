<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserActionRepository
{
    private const KEEP_PER_USER = 10;

    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    public function add(int $telegramId, ?string $updateType, string $action): void
    {
        $action = mb_substr(trim($action), 0, 200);
        if ($action === '') {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_actions (telegram_id, update_type, action) VALUES (?, ?, ?)',
        );
        $stmt->execute([$telegramId, $updateType, $action]);

        $this->pruneOld($telegramId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $telegramId, int $limit = self::KEEP_PER_USER): array
    {
        $limit = max(1, min($limit, self::KEEP_PER_USER));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_actions WHERE telegram_id = ? ORDER BY created_at DESC, id DESC LIMIT ?',
        );
        $stmt->bindValue(1, $telegramId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_reverse($rows);
    }

    private function pruneOld(int $telegramId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM user_actions WHERE telegram_id = ? ORDER BY created_at DESC, id DESC LIMIT ?',
        );
        $stmt->bindValue(1, $telegramId, \PDO::PARAM_INT);
        $stmt->bindValue(2, self::KEEP_PER_USER, \PDO::PARAM_INT);
        $stmt->execute();
        $keepIds = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        if ($keepIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $delete = $this->pdo->prepare(
            'DELETE FROM user_actions WHERE telegram_id = ? AND id NOT IN (' . $placeholders . ')',
        );
        $delete->execute(array_merge([$telegramId], array_map(intval(...), $keepIds)));
    }
}