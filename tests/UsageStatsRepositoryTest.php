<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Repositories\UsageStatsRepository;
use PHPUnit\Framework\TestCase;

final class UsageStatsRepositoryTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $config = $this->testConfig();
        $this->pdo = Connection::make($config);
        (new Migrator($this->pdo, 'sqlite'))->migrate();
    }

    public function testRecordHitUpsertsUsesAndUniqueUsers(): void
    {
        $repo = new UsageStatsRepository($this->pdo);
        $day = '2026-01-15';

        $repo->recordHit($day, 'find_song', 1001);
        $repo->recordHit($day, 'find_song', 1001);
        $repo->recordHit($day, 'find_song', 1002);

        $rows = $repo->aggregateBySection($day, $day);
        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]['uses']);
        self::assertSame(2, $rows[0]['users']);
        self::assertSame(2, $repo->activeParticipantsCount($day, $day));
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
            adminChatId: '',
            dbConnection: 'sqlite',
            dbHost: '',
            dbPort: '',
            dbDatabase: ':memory:',
            dbUsername: '',
            dbPassword: '',
            timezone: 'Asia/Almaty',
            webhookSecret: '',
            adminPagesKey: '',
            webhookUrl: '',
            fakeNow: null,
        );
    }
}