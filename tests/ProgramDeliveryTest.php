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
use App\Services\ProgramDeliveryService;
use App\Services\ProgramMessageBuilder;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;

final class ProgramDeliveryTest extends TestCase
{
    public function testOneMessagePerParticipant(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->config();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $adminId = $users->create(96300, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $users->create(96301, 'А', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(96302, 'Б', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Тест');
        $programs = new ProgramRepository($pdo);
        $programId = $programs->create('Программа', $adminId, '2030-02-01');
        $programs->toggleSong($programId, $songId);
        $program = $programs->find($programId);
        self::assertNotNull($program);

        $outbound = new RecordingTelegramOutbound();
        $delivery = new ProgramDeliveryService(
            $config,
            $users,
            $programs,
            new ProgramMessageBuilder($texts, $programs),
            $texts,
            $outbound,
        );

        $bot = Nutgram::fake();
        $result = $delivery->notifyParticipants($bot, $program, false);

        self::assertSame(3, $result['total']);
        self::assertSame(3, $result['sent']);
        self::assertCount(3, $outbound->messages);
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