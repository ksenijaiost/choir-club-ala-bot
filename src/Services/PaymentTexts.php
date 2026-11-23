<?php

declare(strict_types=1);

namespace App\Services;

use App\Text\TextRepository;

final class PaymentTexts
{
    public function __construct(private readonly TextRepository $texts)
    {
    }

    public function monthName(int $month): string
    {
        return $this->texts->get('calendar.month_' . $month);
    }

    public function monthShort(int $month): string
    {
        return mb_substr($this->monthName($month), 0, 3);
    }

    /**
     * @return array{month: string, year: string}
     */
    public function monthYearPlaceholders(int $year, int $month): array
    {
        return [
            'month' => $this->monthName($month),
            'year' => (string) $year,
        ];
    }
}
