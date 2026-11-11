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

    public function testCatalogListsSongsWithCurrentMaterials(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(7000, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $withMaterials = $songs->create('Есть материалы');
        $songs->create('Пустая песня');

        $materials = new MaterialRepository($pdo);
        $materials->insert($withMaterials, MaterialType::NOTES, null, 'n1', 'nu1', $uploaderId, null, true);

        self::assertSame(1, $songs->countWithCurrentMaterials());
        $page = $songs->listWithCurrentMaterials(0, 10);
        self::assertCount(1, $page);
        self::assertSame('Есть материалы', $page[0]['title']);
    }

    public function testRecordingWithTwoPartsVisibleForBothInMyRecordingsQuery(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(7002, 'Админ', 'soprano_1', UserRole::ADMIN, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Хоровая');

        $materials = new MaterialRepository($pdo);
        $materials->insertRecording($songId, ['soprano_1', 'alto_1'], 'f1', 'u1', $uploaderId, null);

        $forSoprano = $materials->listSongsWithRecordingsForPart('soprano_1');
        $forAlto = $materials->listSongsWithRecordingsForPart('alto_1');

        self::assertCount(1, $forSoprano);
        self::assertCount(1, $forAlto);
        self::assertSame('Хоровая', $forSoprano[0]['title']);
        self::assertSame(1, (int) $forSoprano[0]['recording_count']);
        self::assertSame(1, (int) $forAlto[0]['recording_count']);
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
        $materials->insertRecording($s1, ['tenor'], 'f1', 'u1', $uploaderId, null);
        $materials->insertRecording($s1, ['tenor'], 'f2', 'u2', $uploaderId, null);
        $materials->insertRecording($s2, ['bass'], 'f3', 'u3', $uploaderId, null);

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
