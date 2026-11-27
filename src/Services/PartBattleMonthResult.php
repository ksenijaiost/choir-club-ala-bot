<?php

declare(strict_types=1);

namespace App\Services;

final class PartBattleMonthResult
{
    /**
     * @param list<array{part: string, member_count: int, attended: int, total: int, percent: int, rank: int}> $rows
     * @param list<string> $winners
     */
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly int $rehearsalCount,
        public readonly array $rows,
        public readonly array $winners,
    ) {
    }

    public function hasRehearsals(): bool
    {
        return $this->rehearsalCount > 0;
    }
}