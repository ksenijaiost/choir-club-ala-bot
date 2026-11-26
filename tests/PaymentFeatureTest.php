<?php

declare(strict_types=1);

namespace App\Tests;

use App\Tests\Support\AchievementServiceFactory;
use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\PaymentCallbackHandler;
use App\Handlers\PaymentDeps;
use App\Handlers\PaymentMemberHandler;
use App\Jobs\PaymentReminderFirstJob;
use App\Jobs\PaymentReminderSecondJob;
use App\Repositories\BotSettingsRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\NotificationRecipientResolver;
use App\Services\PaymentReminderTextService;
use App\Services\PaymentTexts;
use App\Services\PaymentStatusFormatter;
use App\Telegram\InteractiveMenuService;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
final class PaymentFeatureTest extends TestCase
{
    public function testFindLastConfirmed(): void
    {
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $uid = $users->create(7101, 'Оплаты', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $payments = new PaymentRecordRepository($pdo);

        self::assertNull($payments->findLastConfirmed($uid));

        $payments->confirmByConductor($uid, 2026, 3, 1);
        $payments->confirmByConductor($uid, 2026, 5, 1);

        $last = $payments->findLastConfirmed($uid);
        self::assertNotNull($last);
        self::assertSame(5, (int) $last['period_month']);
    }

    public function testDeferByMemberAndClaimClearsDeferStatus(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $uid = $users->create(7102, 'Отложил', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $payments = new PaymentRecordRepository($pdo);

        $payments->deferByMember($uid, 2026, 4, 'на следующей неделе');
        $row = $payments->find($uid, 2026, 4);
        self::assertTrue($payments->isMemberDeferred($row));
        self::assertSame(
            $texts->get('payment.status_deferred_comment', ['comment' => 'на следующей неделе']),
            PaymentStatusFormatter::forRecord($texts, $payments, $row),
        );

        $payments->claimByMember($uid, 2026, 4);
        $row = $payments->find($uid, 2026, 4);
        self::assertFalse($payments->isMemberDeferred($row));
        self::assertTrue($payments->hasMemberClaim($row));
        self::assertSame('на следующей неделе', $row['member_comment']);
    }

    public function testPayLaterSkipStoresDefer(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '999001',
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
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $tid = 7103;
        $users->create($tid, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->upsertConductor(999001, 'Дирижёр');

        $deps = $this->paymentDeps($config, $pdo, $texts, $users);

        $bot = Nutgram::fake();
        PaymentCallbackHandler::handle($bot, $deps, new AccessControl($config), 'pay:later_skip:2026:4', $tid);

        $member = $users->findByTelegramId($tid);
        self::assertNotNull($member);
        $row = $deps->payments->find((int) $member['id'], 2026, 4);
        self::assertTrue($deps->payments->isMemberDeferred($row));
    }

    public function testDeferCommentFlowSavesText(): void
    {
        $config = $this->testConfig();
        $pdo = $this->pdo();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $users = new UserRepository($pdo);
        $tid = 7104;
        $users->create($tid, 'Коммент', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);
        $deps = $this->paymentDeps($config, $pdo, $texts, $users);

        $bot = Nutgram::fake();
        $deps->flows->set($tid, PaymentMemberHandler::FLOW_DEFER_COMMENT, ['year' => 2026, 'month' => 6]);
        PaymentMemberHandler::handleFlowMessage($bot, $deps, $tid, 'до 25-го');

        $member = $users->findByTelegramId($tid);
        self::assertNotNull($member);
        $row = $deps->payments->find((int) $member['id'], 2026, 6);
        self::assertSame('до 25-го', $row['member_comment']);
        self::assertNull($deps->flows->get($tid));
    }

    public function testMemberPaymentScreenWithPreviousMonthButton(): void
    {
        putenv('FAKE_NOW=2026-05-12T10:00:00+05:00');
        $config = $this->testConfig();
        $pdo = $this->pdo();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $users = new UserRepository($pdo);
        $tid = 7105;
        $uid = $users->create($tid, 'Экран', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $payments = new PaymentRecordRepository($pdo);
        $payments->confirmByConductor($uid, 2026, 3, 1);

        $deps = $this->paymentDeps($config, $pdo, $texts, $users);
        $member = $users->findByTelegramId($tid);
        self::assertNotNull($member);

        $bot = Nutgram::fake();
        PaymentMemberHandler::openHome($bot, $deps, $member);

        $sent = '';
        foreach ($bot->getRequestHistory() as $entry) {
            $body = json_decode((string) $entry['request']->getBody(), true);
            if (is_array($body) && isset($body['text'])) {
                $sent = (string) $body['text'];
            }
        }
        self::assertStringContainsString(
            $texts->get('payment.screen_last_confirmed', [
                'month' => 'март',
                'year' => '2026',
            ]),
            $sent,
        );
        $bot->assertRaw(static function (\GuzzleHttp\Psr7\Request $request): bool {
            $body = (string) $request->getBody();

            return str_contains($body, 'pay:ok:2026:4');
        });
        putenv('FAKE_NOW');
    }

    public function testSecondReminderIncludesDeferComment(): void
    {
        putenv('FAKE_NOW=2026-04-20T12:00:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $uid = $users->create(7106, 'Двадцатое', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $payments = new PaymentRecordRepository($pdo);
        $payments->deferByMember($uid, 2026, 4, 'в конце месяца');

        $outbound = new RecordingTelegramOutbound();
        $settings = new BotSettingsRepository($pdo);
        $paymentTexts = new PaymentTexts($texts);
        $reminderTexts = new PaymentReminderTextService($settings, $texts, $paymentTexts);
        $job = new PaymentReminderSecondJob(
            $users,
            $payments,
            $settings,
            new JobDeliveryRepository($pdo),
            $texts,
            $paymentTexts,
            $reminderTexts,
            $outbound,
        );

        $result = $job->runIfDue($config);
        self::assertSame(1, $result['sent']);
        self::assertStringContainsString('в конце месяца', $outbound->messages[0]['text']);

        putenv('FAKE_NOW');
    }

    public function testFirstReminderOn15thTargetsCurrentMonth(): void
    {
        putenv('FAKE_NOW=2026-04-15T12:10:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->create(7107, 'Плательщик', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $outbound = new RecordingTelegramOutbound();
        $settings = new BotSettingsRepository($pdo);
        $paymentTexts = new PaymentTexts($texts);
        $reminderTexts = new PaymentReminderTextService($settings, $texts, $paymentTexts);
        $job = new PaymentReminderFirstJob(
            $users,
            new PaymentRecordRepository($pdo),
            $settings,
            new JobDeliveryRepository($pdo),
            $texts,
            $reminderTexts,
            $outbound,
        );

        $result = $job->runIfDue($config);
        self::assertSame(1, $result['sent']);
        self::assertStringContainsString('апрел', mb_strtolower($outbound->messages[0]['text']));

        putenv('FAKE_NOW');
    }

    private function pdo(): \PDO
    {
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        return $pdo;
    }

    /**
     * @return PaymentDeps
     */
    private function paymentDeps(Config $config, \PDO $pdo, TextRepository $texts, UserRepository $users): PaymentDeps
    {
        $settings = new BotSettingsRepository($pdo);
        $paymentTexts = new PaymentTexts($texts);

        return new PaymentDeps(
            $config,
            $users,
            new PaymentRecordRepository($pdo),
            $paymentTexts,
            new NotificationRecipientResolver($config, $users),
            $texts,
            $settings,
            new UserFlowRepository($pdo),
            new PaymentReminderTextService($settings, $texts, $paymentTexts),
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
            AchievementServiceFactory::make($config, $pdo, $texts)
        );
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