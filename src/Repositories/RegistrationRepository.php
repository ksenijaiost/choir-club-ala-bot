<?php

declare(strict_types=1);

namespace App\Repositories;

final class RegistrationRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function create(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO registration_requests (user_id, status) VALUES (?, ?)'
        );
        $stmt->execute([$userId, 'pending']);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOpenByUserId(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM registration_requests WHERE user_id = ? AND status = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, 'pending']);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function setAdminMessageId(int $requestId, int $messageId): void
    {
        $stmt = $this->pdo->prepare('UPDATE registration_requests SET admin_message_id = ? WHERE id = ?');
        $stmt->execute([$messageId, $requestId]);
    }

    public function resolve(int $requestId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE registration_requests SET status = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$status, $requestId]);
    }
}
