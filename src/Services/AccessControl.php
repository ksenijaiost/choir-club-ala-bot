<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Domain\UserRole;
use App\Domain\UserStatus;

final class AccessControl
{
    public function __construct(private readonly Config $config)
    {
    }

    public function isOwnerTelegramId(int $telegramId): bool
    {
        $configured = trim($this->config->ownerTelegramId);

        return $configured !== '' && (string) $telegramId === $configured;
    }

    /**
     * Legacy: CONDUCTOR_TELEGRAM_ID в .env (опционально).
     */
    public function isLegacyConductorTelegramId(int $telegramId): bool
    {
        $configured = trim($this->config->conductorTelegramId);

        return $configured !== '' && (string) $telegramId === $configured;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function isOwner(array $user): bool
    {
        return ($user['role'] ?? '') === UserRole::OWNER
            && ($user['status'] ?? '') === UserStatus::ACTIVE;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function isConductor(array $user): bool
    {
        return ($user['role'] ?? '') === UserRole::CONDUCTOR
            && ($user['status'] ?? '') === UserStatus::ACTIVE;
    }

    /**
     * Модерация заявок, блокировки, история, оплата — owner и conductor (admin — только заявки).
     *
     * @param array<string, mixed> $user
     */
    public function canModerateRegistration(array $user): bool
    {
        if (($user['status'] ?? '') !== UserStatus::ACTIVE) {
            return false;
        }

        $role = $user['role'] ?? '';

        return in_array($role, [UserRole::OWNER, UserRole::CONDUCTOR, UserRole::ADMIN], true);
    }

    /**
     * Панель дирижёра: оплата, история, участники.
     *
     * @param array<string, mixed> $user
     */
    public function hasConductorPrivileges(array $user): bool
    {
        if (($user['status'] ?? '') !== UserStatus::ACTIVE) {
            return false;
        }

        $role = $user['role'] ?? '';

        return $role === UserRole::OWNER || $role === UserRole::CONDUCTOR;
    }

    /**
     * Назначение помощников.
     *
     * @param array<string, mixed> $user
     */
    public function canManageHelpers(array $user): bool
    {
        if (($user['status'] ?? '') !== UserStatus::ACTIVE) {
            return false;
        }

        $role = $user['role'] ?? '';

        return $role === UserRole::OWNER || $role === UserRole::CONDUCTOR;
    }

    /**
     * Админ-меню: расписание, материалы, посещаемость.
     *
     * @param array<string, mixed> $user
     */
    public function hasAdminStaffMenu(array $user): bool
    {
        if (($user['status'] ?? '') !== UserStatus::ACTIVE) {
            return false;
        }

        $role = $user['role'] ?? '';

        return in_array($role, [UserRole::OWNER, UserRole::CONDUCTOR, UserRole::ADMIN], true);
    }

    public function isAdminChatId(int|string $chatId): bool
    {
        $configured = trim($this->config->adminChatId);
        if ($configured === '') {
            return false;
        }

        return (string) $chatId === $configured;
    }

    /**
     * Загрузка и управление материалами — владелица, помощники и дирижёр.
     *
     * @param array<string, mixed> $user
     */
    public function canUploadMaterials(array $user): bool
    {
        if (($user['status'] ?? '') !== UserStatus::ACTIVE) {
            return false;
        }

        $role = (string) ($user['role'] ?? '');

        return in_array($role, [UserRole::OWNER, UserRole::ADMIN, UserRole::CONDUCTOR], true);
    }
}
