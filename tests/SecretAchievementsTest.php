<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Domain\SecretAchievements;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AboutBotHandler;
use App\Handlers\PaymentMemberHandler;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\BotSettingsRepository;
use App\Repositories\DayBeforeReminderDeliveryRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\ProgramRepository;
use App\Repositories\UserAchievementRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\AchievementServiceFactory;
use App\Tests\Support\AttendanceDepsFactory;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class SecretAchievementsTest extends TestCase
{
    public function testEarlyBirdOnlyForFirstAttendeeAndOnce(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(801, 'Первый', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(802, 'Второй', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $first = $users->findByTelegramId(801);
        $second = $users->findByTelegramId(802);
        self::assertNotNull($first);
        self::assertNotNull($second);

        $outbound = new RecordingTelegramOutbound();
        $deps = AttendanceDepsFactory::make($config, $pdo, $texts, $users, $outbound);
        $rehearsalId = $this->insertRehearsal($pdo, '2026-07-01 19:30:00');

        $deps->attendance->recordAttended($first, $rehearsalId);
        $awards = new UserAchievementRepository($pdo);
        self::assertTrue($awards->hasAward((int) $first['id'], SecretAchievements::EARLY_BIRD));

        $deps->attendance->recordAttended($second, $rehearsalId);
        self::assertFalse($awards->hasAward((int) $second['id'], SecretAchievements::EARLY_BIRD));

        $deps->attendance->recordAttended($first, $this->insertRehearsal($pdo, '2026-07-08 19:30:00'));
        $congrats = array_filter(
            $outbound->messages,
            static fn (array $m): bool => $m['chat_id'] === 801 && str_contains($m['text'], 'Секретная ачивка'),
        );
        self::assertCount(1, $congrats);
    }

    public function testNightOwlOnlyBetweenMidnightAndFiveAlmaty(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $users = new UserRepository($pdo = $this->pdo());
        $users->create(811, 'Сова', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(811);
        self::assertNotNull($member);

        $nightConfig = $this->testConfig(fakeNow: '2026-08-01T02:30:00+05:00');
        $dayConfig = $this->testConfig(fakeNow: '2026-08-01T12:00:00+05:00');
        $awards = new UserAchievementRepository($pdo);

        $night = AchievementServiceFactory::make($nightConfig, $pdo, $texts);
        $night->onMaterialsOpened($member, $nightConfig);
        self::assertTrue($awards->hasAward((int) $member['id'], SecretAchievements::NIGHT_OWL));

        $day = AchievementServiceFactory::make($dayConfig, $pdo, $texts, new RecordingTelegramOutbound());
        $day->onMaterialsOpened($member, $dayConfig);
        $owlMsgs = array_filter(
            (new RecordingTelegramOutbound())->messages,
            static fn (array $m): bool => str_contains($m['text'] ?? '', 'Ночная сова'),
        );
        self::assertCount(0, $owlMsgs);
    }

    public function testFastLearnerWithinTwentyFourHoursAfterPublish(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->create(821, 'Учень', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(821);
        self::assertNotNull($member);

        $programs = new ProgramRepository($pdo);
        $programId = $programs->create('Тест', (int) $member['id'], '2030-03-01');
        $pdo->prepare('UPDATE programs SET notified_at = ? WHERE id = ?')->execute(['2030-02-01 09:00:00', $programId]);

        $configSoon = $this->testConfig(fakeNow: '2030-02-01T10:00:00+05:00');
        $achievements = AchievementServiceFactory::make($configSoon, $pdo, $texts);
        $achievements->onProgramRecordingsFetched($member, $programId, $configSoon);
        self::assertTrue((new UserAchievementRepository($pdo))->hasAward((int) $member['id'], SecretAchievements::FAST_LEARNER));

        $configLate = $this->testConfig(fakeNow: '2030-02-03T10:00:00+05:00');
        $other = $users->findByTelegramId(821);
        self::assertNotNull($other);
        AchievementServiceFactory::make($configLate, $pdo, $texts)->onProgramRecordingsFetched($other, $programId, $configLate);
        self::assertSame(1, count((new UserAchievementRepository($pdo))->listCodesForUser((int) $member['id'])));
    }

    public function testPunctualPayerExcludesConductorAndLateConfirm(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->create(831, 'Плательщик', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(832, 'Дирижёр', 'tenor', UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(831);
        $conductor = $users->findByTelegramId(832);
        self::assertNotNull($member);
        self::assertNotNull($conductor);
        self::assertFalse(PaymentMemberHandler::isActivePayer($conductor));

        $settings = new BotSettingsRepository($pdo);
        $settings->set('payment_reminder_day_1', '15');
        $settings->set('payment_reminder_hour', '12');

        $payments = new PaymentRecordRepository($pdo);
        $year = 2026;
        $month = 9;
        $configBefore = $this->testConfig(fakeNow: '2026-09-10T10:00:00+05:00');
        $achievements = AchievementServiceFactory::make($configBefore, $pdo, $texts);

        $payments->confirmByConductor((int) $member['id'], $year, $month, (int) $conductor['id']);
        $achievements->onPaymentConfirmed($member, $year, $month, $configBefore);
        self::assertTrue((new UserAchievementRepository($pdo))->hasAward((int) $member['id'], SecretAchievements::PUNCTUAL_PAYER));

        $payments->confirmByConductor((int) $conductor['id'], $year, $month, (int) $conductor['id']);
        $achievements->onPaymentConfirmed($conductor, $year, $month, $configBefore);
        self::assertFalse((new UserAchievementRepository($pdo))->hasAward((int) $conductor['id'], SecretAchievements::PUNCTUAL_PAYER));

        $configAfter = $this->testConfig(fakeNow: '2026-09-16T10:00:00+05:00');
        $users->create(833, 'Поздно', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $late = $users->findByTelegramId(833);
        self::assertNotNull($late);
        $payments->confirmByConductor((int) $late['id'], $year, $month, (int) $conductor['id']);
        AchievementServiceFactory::make($configAfter, $pdo, $texts)->onPaymentConfirmed($late, $year, $month, $configAfter);
        self::assertFalse((new UserAchievementRepository($pdo))->hasAward((int) $late['id'], SecretAchievements::PUNCTUAL_PAYER));
    }

    public function testWordKeeperCountsManualWarnsBeforeReminderAndResetsOnUnexcused(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(841, 'Слово', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(841);
        self::assertNotNull($member);

        $outbound = new RecordingTelegramOutbound();
        $deps = AttendanceDepsFactory::make($config, $pdo, $texts, $users, $outbound);
        $marks = new AttendanceMarkRepository($pdo);
        $dayBefore = new DayBeforeReminderDeliveryRepository($pdo);

        $rehearsalIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $rehearsalIds[] = $this->insertRehearsal($pdo, sprintf('2026-10-%02d 19:30:00', $i));
        }

        foreach ($rehearsalIds as $rehearsalId) {
            $deps->attendance->recordWarned($member, $rehearsalId, AttendanceSource::ABSENCE_BUTTON, 'дела');
            $member = $users->findByTelegramId(841) ?? $member;
        }

        $awards = new UserAchievementRepository($pdo);
        self::assertTrue($awards->hasAward((int) $member['id'], SecretAchievements::WORD_KEEPER));

        $afterReminder = $this->insertRehearsal($pdo, '2026-10-20 19:30:00');
        $dayBefore->recordSent($afterReminder, (int) $member['id']);
        $deps->attendance->recordWarned($member, $afterReminder, AttendanceSource::ABSENCE_BUTTON, 'поздно');
        $keeperCongrats = array_filter(
            $outbound->messages,
            static fn (array $m): bool => $m['chat_id'] === 841 && str_contains($m['text'], 'Секретная ачивка'),
        );
        self::assertCount(1, $keeperCongrats);

        $users->create(842, 'Сброс', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $resetUser = $users->findByTelegramId(842);
        self::assertNotNull($resetUser);
        $resetDeps = AttendanceDepsFactory::make($config, $pdo, $texts, $users);
        for ($i = 1; $i <= 3; $i++) {
            $resetDeps->attendance->recordWarned($resetUser, $this->insertRehearsal($pdo, sprintf('2026-11-%02d 19:30:00', $i)), AttendanceSource::ABSENCE_BUTTON, null);
            $resetUser = $users->findByTelegramId(842) ?? $resetUser;
        }
        $rBreak = $this->insertRehearsal($pdo, '2026-11-10 19:30:00');
        $marks->upsert($rBreak, (int) $resetUser['id'], AttendanceMark::UNEXCUSED, AttendanceSource::CHECKIN, null);
        for ($i = 1; $i <= 4; $i++) {
            $resetDeps->attendance->recordWarned(
                $resetUser,
                $this->insertRehearsal($pdo, sprintf('2026-11-%02d 19:30:00', 10 + $i)),
                AttendanceSource::ABSENCE_BUTTON,
                null,
            );
        }
        self::assertFalse($awards->hasAward((int) $resetUser['id'], SecretAchievements::WORD_KEEPER));
    }

    public function testProfileSectionHiddenUntilAwarded(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->create(851, 'Профиль', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(851);
        self::assertNotNull($member);

        $achievements = AchievementServiceFactory::make($this->testConfig(), $pdo, $texts);
        self::assertSame('', $achievements->formatProfileSection($member));

        $achievements->awardIfNew($member, SecretAchievements::NIGHT_OWL);
        $section = $achievements->formatProfileSection($member);
        self::assertStringContainsString($texts->get('achievements.secret.profile_header'), $section);
        self::assertStringContainsString($texts->get('achievements.secret.night_owl_title'), $section);
        self::assertStringNotContainsString($texts->get('achievements.secret.early_bird_title'), $section);
    }

    public function testAboutMentionsSecretsWithoutSpoilers(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $body = AboutBotHandler::sectionText($texts, 'member');

        self::assertStringContainsString($texts->get('achievements.secret.about_teaser'), $body);
        foreach (SecretAchievements::all() as $code) {
            self::assertStringNotContainsString($texts->get(SecretAchievements::titleKey($code)), $body);
        }
    }

    private function pdo(): \PDO
    {
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        return $pdo;
    }

    private function insertRehearsal(\PDO $pdo, string $startsAt): int
    {
        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute([$startsAt, $startsAt, 'зал', 'scheduled']);

        return (int) $pdo->lastInsertId();
    }

    private function testConfig(?string $fakeNow = null): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
            adminChatId: '999',
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
            fakeNow: $fakeNow,
        );
    }
}