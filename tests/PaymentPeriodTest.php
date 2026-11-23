<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\PaymentPeriod;
use PHPUnit\Framework\TestCase;

final class PaymentPeriodTest extends TestCase
{
    public function testFirstAndSecondReminderUseCurrentMonth(): void
    {
        $local = new \DateTimeImmutable('2026-04-15 12:00:00', new \DateTimeZone('Asia/Almaty'));

        self::assertSame(['year' => 2026, 'month' => 4], PaymentPeriod::targetForFirstReminder($local));
        self::assertSame(['year' => 2026, 'month' => 4], PaymentPeriod::targetForSecondReminder($local));
    }

    public function testPreviousMonthAcrossYearBoundary(): void
    {
        $local = new \DateTimeImmutable('2026-01-20 12:00:00', new \DateTimeZone('Asia/Almaty'));

        self::assertSame(['year' => 2025, 'month' => 12], PaymentPeriod::previousMonth($local));
    }

    public function testAdminMonthChoicesAcrossYear(): void
    {
        $local = new \DateTimeImmutable('2026-01-10 12:00:00', new \DateTimeZone('Asia/Almaty'));
        $choices = PaymentPeriod::adminMonthChoices($local);
        self::assertSame(['year' => 2026, 'month' => 2], $choices[0]);
        self::assertSame(['year' => 2025, 'month' => 11], $choices[3]);
    }
}