<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\StatsCommandHandler;
use App\Services\AccessControl;
use App\Config;
use PHPUnit\Framework\TestCase;

final class StatsAccessTest extends TestCase
{
    public function testConductorAndHelperCanViewStatsMemberCannot(): void
    {
        $config = Config::fromEnv();
        $access = new AccessControl($config);

        $member = ['role' => UserRole::MEMBER, 'status' => UserStatus::ACTIVE];
        $helper = ['role' => UserRole::ADMIN, 'status' => UserStatus::ACTIVE];
        $conductor = ['role' => UserRole::CONDUCTOR, 'status' => UserStatus::ACTIVE];

        self::assertFalse(StatsCommandHandler::canViewStats($access, $member));
        self::assertTrue(StatsCommandHandler::canViewStats($access, $helper));
        self::assertTrue(StatsCommandHandler::canViewStats($access, $conductor));
    }
}