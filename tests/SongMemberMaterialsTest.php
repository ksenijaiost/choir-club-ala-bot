<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\MaterialType;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserRepository;
use PHPUnit\Framework\TestCase;

final class SongMemberMaterialsTest extends TestCase
{
    public function testSearchFindsPartialTitle(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $songs = new SongRepository($pdo);
        $songs->create('Аллилуия весенняя');
        $songs->create('Зимняя аллилуия');

        $matches = $songs->searchByTitleFragment('алли', 10);
        self::assertCount(2, $matches);
    }

    public function testMyRecordingsListsSongsForPart(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(7001, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $s1 = $songs->create('Песня А');
        $s2 = $songs->create('Песня Б');

        $materials = new MaterialRepository($pdo);
        $materials->insert($s1, MaterialType::RECORDING, 'tenor', 'f1', 'u1', $uploaderId, null, true);
        $materials->insert($s1, MaterialType::RECORDING, 'tenor', 'f2', 'u2', $uploaderId, null, true);
        $materials->insert($s2, MaterialType::RECORDING, 'bass', 'f3', 'u3', $uploaderId, null, true);

        $forTenor = $materials->listSongsWithRecordingsForPart('tenor');
        self::assertCount(1, $forTenor);
        self::assertSame('Песня А', $forTenor[0]['title']);
        self::assertSame(2, (int) $forTenor[0]['recording_count']);
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
            webhookSecret: 'secret',
            adminPagesKey: 'admin-key',
            webhookUrl: '',
            fakeNow: null,
        );
    }
}
