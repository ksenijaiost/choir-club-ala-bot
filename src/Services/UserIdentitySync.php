<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;

final class UserIdentitySync
{
    public static function syncPrivilegedTelegramUser(
        int $telegramId,
        string $displayName,
        AccessControl $access,
        UserRepository $users,
    ): void {
        if ($access->isOwnerTelegramId($telegramId)) {
            $users->upsertOwner($telegramId, $displayName);

            return;
        }

        if ($access->isLegacyConductorTelegramId($telegramId)) {
            $users->upsertConductor($telegramId, $displayName);
        }
    }
}
