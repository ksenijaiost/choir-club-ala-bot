<?php

declare(strict_types=1);

namespace App\Repositories;

final class BroadcastRepository
{
    public const KIND_TEXT = 'text';

    public const KIND_POLL = 'poll';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const CUSTOM_TEXT_MAX = 500;

    public const OPTION_LABEL_MAX = 100;

    public const MAX_OPTIONS = 10;

    public const MIN_OPTIONS = 2;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @param list<string> $options
     */
    public function createPoll(
        int $authorUserId,
        string $question,
        array $options,
        bool $multiChoice,
        bool $allowCustom,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO broadcasts (author_user_id, kind, text, multi_choice, allow_custom, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $authorUserId,
            self::KIND_POLL,
            $question,
            $multiChoice ? 1 : 0,
            $allowCustom ? 1 : 0,
            self::STATUS_OPEN,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->insertOptions($id, $options);

        return $id;
    }

    public function createText(int $authorUserId, string $text): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO broadcasts (author_user_id, kind, text, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$authorUserId, self::KIND_TEXT, $text, self::STATUS_OPEN]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<string> $labels
     */
    private function insertOptions(int $broadcastId, array $labels): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO broadcast_options (broadcast_id, position, label) VALUES (?, ?, ?)'
        );
        foreach ($labels as $i => $label) {
            $stmt->execute([$broadcastId, $i + 1, $label]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM broadcasts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM broadcasts ORDER BY CASE status WHEN \'open\' THEN 0 ELSE 1 END, created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOptions(int $broadcastId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM broadcast_options WHERE broadcast_id = ? ORDER BY position ASC'
        );
        $stmt->execute([$broadcastId]);

        return $stmt->fetchAll();
    }

    public function addRecipient(int $broadcastId, int $userId): void
    {
        $sql = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT OR IGNORE INTO broadcast_recipients (broadcast_id, user_id) VALUES (?, ?)'
            : 'INSERT IGNORE INTO broadcast_recipients (broadcast_id, user_id) VALUES (?, ?)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$broadcastId, $userId]);
    }

    public function markDelivered(int $broadcastId, int $userId, int $telegramMessageId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE broadcast_recipients
             SET telegram_message_id = ?, delivered_at = CURRENT_TIMESTAMP, delivery_error = NULL
             WHERE broadcast_id = ? AND user_id = ?'
        );
        $stmt->execute([$telegramMessageId, $broadcastId, $userId]);
    }

    public function markDeliveryFailed(int $broadcastId, int $userId, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE broadcast_recipients
             SET delivery_error = ?, delivered_at = NULL, telegram_message_id = NULL
             WHERE broadcast_id = ? AND user_id = ?'
        );
        $stmt->execute([$error, $broadcastId, $userId]);
    }

    public function markRead(int $broadcastId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE broadcast_recipients SET read_at = CURRENT_TIMESTAMP WHERE broadcast_id = ? AND user_id = ?'
        );
        $stmt->execute([$broadcastId, $userId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecipients(int $broadcastId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, u.display_name, u.telegram_id, u.telegram_username
             FROM broadcast_recipients r
             JOIN users u ON u.id = r.user_id
             WHERE r.broadcast_id = ?
             ORDER BY u.display_name ASC'
        );
        $stmt->execute([$broadcastId]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAnswers(int $broadcastId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, u.display_name, u.telegram_username, o.label, o.position
             FROM broadcast_answers a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN broadcast_options o ON o.id = a.option_id
             WHERE a.broadcast_id = ?
             ORDER BY a.user_id ASC, o.position ASC'
        );
        $stmt->execute([$broadcastId]);

        return $stmt->fetchAll();
    }

    public function isOpen(array $broadcast): bool
    {
        return ($broadcast['status'] ?? '') === self::STATUS_OPEN;
    }

    public function close(int $broadcastId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE broadcasts SET status = ?, closed_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([self::STATUS_CLOSED, $broadcastId]);
    }

    public function setAnswerOption(int $broadcastId, int $userId, int $optionId, bool $multiChoice): void
    {
        if (!$multiChoice) {
            $this->pdo->prepare('DELETE FROM broadcast_answers WHERE broadcast_id = ? AND user_id = ?')
                ->execute([$broadcastId, $userId]);
        }

        $existing = $this->pdo->prepare(
            'SELECT id FROM broadcast_answers WHERE broadcast_id = ? AND user_id = ? AND option_id = ? LIMIT 1'
        );
        $existing->execute([$broadcastId, $userId, $optionId]);
        $row = $existing->fetch();
        if ($row !== false) {
            if ($multiChoice) {
                $this->pdo->prepare('DELETE FROM broadcast_answers WHERE id = ?')->execute([(int) $row['id']]);
            }

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO broadcast_answers (broadcast_id, user_id, option_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$broadcastId, $userId, $optionId]);
    }

    public function setCustomAnswer(int $broadcastId, int $userId, string $text): void
    {
        $this->pdo->prepare(
            'DELETE FROM broadcast_answers WHERE broadcast_id = ? AND user_id = ? AND option_id IS NULL'
        )->execute([$broadcastId, $userId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO broadcast_answers (broadcast_id, user_id, custom_text) VALUES (?, ?, ?)'
        );
        $stmt->execute([$broadcastId, $userId, $text]);
    }

    /**
     * @return list<int>
     */
    public function selectedOptionIds(int $broadcastId, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT option_id FROM broadcast_answers WHERE broadcast_id = ? AND user_id = ? AND option_id IS NOT NULL'
        );
        $stmt->execute([$broadcastId, $userId]);
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = (int) $row['option_id'];
        }

        return $ids;
    }

    public function findCustomAnswer(int $broadcastId, int $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT custom_text FROM broadcast_answers
             WHERE broadcast_id = ? AND user_id = ? AND option_id IS NULL LIMIT 1'
        );
        $stmt->execute([$broadcastId, $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $text = $row['custom_text'] ?? null;

        return $text !== null && $text !== '' ? (string) $text : null;
    }

    public function hasAnswered(int $broadcastId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM broadcast_answers WHERE broadcast_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$broadcastId, $userId]);

        return (bool) $stmt->fetchColumn();
    }
}