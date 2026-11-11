<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\UserRole;
use App\Text\TextRepository;

final class UserRolePresenter
{
    public function __construct(private readonly TextRepository $texts)
    {
    }

    /**
     * @param array<string, mixed> $user
     */
    public function singingRoleBadge(array $user): string
    {
        $role = (string) ($user['role'] ?? UserRole::MEMBER);

        return match ($role) {
            UserRole::OWNER => $this->texts->get('admin.role_badge_owner'),
            UserRole::CONDUCTOR => $this->texts->get('admin.role_badge_conductor'),
            UserRole::ADMIN => $this->texts->get('admin.role_badge_helper'),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $user
     */
    public function displayNameWithBadge(array $user): string
    {
        $name = (string) ($user['display_name'] ?? '');
        $badge = $this->singingRoleBadge($user);

        if ($badge === '') {
            return $name;
        }

        return $name . ' · ' . $badge;
    }
}
