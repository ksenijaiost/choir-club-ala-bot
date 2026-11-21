<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\DbTime;
use PHPUnit\Framework\TestCase;

final class DbTimeTest extends TestCase
{
    public function testSqliteStoredUtcDisplaysInAlmaty(): void
    {
        $tz = new \DateTimeZone('Asia/Almaty');
        self::assertSame('22:23', DbTime::formatHi('sqlite', '2026-11-21 17:23:00', $tz));
    }

    public function testMysqlStoredLocalDisplaysWithoutShift(): void
    {
        $tz = new \DateTimeZone('Asia/Almaty');
        self::assertSame('22:23', DbTime::formatHi('mysql', '2026-11-21 22:23:00', $tz));
    }
}