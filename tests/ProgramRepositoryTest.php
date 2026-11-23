<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Repositories\ProgramRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserRepository;
use PHPUnit\Framework\TestCase;

final class ProgramRepositoryTest extends TestCase
{
    public function testToggleMoveDeleteAndArchive(): void
    {
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $creatorId = $users->create(96001, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $songs = new SongRepository($pdo);
        $a = $songs->create('Песня А');
        $b = $songs->create('Песня Б');
        $c = $songs->create('Песня В');

        $programs = new ProgramRepository($pdo);
        $id = $programs->create('Концерт', $creatorId, '2026-12-01');

        self::assertTrue($programs->toggleSong($id, $a));
        self::assertTrue($programs->toggleSong($id, $b));
        self::assertTrue($programs->toggleSong($id, $c));
        self::assertFalse($programs->toggleSong($id, $b));

        $programs->toggleSong($id, $b);
        $programs->moveUp($id, $c);
        $programs->moveDown($id, $a);

        $listed = $programs->getSongs($id);
        self::assertCount(3, $listed);

        $programs->delete($id);
        self::assertNull($programs->find($id));

        $pastId = $programs->create('Прошлый', $creatorId, '2020-01-01');
        $archived = $programs->archivePastEvents('2026-01-01');
        self::assertContains($pastId, $archived);
        self::assertSame(1, (int) ($programs->find($pastId)['is_archived'] ?? 0));
    }

    public function testChangedSinceNotify(): void
    {
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $creatorId = $users->create(96002, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $songs = new SongRepository($pdo);
        $songId = $songs->create('Одна');

        $programs = new ProgramRepository($pdo);
        $id = $programs->create('Тест', $creatorId, null);
        $programs->toggleSong($id, $songId);
        $row = $programs->find($id);
        self::assertNotNull($row);
        self::assertTrue($programs->needsFirstNotify($row));

        $programs->markNotified($id);
        $row = $programs->find($id);
        self::assertNotNull($row);
        self::assertFalse($programs->needsUpdateNotify($row));

        $programs->rename($id, 'Тест 2');
        $row = $programs->find($id);
        self::assertNotNull($row);
        self::assertTrue($programs->needsUpdateNotify($row));
    }

    private function pdo(): \PDO
    {
        $pdo = Connection::make($this->config());
        (new Migrator($pdo, 'sqlite'))->migrate();

        return $pdo;
    }

    private function config(): Config
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
            fakeNow: null,
        );
    }
}