<?php

declare(strict_types=1);

namespace App\Domain;

final class SecretAchievements
{
    public const EARLY_BIRD = 'early_bird';
    public const NIGHT_OWL = 'night_owl';
    public const FAST_LEARNER = 'fast_learner';
    public const PUNCTUAL_PAYER = 'punctual_payer';
    public const WORD_KEEPER = 'word_keeper';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::EARLY_BIRD,
            self::NIGHT_OWL,
            self::FAST_LEARNER,
            self::PUNCTUAL_PAYER,
            self::WORD_KEEPER,
        ];
    }

    public static function titleKey(string $code): string
    {
        return 'achievements.secret.' . $code . '_title';
    }

    public static function badgeKey(string $code): string
    {
        return 'achievements.secret.' . $code . '_badge';
    }

    public static function revealKey(string $code): string
    {
        return 'achievements.secret.' . $code . '_reveal';
    }
}