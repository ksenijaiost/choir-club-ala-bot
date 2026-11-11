<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Domain\UserRole;
use App\Repositories\UserRepository;

/**
 * Операционные уведомления (пропуски, причины, оплата, изгнание) — активному дирижёру в БД;
 * если дирижёра нет — owner.
 */
final class NotificationRecipientResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly UserRepository $users,
    ) {
    }

    public function resolveTelegramId(): ?int
    {
        $conductors = $this->users->listActiveByRole(UserRole::CONDUCTOR);
        if ($conductors !== []) {
            return (int) $conductors[0]['telegram_id'];
        }

        $ownerId = trim($this->config->ownerTelegramId);
        if ($ownerId !== '') {
            return (int) $ownerId;
        }

        $owners = $this->users->listActiveByRole(UserRole::OWNER);
        if ($owners !== []) {
            return (int) $owners[0]['telegram_id'];
        }

        return null;
    }
}
