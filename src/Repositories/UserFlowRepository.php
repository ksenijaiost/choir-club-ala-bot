<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserFlowRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array{flow: string, context: array<string, mixed>}|null
     */
    public function get(int $telegramId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT flow, context FROM user_flows WHERE telegram_id = ? LIMIT 1');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $context = json_decode((string) $row['context'], true);

        return [
            'flow' => (string) $row['flow'],
            'context' => is_array($context) ? $context : [],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function set(int $telegramId, string $flow, array $context): void
    {
        $json = json_encode($context, JSON_THROW_ON_ERROR);

        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO user_flows (telegram_id, flow, context) VALUES (?, ?, ?)
                 ON CONFLICT(telegram_id) DO UPDATE SET
                    flow = excluded.flow,
                    context = excluded.context,
                    updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([$telegramId, $flow, $json]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_flows (telegram_id, flow, context) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                flow = VALUES(flow),
                context = VALUES(context),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$telegramId, $flow, $json]);
    }

    public function clear(int $telegramId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_flows WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
    }
}
