<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\UserRole;
use App\Domain\VoicePart;
use App\Text\TextRepository;

final class VoicePartRules
{
    public const RECORDINGS_ALL_PARTS = 'all';

    /**
     * @param array<string, mixed> $user
     */
    public static function isConductor(array $user): bool
    {
        return ($user['role'] ?? '') === UserRole::CONDUCTOR;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function requiresAssignedVoicePart(array $user): bool
    {
        if (self::isConductor($user)) {
            return false;
        }

        $role = (string) ($user['role'] ?? UserRole::MEMBER);

        return in_array($role, [UserRole::MEMBER, UserRole::ADMIN, UserRole::OWNER], true);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function hasAssignedVoicePart(array $user): bool
    {
        $part = (string) ($user['voice_part'] ?? '');

        return $part !== '' && VoicePart::isValid($part);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function seesAnyRecordingsOnSongMenu(array $user): bool
    {
        return self::isConductor($user) || !self::hasAssignedVoicePart($user);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function partLabelForDisplay(TextRepository $texts, array $user): ?string
    {
        if (self::isConductor($user)) {
            return null;
        }

        $partCode = (string) ($user['voice_part'] ?? '');
        if ($partCode !== '' && VoicePart::isValid($partCode)) {
            return $texts->get('parts.' . $partCode);
        }

        return $texts->get('profile.part_none');
    }
}