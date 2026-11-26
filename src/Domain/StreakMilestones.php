<?php

declare(strict_types=1);

namespace App\Domain;

final class StreakMilestones
{
    /** @var list<int> */
    public const THRESHOLDS = [4, 8, 25, 50, 100, 200, 300];

    /**
     * @return list<int>
     */
    public static function all(): array
    {
        return self::THRESHOLDS;
    }

    public static function textKey(int $milestone): string
    {
        return 'streak.milestone_' . $milestone . '_title';
    }

    public static function badgeKey(int $milestone): string
    {
        return 'streak.milestone_' . $milestone . '_badge';
    }
}