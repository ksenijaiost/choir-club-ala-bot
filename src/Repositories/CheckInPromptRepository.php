<?php

declare(strict_types=1);

namespace App\Repositories;

final class CheckInPromptRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function save(int $rehearsalId, int $userId, int $chatId, int $messageId): void
    {
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo->prepare(
                'INSERT OR REPLACE INTO check_in_prompts (rehearsal_id, user_id, chat_id, message_id)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$rehearsalId, $userId, $chatId, $messageId]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO check_in_prompts (rehearsal_id, user_id, chat_id, message_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE chat_id = VALUES(chat_id), message_id = VALUES(message_id)'
        );
        $stmt->execute([$rehearsalId, $userId, $chatId, $messageId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForRehearsal(int $rehearsalId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM check_in_prompts WHERE rehearsal_id = ? ORDER BY user_id ASC'
        );
        $stmt->execute([$rehearsalId]);

        return $stmt->fetchAll() ?: [];
    }

    public function deleteForRehearsal(int $rehearsalId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM check_in_prompts WHERE rehearsal_id = ?');
        $stmt->execute([$rehearsalId]);
    }
}
