<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserOpenMenuRepository
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    /**
     * @return array{telegram_id: int, chat_id: int, message_id: int}|null
     */
    public function find(int $telegramId, int $chatId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT telegram_id, chat_id, message_id FROM user_open_menus
             WHERE telegram_id = ? AND chat_id = ? LIMIT 1',
        );
        $stmt->execute([$telegramId, $chatId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? [
            'telegram_id' => (int) $row['telegram_id'],
            'chat_id' => (int) $row['chat_id'],
            'message_id' => (int) $row['message_id'],
        ] : null;
    }

    public function upsert(int $telegramId, int $chatId, int $messageId): void
    {
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO user_open_menus (telegram_id, chat_id, message_id, updated_at)
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                 ON CONFLICT(telegram_id, chat_id) DO UPDATE SET
                    message_id = excluded.message_id,
                    updated_at = CURRENT_TIMESTAMP',
            );
            $stmt->execute([$telegramId, $chatId, $messageId]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_open_menus (telegram_id, chat_id, message_id, updated_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                message_id = VALUES(message_id),
                updated_at = CURRENT_TIMESTAMP',
        );
        $stmt->execute([$telegramId, $chatId, $messageId]);
    }

    public function delete(int $telegramId, int $chatId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM user_open_menus WHERE telegram_id = ? AND chat_id = ?',
        );
        $stmt->execute([$telegramId, $chatId]);
    }
}