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
        $notifications = $role === UserRole::CONDUCTOR ? 0 : 1;
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (telegram_id, display_name, voice_part, role, status, material_notifications_enabled)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$telegramId, $displayName, $voicePart, $role, $status, $notifications]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $user
     */
    public function materialNotificationsEnabled(array $user): bool
    {
        return ((int) ($user['material_notifications_enabled'] ?? 1)) === 1;
    }

    public function setMaterialNotificationsEnabled(int $userId, bool $enabled): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET material_notifications_enabled = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$enabled ? 1 : 0, $userId]);
    }

    public function toggleMaterialNotifications(int $userId): bool
    {
        $user = $this->findById($userId);
        if ($user === null) {
            return true;
        }

        $next = !$this->materialNotificationsEnabled($user);
        $this->setMaterialNotificationsEnabled($userId, $next);

        return $next;
    }

    public function updateDisplayName(int $userId, string $displayName): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET display_name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$displayName, $userId]);
    }

    public function updateTelegramUsername(int $userId, ?string $username): void
    {
        $normalized = $username !== null ? trim($username) : null;
        if ($normalized === '') {
            $normalized = null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users SET telegram_username = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$normalized, $userId]);
    }

    public function updateVoicePart(int $userId, string $voicePart): void
    {
        $this->setVoicePart($userId, $voicePart);
    }

    public function setVoicePart(int $userId, ?string $voicePart): void
    {
        $existing = $this->findById($userId);
        if ($existing !== null && ($existing['role'] ?? '') === UserRole::CONDUCTOR) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET voice_part = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$voicePart, $userId]);
    }

    public function clearVoicePart(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET voice_part = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$userId]);
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
                null,
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
            $this->create($telegramId, $displayName, null, UserRole::CONDUCTOR, UserStatus::ACTIVE);
            $created = $this->findByTelegramId($telegramId);
            if ($created !== null) {
                $this->resetConsecutiveUnexcused((int) $created['id']);
            }

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users SET role = ?, status = ?, voice_part = NULL, consecutive_unexcused = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
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
            $this->clearVoicePart($userId);
            $this->resetConsecutiveUnexcused($userId);
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

    public function incrementConsecutiveUnexcused(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET consecutive_unexcused = consecutive_unexcused + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$userId]);

        $stmt = $this->pdo->prepare('SELECT consecutive_unexcused FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function setConsecutiveUnexcused(int $userId, int $value): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET consecutive_unexcused = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$value, $userId]);
    }

    /**
     * Получатели материалов, программ и рассылок: active member/admin/conductor/owner.
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveParticipants(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE status = ? AND role IN (?, ?, ?, ?) ORDER BY id ASC'
        );
        $stmt->execute([
            UserStatus::ACTIVE,
            UserRole::MEMBER,
            UserRole::ADMIN,
            UserRole::CONDUCTOR,
            UserRole::OWNER,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Участники посещаемости и напоминаний накануне (без дирижёра).
     *
     * @return list<array<string, mixed>>
     */
    public function listAttendanceParticipants(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE status = ? AND role IN (?, ?, ?) ORDER BY id ASC'
        );
        $stmt->execute([UserStatus::ACTIVE, UserRole::MEMBER, UserRole::ADMIN, UserRole::OWNER]);

        return $stmt->fetchAll();
    }

    /**
     * Участники, помощники и владелица для напоминаний об оплате.
     *
     * @return list<array<string, mixed>>
     */
    public function listActivePayers(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE status = ? AND role IN (?, ?, ?) ORDER BY display_name ASC'
        );
        $stmt->execute([UserStatus::ACTIVE, UserRole::MEMBER, UserRole::ADMIN, UserRole::OWNER]);

        return $stmt->fetchAll();
    }

    /**
     * Участники и помощники (не owner/conductor), со статусом active или blocked.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * @return list<int>
     */
    public function listAllActiveTelegramIds(): array
    {
        $stmt = $this->pdo->prepare('SELECT telegram_id FROM users WHERE status = ? ORDER BY id ASC');
        $stmt->execute([UserStatus::ACTIVE]);
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = (int) $row['telegram_id'];
        }

        return $ids;
    }

    public function listMembersForBlocking(?string $voicePartFilter = null): array
    {
        return $this->listSingingMembersForAdmin($voicePartFilter, true);
    }

    /**
     * Участники хора в админке: member, admin, owner; conductor — если указана партия.
     *
     * @return list<array<string, mixed>>
     */
    public function listSingingMembersForAdmin(?string $voicePartFilter = null, bool $includeBlocked = true): array
    {
        $statuses = $includeBlocked
            ? [UserStatus::ACTIVE, UserStatus::BLOCKED]
            : [UserStatus::ACTIVE];

        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
        $sql = "SELECT * FROM users WHERE status IN ({$placeholders}) AND role IN (?, ?, ?, ?)";
        $params = [
            ...$statuses,
            UserRole::MEMBER,
            UserRole::ADMIN,
            UserRole::OWNER,
            UserRole::CONDUCTOR,
        ];

        if ($voicePartFilter !== null && $voicePartFilter !== '' && $voicePartFilter !== 'all') {
            $sql .= ' AND voice_part = ?';
            $params[] = $voicePartFilter;
        }

        $sql .= ' ORDER BY display_name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Активные участники хора для отчёта в админ-чате.
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveChoirRoster(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE status = ? AND role IN (?, ?, ?, ?) ORDER BY display_name ASC'
        );
        $stmt->execute([
            UserStatus::ACTIVE,
            UserRole::MEMBER,
            UserRole::ADMIN,
            UserRole::OWNER,
            UserRole::CONDUCTOR,
        ]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, int>
     */
    public function countChoirUsersByStatus(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS cnt FROM users
             WHERE role IN (?, ?, ?, ?)
             GROUP BY status'
        );
        $stmt->execute([UserRole::MEMBER, UserRole::ADMIN, UserRole::OWNER, UserRole::CONDUCTOR]);

        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[(string) $row['status']] = (int) $row['cnt'];
        }

        return $out;
    }

    public function setAttendanceStreak(int $userId, int $current, int $best): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET attendance_streak = ?,
                attendance_streak_best = CASE WHEN attendance_streak_best > ? THEN attendance_streak_best ELSE ? END,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$current, $best, $best, $userId]);
    }

    /**
     * Текущая серия посещений (без дирижёра): топ по attendance_streak.
     *
     * @return list<array<string, mixed>>
     */
    public function listTopAttendanceStreaks(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE status = ? AND role IN (?, ?, ?) AND attendance_streak > 0
             ORDER BY attendance_streak DESC, attendance_streak_best DESC, display_name ASC
             LIMIT ?'
        );
        $stmt->bindValue(1, UserStatus::ACTIVE, \PDO::PARAM_STR);
        $stmt->bindValue(2, UserRole::MEMBER, \PDO::PARAM_STR);
        $stmt->bindValue(3, UserRole::ADMIN, \PDO::PARAM_STR);
        $stmt->bindValue(4, UserRole::OWNER, \PDO::PARAM_STR);
        $stmt->bindValue(5, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }
}
