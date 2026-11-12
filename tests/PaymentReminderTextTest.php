<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AboutBotHandler;
use App\Handlers\PaymentAdminHandler;
use App\Handlers\PaymentDeps;
use App\Handlers\PaymentReminderTextAdminHandler;
use App\Jobs\PaymentReminderFirstJob;
use App\Repositories\BotSettingsRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\PaymentReminderTextService;
use App\Services\PaymentTexts;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class PaymentReminderTextTest extends TestCase
{
    public function testJobUsesCustomTextAndMonthPlaceholder(): void
    {
        putenv('FAKE_NOW=2026-04-23T12:10:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $settings = new BotSettingsRepository($pdo);
        $settings->set(
            PaymentReminderTextService::SETTING_1,
            'Оплата за {month} — не забудь.',
        );

        $users = new UserRepository($pdo);
        $users->create(7010, 'Плательщик', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $paymentTexts = new PaymentTexts($texts);
        $reminderTexts = new PaymentReminderTextService($settings, $texts, $paymentTexts);
        $outbound = new RecordingTelegramOutbound();

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
        self::assertStringContainsString('Оплата за май', $outbound->messages[0]['text']);

        putenv('FAKE_NOW');
    }

    public function testResetRestoresDefaultReminder(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $settings = new BotSettingsRepository($pdo);
        $paymentTexts = new PaymentTexts($texts);
        $service = new PaymentReminderTextService($settings, $texts, $paymentTexts);

        $settings->set(PaymentReminderTextService::SETTING_1, 'Свой текст');
        self::assertSame('Свой текст', $service->templateForReminder(1));

        $service->resetToDefaults();
        self::assertStringContainsString(
            'Не забудь оплатить хор',
            $service->templateForReminder(1),
        );
        self::assertNull($settings->get(PaymentReminderTextService::SETTING_1));
    }

    public function testHelperCannotOpenReminderTextsCallback(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $tid = 7021;
        $users->create($tid, 'Помощник', 'alto_2', UserRole::ADMIN, UserStatus::ACTIVE);
        $helper = $users->findByTelegramId($tid);
        self::assertNotNull($helper);

        $deps = $this->paymentDeps($config, $pdo, $texts, $users);
        $access = new AccessControl($config);

        $bot = Nutgram::fake();
        PaymentReminderTextAdminHandler::handleCallback(
            $bot,
            $deps,
            $access,
            $helper,
            PaymentReminderTextAdminHandler::CB_HOME,
            $tid,
        );

        self::assertNull($deps->settings->get(PaymentReminderTextService::SETTING_1));
    }

    public function testMemberCannotOpenReminderTextsCallback(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $tid = 7020;
        $users->create($tid, 'Участник', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId($tid);
        self::assertNotNull($member);

        $deps = $this->paymentDeps($config, $pdo, $texts, $users);
        $access = new AccessControl($config);

        $bot = Nutgram::fake();

        PaymentReminderTextAdminHandler::handleCallback(
            $bot,
            $deps,
            $access,
            $member,
            PaymentReminderTextAdminHandler::CB_HOME,
            $tid,
        );

        $bot->assertCalled('answerCallbackQuery', 1);
        $bot->assertCalled('sendMessage', 0);
    }

    public function testConductorPaymentAdminShowsReminderTextsButton(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $tid = 7030;
        $users->upsertConductor($tid, 'Дирижёр');
        $conductor = $users->findByTelegramId($tid);
        self::assertNotNull($conductor);

        $deps = $this->paymentDeps($config, $pdo, $texts, $users);
        $access = new AccessControl($config);

        $bot = Nutgram::fake();
        $user = new User();
        $user->id = $tid;
        $user->first_name = 'Дирижёр';
        $bot->setCommonUser($user);
        PaymentAdminHandler::openHome($bot, $deps, $access, $conductor);

        $bot->assertReplyText($texts->get('payment.admin_pick_month'));
        $buttonLabel = $texts->get('payment.reminder_texts_button');
        $bot->assertRaw(static function (\GuzzleHttp\Psr7\Request $request) use ($buttonLabel): bool {
            $body = (string) $request->getBody();

            return str_contains($body, 'pay:rt:home') && str_contains($body, $buttonLabel);
        });
    }

    public function testMemberAboutPaymentSectionHasNoReminderQuotes(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $body = AboutBotHandler::sectionText($texts, 'member');

        self::assertStringNotContainsString('Не забудь оплатить хор', $body);
        self::assertStringNotContainsString('Давай денег', $body);
        self::assertStringContainsString('23-го числа в 12:00', $body);
    }

    public function testEditFlowSavesViaCallback(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $tid = 7040;
        $users->upsertConductor($tid, 'Дирижёр');
        $conductor = $users->findByTelegramId($tid);
        self::assertNotNull($conductor);

        $deps = $this->paymentDeps($config, $pdo, $texts, $users);
        $access = new AccessControl($config);

        $deps->flows->set($tid, 'payment_reminder_edit', [
            'which' => 2,
            'step' => 'preview',
            'draft' => 'Плати за {month}!',
        ]);

        $bot = Nutgram::fake();

        PaymentReminderTextAdminHandler::handleCallback(
            $bot,
            $deps,
            $access,
            $conductor,
            'pay:rt:save:2',
            $tid,
        );

        self::assertSame('Плати за {month}!', $deps->settings->get(PaymentReminderTextService::SETTING_2));
        self::assertNull($deps->flows->get($tid));
        $bot->assertCalled('sendMessage', 2);
    }

    private function paymentDeps(
        Config $config,
        \PDO $pdo,
        TextRepository $texts,
        UserRepository $users,
    ): PaymentDeps {
        $settings = new BotSettingsRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $paymentTexts = new PaymentTexts($texts);
        $recipients = new \App\Services\NotificationRecipientResolver($config, $users);

        return new PaymentDeps(
            $config,
            $users,
            new PaymentRecordRepository($pdo),
            $paymentTexts,
            $recipients,
            $texts,
            $settings,
            $flows,
            new PaymentReminderTextService($settings, $texts, $paymentTexts),
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
