<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\UserRole;
use App\Domain\UserStatus;

final class UserRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByTelegramId(int $telegramId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE telegram_id = ? LIMIT 1');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(int $telegramId, string $displayName, ?string $voicePart, string $role, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (telegram_id, display_name, voice_part, role, status) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$telegramId, $displayName, $voicePart, $role, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateDisplayName(int $userId, string $displayName): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET display_name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$displayName, $userId]);
    }

    public function updateVoicePart(int $userId, string $voicePart): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET voice_part = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$voicePart, $userId]);
    }

    public function updateStatus(int $userId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$status, $userId]);
    }

    public function updateRole(int $userId, string $role): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$role, $userId]);
    }

    public function upsertOwner(int $telegramId, string $displayName): void
    {
        $existing = $this->findByTelegramId($telegramId);
        if ($existing === null) {
            $this->create(
                $telegramId,
                $displayName !== '' ? $displayName : 'Owner',
                'tenor',
                UserRole::OWNER,
                UserStatus::ACTIVE,
            );

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users SET role = ?, status = ?, display_name = CASE WHEN display_name = \'\' THEN ? ELSE display_name END, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([
            UserRole::OWNER,
            UserStatus::ACTIVE,
            $displayName !== '' ? $displayName : 'Owner',
            $existing['id'],
        ]);
    }

    public function upsertConductor(int $telegramId, string $displayName = 'Дирижёр'): void
    {
        $existing = $this->findByTelegramId($telegramId);
        if ($existing !== null && ($existing['role'] ?? '') === UserRole::OWNER) {
            return;
        }

        $this->demoteAllConductorsToMember();

        if ($existing === null) {
            $this->create($telegramId, $displayName, 'tenor', UserRole::CONDUCTOR, UserStatus::ACTIVE);

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users SET role = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([UserRole::CONDUCTOR, UserStatus::ACTIVE, $existing['id']]);
    }

    public function appointConductor(int $userId): void
    {
        $target = $this->findById($userId);
        if ($target === null || ($target['status'] ?? '') !== UserStatus::ACTIVE) {
            throw new \InvalidArgumentException('User cannot be appointed conductor');
        }

        $role = (string) ($target['role'] ?? '');
        if ($role === UserRole::OWNER || $role === UserRole::CONDUCTOR) {
            throw new \InvalidArgumentException('User cannot be appointed conductor');
        }

        $this->pdo->beginTransaction();
        try {
            $this->demoteAllConductorsToMember();
            $this->updateRole($userId, UserRole::CONDUCTOR);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function demoteAllConductorsToMember(): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE role = ?'
        );
        $stmt->execute([UserRole::MEMBER, UserRole::CONDUCTOR]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveConductor(): ?array
    {
        $list = $this->listActiveByRole(UserRole::CONDUCTOR);

        return $list[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveConductorCandidates(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE status = ? AND role IN (?, ?) ORDER BY display_name ASC'
        );
        $stmt->execute([UserStatus::ACTIVE, UserRole::MEMBER, UserRole::ADMIN]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveByRole(string $role): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE role = ? AND status = ? ORDER BY display_name ASC'
        );
        $stmt->execute([$role, UserStatus::ACTIVE]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveMembers(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE role = ? AND status = ? ORDER BY display_name ASC'
        );
        $stmt->execute([UserRole::MEMBER, UserStatus::ACTIVE]);

        return $stmt->fetchAll();
    }

    public function resetConsecutiveUnexcused(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET consecutive_unexcused = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$userId]);
    }

    /**
     * Участники и помощники (не owner/conductor), со статусом active или blocked.
     *
     * @return list<array<string, mixed>>
     */
    public function listMembersForBlocking(?string $voicePartFilter = null): array
    {
        $sql = 'SELECT * FROM users WHERE role IN (?, ?) AND status IN (?, ?)';
        $params = [UserRole::MEMBER, UserRole::ADMIN, UserStatus::ACTIVE, UserStatus::BLOCKED];

        if ($voicePartFilter !== null && $voicePartFilter !== '' && $voicePartFilter !== 'all') {
            $sql .= ' AND voice_part = ?';
            $params[] = $voicePartFilter;
        }

        $sql .= ' ORDER BY display_name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
