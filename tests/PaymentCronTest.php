<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Jobs\PaymentReminderFirstJob;
use App\Jobs\PaymentReminderSecondJob;
use App\Repositories\BotSettingsRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\UserRepository;
use App\Services\PaymentTexts;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class PaymentCronTest extends TestCase
{
    public function testFirstReminderIdempotentOn23rd(): void
    {
        putenv('FAKE_NOW=2026-04-23T12:10:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(7001, 'Плательщик', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $outbound = new RecordingTelegramOutbound();
        $payments = new PaymentRecordRepository($pdo);

        $job = new PaymentReminderFirstJob(
            $users,
            $payments,
            new BotSettingsRepository($pdo),
            new JobDeliveryRepository($pdo),
            $texts,
            new PaymentTexts($texts),
            $outbound,
        );

        $first = $job->runIfDue($config);
        self::assertSame(1, $first['sent']);
        self::assertCount(1, $outbound->messages);

        $second = $job->runIfDue($config);
        self::assertSame(0, $second['sent']);
        self::assertCount(1, $outbound->messages);

        putenv('FAKE_NOW');
    }

    public function testFirstReminderSkipsWhenConfirmed(): void
    {
        putenv('FAKE_NOW=2026-04-23T12:10:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uid = $users->create(7003, 'Оплачен', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $payments = new PaymentRecordRepository($pdo);
        $payments->confirmByConductor($uid, 2026, 5, 1);

        $outbound = new RecordingTelegramOutbound();
        $job = new PaymentReminderFirstJob(
            $users,
            $payments,
            new BotSettingsRepository($pdo),
            new JobDeliveryRepository($pdo),
            $texts,
            new PaymentTexts($texts),
            $outbound,
        );

        $result = $job->runIfDue($config);
        self::assertSame(0, $result['sent']);
        self::assertSame(1, $result['skipped_confirmed']);
        self::assertCount(0, $outbound->messages);

        putenv('FAKE_NOW');
    }

    public function testSecondReminderSkipsWhenConfirmed(): void
    {
        putenv('FAKE_NOW=2026-05-01T12:00:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uid = $users->create(7002, 'Оплачен', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $payments = new PaymentRecordRepository($pdo);
        $payments->confirmByConductor($uid, 2026, 5, 1);

        $outbound = new RecordingTelegramOutbound();
        $job = new PaymentReminderSecondJob(
            $users,
            $payments,
            new BotSettingsRepository($pdo),
            new JobDeliveryRepository($pdo),
            $texts,
            new PaymentTexts($texts),
            $outbound,
        );

        $result = $job->runIfDue($config);
        self::assertSame(0, $result['sent']);
        self::assertSame(1, $result['skipped_confirmed']);
        self::assertCount(0, $outbound->messages);

        putenv('FAKE_NOW');
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
            fakeNow: getenv('FAKE_NOW') !== false ? (string) getenv('FAKE_NOW') : null,
        );
    }
}
