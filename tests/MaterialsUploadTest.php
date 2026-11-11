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
use App\Services\MaterialsService;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class MaterialsUploadTest extends TestCase
{
    public function testDuplicateFileUniqueIdIsDetected(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(8001, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Тестовая песня');

        $materials = new MaterialRepository($pdo);
        $materials->insert(
            $songId,
            MaterialType::NOTES,
            null,
            'file-1',
            'unique-abc',
            $uploaderId,
            null,
            true,
        );

        $service = new MaterialsService($materials, $songs, $texts);
        $dup = $service->findDuplicate('unique-abc');
        self::assertNotNull($dup);
        self::assertSame('Тестовая песня', $dup['song_title']);
    }

    public function testNotesReplaceArchivesPrevious(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');

        $users = new UserRepository($pdo);
        $users->create(8002, 'Дирижёр', 'tenor', UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $uploader = $users->findByTelegramId(8002);
        self::assertNotNull($uploader);

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Аллилуия');

        $materials = new MaterialRepository($pdo);
        $service = new MaterialsService($materials, $songs, $texts);

        $service->saveUpload($uploader, $songId, MaterialType::NOTES, null, 'f1', 'u1', null, true);
        $service->saveUpload($uploader, $songId, MaterialType::NOTES, null, 'f2', 'u2', null, true);

        $current = $materials->findCurrentSingleton($songId, MaterialType::NOTES);
        self::assertNotNull($current);
        self::assertSame('u2', $current['telegram_file_unique_id']);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM materials WHERE song_id = ? AND type = ? AND is_current = 0'
        );
        $stmt->execute([$songId, MaterialType::NOTES]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testRecordingAddsWithoutReplacing(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');

        $users = new UserRepository($pdo);
        $users->create(8003, 'Админ', 'alto_1', UserRole::ADMIN, UserStatus::ACTIVE);
        $uploader = $users->findByTelegramId(8003);
        self::assertNotNull($uploader);

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Песня');

        $materials = new MaterialRepository($pdo);
        $service = new MaterialsService($materials, $songs, $texts);

        $service->saveUpload($uploader, $songId, MaterialType::RECORDING, ['tenor'], 'r1', 'ru1', null, true);
        $service->saveUpload($uploader, $songId, MaterialType::RECORDING, ['tenor'], 'r2', 'ru2', null, true);

        self::assertCount(2, $materials->listCurrentRecordings($songId, 'tenor'));
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
            adminChatId: '-1001',
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
