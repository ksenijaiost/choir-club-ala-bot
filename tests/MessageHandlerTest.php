<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\MessageHandler;
use App\Handlers\ScheduleDeps;
use App\Repositories\BotSettingsRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\ScheduleTemplateRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\ScheduleBroadcastService;
use App\Services\ScheduleGenerator;
use App\Services\SchedulePresenter;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;

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
        $access = new AccessControl($config);

        $bot = FakeNutgram::create();
        $bot->setCommonUser(new User(id: self::TELEGRAM_ID, is_bot: false, first_name: 'Иван'));
        $bot->setCommonChat(new Chat(id: self::TELEGRAM_ID, type: ChatType::PRIVATE));

        MessageHandler::register($bot, $texts, $users, $access, $schedule);

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
        $access = new AccessControl($config);

        $bot = FakeNutgram::create();
        $bot->setCommonUser(new User(id: self::TELEGRAM_ID, is_bot: false, first_name: 'Иван'));
        $bot->setCommonChat(new Chat(id: -100_500, type: ChatType::GROUP));

        MessageHandler::register($bot, $texts, $users, $access, $schedule);

        $bot->hearText('Тест Пользователь')
            ->willStartConversation()
            ->reply()
            ->assertNoReply();

        $user = $users->findByTelegramId(self::TELEGRAM_ID);
        self::assertSame('', trim((string) ($user['display_name'] ?? '')));
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
}
