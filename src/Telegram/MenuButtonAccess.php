<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Services\AccessControl;
use App\Text\TextRepository;

final class MenuButtonAccess
{
    /**
     * @param array<string, mixed> $user
     */
    public static function canUseReplyMenuButton(
        AccessControl $access,
        TextRepository $texts,
        array $user,
        string $label,
    ): bool {
        if ($label === $texts->get('menu.schedule')
            || $label === $texts->get('menu.wont_come')
            || $label === $texts->get('menu.find_song')
            || $label === $texts->get('menu.my_recordings')
            || $label === $texts->get('menu.my_profile')
            || $label === $texts->get('menu.about_bot')) {
            return true;
        }

        if ($label === $texts->get('menu.admin_schedule')) {
            return $access->hasAdminStaffMenu($user);
        }

        if ($label === $texts->get('menu.admin_materials')) {
            return $access->canUploadMaterials($user);
        }

        if ($label === $texts->get('menu.admin_attendance')
            || $label === $texts->get('menu.admin_payment')
            || $label === $texts->get('menu.admin_history')
            || $label === $texts->get('menu.admin_members')) {
            return $access->hasConductorPrivileges($user);
        }

        if ($label === $texts->get('menu.admin_helpers')) {
            return $access->canManageHelpers($user);
        }

        if ($label === $texts->get('menu.assign_conductor')
            || $label === $texts->get('menu.admin_chat')) {
            return $access->isOwner($user);
        }

        return false;
    }
}
