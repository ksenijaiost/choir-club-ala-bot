<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Jobs\ProgramArchiveJob;
use App\Repositories\ProgramRepository;
use App\Repositories\UserRepository;
use PHPUnit\Framework\TestCase;

final class ProgramArchiveJobTest extends TestCase
{
    public function testArchivesPastPrograms(): void
    {
        $config = $this->config();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $creatorId = $users->create(96100, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $programs = new ProgramRepository($pdo);
        $future = $programs->create('Будущее', $creatorId, '2030-06-01');
        $past = $programs->create('Прошлое', $creatorId, '2019-06-01');

        $job = new ProgramArchiveJob($programs);
        $frozen = $this->configWithNow('2026-06-15T12:00:00+05:00');
        $result = $job->run($frozen);

        self::assertSame(1, $result['archived']);
        self::assertContains($past, $result['ids']);
        self::assertNotContains($future, $result['ids']);
    }

    private function configWithNow(string $fakeNow): Config
    {
        return new Config(
            botToken: 't',
            ownerTelegramId: '1',
            conductorTelegramId: '2',
            adminChatId: '',
            dbConnection: 'sqlite',
            dbHost: '',
            dbPort: '',
            dbDatabase: ':memory:',
            dbUsername: '',
            dbPassword: '',
            timezone: 'Asia/Almaty',
            webhookSecret: 's',
            adminPagesKey: 'k',
            webhookUrl: '',
            fakeNow: $fakeNow,
        );
    }

    private function config(): Config
    {
        return $this->configWithNow('2026-06-15T12:00:00+05:00');
    }
}