<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Repositories\BlockEventRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceDiscipline;
use App\Services\MemberBlockService;
use App\Services\NotificationRecipientResolver;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class OwnerParticipationTest extends TestCase
{
    public function testOwnerIsListedAsParticipantAndPayer(): void
    {
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(10, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->upsertOwner(99, 'Владелица');

        $participantIds = array_map(
            static fn (array $row): int => (int) $row['telegram_id'],
            $users->listActiveParticipants(),
        );
        self::assertContains(99, $participantIds);

        $payerIds = array_map(
            static fn (array $row): int => (int) $row['telegram_id'],
            $users->listActivePayers(),
        );
        self::assertContains(99, $payerIds);
    }

    public function testOwnerIsNotBlockedAfterFourthUnexcused(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->upsertOwner(99, 'Владелица');
        $owner = $users->findByTelegramId(99);
        self::assertNotNull($owner);

        $outbound = new RecordingTelegramOutbound();
        $recipients = new NotificationRecipientResolver($config, $users);
        $blocks = new MemberBlockService($users, new BlockEventRepository($pdo));
        $discipline = new AttendanceDiscipline($users, $texts, $recipients, $outbound, $blocks);

        for ($i = 0; $i < 4; $i++) {
            $discipline->onUnexcusedRecorded($owner);
            $owner = $users->findByTelegramId(99);
            self::assertNotNull($owner);
        }

        self::assertSame(UserStatus::ACTIVE, $owner['status']);
        self::assertSame(4, (int) ($owner['consecutive_unexcused'] ?? 0));

        $expelledTexts = array_filter(
            $outbound->messages,
            static fn (array $message): bool => str_contains($message['text'], 'изгнали'),
        );
        self::assertSame([], $expelledTexts);
    }

    public function testConductorIsNotBlockedAfterFourthUnexcused(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->upsertConductor(88, 'Дирижёр');
        $conductor = $users->findByTelegramId(88);
        self::assertNotNull($conductor);

        $outbound = new RecordingTelegramOutbound();
        $recipients = new NotificationRecipientResolver($config, $users);
        $blocks = new MemberBlockService($users, new BlockEventRepository($pdo));
        $discipline = new AttendanceDiscipline($users, $texts, $recipients, $outbound, $blocks);

        for ($i = 0; $i < 4; $i++) {
            $discipline->onUnexcusedRecorded($conductor);
            $conductor = $users->findByTelegramId(88);
            self::assertNotNull($conductor);
        }

        self::assertSame(UserStatus::ACTIVE, $conductor['status']);
        self::assertSame(4, (int) ($conductor['consecutive_unexcused'] ?? 0));
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '99',
            conductorTelegramId: '',
            adminChatId: '',
            dbConnection: 'sqlite',
            dbHost: '',
            dbPort: '',
            dbUsername: '',
            dbPassword: '',
            dbDatabase: ':memory:',
            timezone: 'Asia/Almaty',
            webhookSecret: 'secret',
            adminPagesKey: 'admin-key',
            webhookUrl: '',
            fakeNow: null,
        );
    }
}
