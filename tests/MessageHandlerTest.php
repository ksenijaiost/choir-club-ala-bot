<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AttendanceDeps;
use App\Handlers\MessageHandler;
use App\Handlers\PaymentDeps;
use App\Repositories\PaymentRecordRepository;
use App\Services\PaymentTexts;
use App\Handlers\ScheduleDeps;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\CheckInSessionRepository;
use App\Repositories\BotSettingsRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\ScheduleTemplateRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AttendanceConductorNotifier;
use App\Services\AttendanceDiscipline;
use App\Services\AttendanceHistoryPresenter;
use App\Services\AttendanceMessageFactory;
use App\Services\AttendanceService;
use App\Services\ConsecutiveUnexcusedRecalculator;
use App\Services\MemberBlockService;
use App\Services\NotificationRecipientResolver;
use App\Repositories\BlockEventRepository;
use App\Services\ScheduleBroadcastService;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Services\ScheduleGenerator;
use App\Services\SchedulePresenter;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class MessageHandlerTest extends TestCase
{
    private const TELEGRAM_ID = 42_001;

    public function testPrivateChatTextTriggersRegistrationNameStep(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::TELEGRAM_ID, '', null, UserRole::MEMBER, UserStatus::PENDING);

        $schedule = $this->scheduleDeps($config, $pdo, $texts, $users);
        $attendance = $this->attendanceDeps($config, $pdo, $texts, $users);
        $payments = $this->paymentDeps($config, $pdo, $users, $texts);
        $access = new AccessControl($config);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->makeUser());
        $bot->setCommonChat($this->makeChat(self::TELEGRAM_ID, ChatType::PRIVATE));

        MessageHandler::register($bot, $texts, $users, $access, $schedule, $attendance, $payments);

        $bot->hearText('Тест Пользователь')
            ->willStartConversation()
            ->reply()
            ->assertReplyMessage(['text' => $texts->get('registration.ask_part')]);

        $user = $users->findByTelegramId(self::TELEGRAM_ID);
        self::assertSame('Тест Пользователь', $user['display_name'] ?? '');
    }

    public function testNonPrivateChatTextIsIgnored(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::TELEGRAM_ID, '', null, UserRole::MEMBER, UserStatus::PENDING);

        $schedule = $this->scheduleDeps($config, $pdo, $texts, $users);
        $attendance = $this->attendanceDeps($config, $pdo, $texts, $users);
        $payments = $this->paymentDeps($config, $pdo, $users, $texts);
        $access = new AccessControl($config);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->makeUser());
        $bot->setCommonChat($this->makeChat(-100_500, ChatType::GROUP));

        MessageHandler::register($bot, $texts, $users, $access, $schedule, $attendance, $payments);

        $bot->hearText('Тест Пользователь')
            ->willStartConversation()
            ->reply()
            ->assertNoReply();

        $user = $users->findByTelegramId(self::TELEGRAM_ID);
        self::assertSame('', trim((string) ($user['display_name'] ?? '')));
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->id = self::TELEGRAM_ID;
        $user->is_bot = false;
        $user->first_name = 'Иван';

        return $user;
    }

    private function makeChat(int $id, ChatType $type): Chat
    {
        $chat = new Chat();
        $chat->id = $id;
        $chat->type = $type;

        return $chat;
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

    private function scheduleDeps(
        Config $config,
        \PDO $pdo,
        TextRepository $texts,
        UserRepository $users,
    ): ScheduleDeps {
        $templates = new ScheduleTemplateRepository($pdo);
        $rehearsals = new RehearsalRepository($pdo);
        $settings = new BotSettingsRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $generator = new ScheduleGenerator($templates, $rehearsals, $settings);
        $presenter = new SchedulePresenter($rehearsals, $texts);
        $broadcast = new ScheduleBroadcastService($users, $texts);

        return new ScheduleDeps(
            $config,
            $templates,
            $rehearsals,
            $flows,
            $settings,
            $generator,
            $presenter,
            $broadcast,
            $users,
            $texts,
        );
    }

    private function paymentDeps(
        Config $config,
        \PDO $pdo,
        UserRepository $users,
        TextRepository $texts,
    ): PaymentDeps {
        $recipients = new NotificationRecipientResolver($config, $users);

        $settings = new \App\Repositories\BotSettingsRepository($pdo);
        $flows = new \App\Repositories\UserFlowRepository($pdo);
        $paymentTexts = new PaymentTexts($texts);

        return new PaymentDeps(
            $config,
            $users,
            new PaymentRecordRepository($pdo),
            $paymentTexts,
            $recipients,
            $texts,
            $settings,
            $flows,
            new \App\Services\PaymentReminderTextService($settings, $texts, $paymentTexts),
        );
    }

    private function attendanceDeps(
        Config $config,
        \PDO $pdo,
        TextRepository $texts,
        UserRepository $users,
    ): AttendanceDeps {
        $rehearsals = new RehearsalRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $marks = new AttendanceMarkRepository($pdo);
        $outbound = new RecordingTelegramOutbound();
        $recipients = new NotificationRecipientResolver($config, $users);
        $blocks = new MemberBlockService($users, new BlockEventRepository($pdo));
        $recalculator = new ConsecutiveUnexcusedRecalculator($marks, $users);
        $discipline = new AttendanceDiscipline($users, $texts, $recipients, $outbound, $blocks);
        $service = new AttendanceService($marks, $users, $discipline, $recalculator);

        return new AttendanceDeps(
            $config,
            $users,
            $rehearsals,
            $marks,
            $flows,
            $service,
            new AttendanceMessageFactory($texts),
            new AttendanceConductorNotifier($recipients, $texts, $outbound),
            $recalculator,
            new AttendanceHistoryPresenter($texts),
            $texts,
            new CheckInSessionRepository($pdo),
        );
    }
}
