<?php

declare(strict_types=1);

namespace App\Repositories;

final class BlockEventRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function record(int $targetUserId, ?int $actorUserId, string $action, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO block_events (target_user_id, actor_user_id, action, reason) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$targetUserId, $actorUserId, $action, $reason]);
    }
}
