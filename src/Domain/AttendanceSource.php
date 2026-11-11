<?php

declare(strict_types=1);

namespace App\Domain;

final class AttendanceSource
{
    public const DAY_BEFORE = 'day_before';
    public const CHECKIN = 'checkin';
    public const ABSENCE_BUTTON = 'absence_button';
    public const SYSTEM = 'system';
    public const MANUAL = 'manual';
}
