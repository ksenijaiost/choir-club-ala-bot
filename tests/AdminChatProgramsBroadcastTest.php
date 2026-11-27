<?php

declare(strict_types=1);

namespace App\Tests;

use App\Tests\Support\AchievementServiceFactory;
use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\BroadcastWizardHandler;
use App\Handlers\HandlerRegistry;
use App\Handlers\ProgramAdminHandler;
use App\Repositories\BotSettingsRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AdminChatResolver;
use App\Telegram\GroupFlowSession;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\RunningMode;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class AdminChatProgramsBroadcastTest extends TestCase
{
    private const ADMIN_CHAT_ID = -1009001;

    private const OTHER_GROUP_ID = -1009002;

    private const CONDUCTOR_TG = 97_101;

    public function testProgramsEditCommandOpensMenuInAdminChat(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        (new BotSettingsRepository($pdo))->set('admin_chat_id', (string) self::ADMIN_CHAT_ID);

        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');

        $bot = $this->fakeGroupBot(self::CONDUCTOR_TG, self::ADMIN_CHAT_ID);
        HandlerRegistry::register($bot, $texts, $config, $pdo);

        $bot->hearText('/programs_edit')
            ->willStartConversation()
            ->reply()
            ->assertCalled('sendMessage');

        $sentToAdmin = false;
        foreach ($bot->getRequestHistory() as $entry) {
            $body = (string) $entry['request']->getBody();
            if (str_contains($body, '"chat_id":' . self::ADMIN_CHAT_ID)) {
                $sentToAdmin = true;
            }
            if (str_contains($body, 'Программы концертов')) {
                self::assertStringContainsString('Программы концертов', $body);
            }
        }

        self::assertTrue($sentToAdmin);
    }

    public function testProgramsCommandOpensMenuInAdminChat(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        (new BotSettingsRepository($pdo))->set('admin_chat_id', (string) self::ADMIN_CHAT_ID);

        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');

        $bot = $this->fakeGroupBot(self::CONDUCTOR_TG, self::ADMIN_CHAT_ID);
        HandlerRegistry::register($bot, $texts, $config, $pdo);

        $bot->hearText('/programs')
            ->willStartConversation()
            ->reply()
            ->assertCalled('sendMessage');

        $sentToAdmin = false;
        $hasOpenPrivateButton = false;
        foreach ($bot->getRequestHistory() as $entry) {
            $body = (string) $entry['request']->getBody();
            if (str_contains($body, '"chat_id":' . self::ADMIN_CHAT_ID)) {
                $sentToAdmin = true;
            }
            if (str_contains($body, 'opn:prg')) {
                $hasOpenPrivateButton = true;
            }
            if (str_contains($body, 'Программы концертов')) {
                self::assertStringContainsString('Программы концертов', $body);
            }
        }

        self::assertTrue($sentToAdmin);
        self::assertFalse($hasOpenPrivateButton);
    }

    public function testBroadcastCommandStartsWizardInAdminChat(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        (new BotSettingsRepository($pdo))->set('admin_chat_id', (string) self::ADMIN_CHAT_ID);

        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');

        $bot = $this->fakeGroupBot(self::CONDUCTOR_TG, self::ADMIN_CHAT_ID);
        HandlerRegistry::register($bot, $texts, $config, $pdo);

        $bot->hearText('/broadcast')
            ->willStartConversation()
            ->reply()
            ->assertCalled('sendMessage');

        $hint = $texts->get('broadcast.wizard_pick_kind');
        $found = false;
        foreach ($bot->getRequestHistory() as $entry) {
            $body = (string) $entry['request']->getBody();
            if (str_contains($body, '"chat_id":' . self::ADMIN_CHAT_ID) && str_contains($body, $hint)) {
                $found = true;
            }
        }
        self::assertTrue($found);

        $flows = new UserFlowRepository($pdo);
        $state = $flows->get(self::CONDUCTOR_TG);
        self::assertSame(BroadcastWizardHandler::FLOW, $state['flow'] ?? null);
        self::assertSame(self::ADMIN_CHAT_ID, (int) ($state['context'][GroupFlowSession::CTX_CHAT] ?? 0));
    }

    public function testProgramFlowTextOnlyInSessionChat(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        (new BotSettingsRepository($pdo))->set('admin_chat_id', (string) self::ADMIN_CHAT_ID);

        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');

        $flows = new UserFlowRepository($pdo);
        $ctx = [];
        GroupFlowSession::bindGroup($ctx, self::ADMIN_CHAT_ID, self::CONDUCTOR_TG, 11);
        $flows->set(self::CONDUCTOR_TG, ProgramAdminHandler::FLOW_CREATE_TITLE, $ctx);

        $access = new AccessControl($config, new AdminChatResolver(new BotSettingsRepository($pdo), $config));
        $deps = $this->programDeps($pdo, $texts);

        $wrongChatBot = $this->fakeGroupBot(self::CONDUCTOR_TG, self::OTHER_GROUP_ID);
        $handledElsewhere = ProgramAdminHandler::handleFlowMessage(
            $wrongChatBot,
            $deps,
            $access,
            self::CONDUCTOR_TG,
            'Новая программа',
            self::OTHER_GROUP_ID,
        );
        self::assertFalse($handledElsewhere);

        $adminBot = $this->fakeGroupBot(self::CONDUCTOR_TG, self::ADMIN_CHAT_ID);
        $handledInAdmin = ProgramAdminHandler::handleFlowMessage(
            $adminBot,
            $deps,
            $access,
            self::CONDUCTOR_TG,
            'Новая программа',
            self::ADMIN_CHAT_ID,
        );
        self::assertTrue($handledInAdmin);
        self::assertSame(
            ProgramAdminHandler::FLOW_CREATE_DATE,
            $flows->get(self::CONDUCTOR_TG)['flow'] ?? null,
        );
    }

    public function testProgramCallbackGuardOnStaleAdminMenu(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        (new BotSettingsRepository($pdo))->set('admin_chat_id', (string) self::ADMIN_CHAT_ID);

        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');

        (new UserOpenMenuRepository($pdo))->upsert(self::CONDUCTOR_TG, self::ADMIN_CHAT_ID, 500);

        $bot = $this->fakeGroupBot(self::CONDUCTOR_TG, self::ADMIN_CHAT_ID);
        HandlerRegistry::register($bot, $texts, $config, $pdo);

        $unavailable = $texts->get('common.unavailable');
        $this->hearAdminChatCallback($bot, self::CONDUCTOR_TG, 'prg:home', 501);
        $bot->reply()->assertCalled('answerCallbackQuery');

        $alertFound = false;
        foreach ($bot->getRequestHistory() as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'answerCallbackQuery')) {
                continue;
            }
            $data = json_decode((string) $entry['request']->getBody(), true);
            if (($data['text'] ?? '') === $unavailable) {
                $alertFound = true;
            }
        }
        self::assertTrue($alertFound);
    }

    private function hearAdminChatCallback(Nutgram $bot, int $telegramId, string $data, int $messageId): void
    {
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'id' => '1',
            'data' => $data,
            'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'T'],
            'message' => [
                'message_id' => $messageId,
                'date' => 1_703_892_479,
                'chat' => ['id' => self::ADMIN_CHAT_ID, 'type' => ChatType::SUPERGROUP->value],
            ],
        ]);

        $mode = $bot->getContainer()->get(RunningMode::class);
        $modeRef = new \ReflectionObject($mode);
        $updateProp = $modeRef->getProperty('update');
        $updateProp->setAccessible(true);
        $update = $updateProp->getValue($mode);
        self::assertInstanceOf(Update::class, $update);

        $botRef = new \ReflectionObject($bot);
        $botUpdate = $botRef->getProperty('update');
        $botUpdate->setAccessible(true);
        $botUpdate->setValue($bot, $update);
    }

    /**
     * @return \App\Handlers\ProgramDeps
     */
    private function programDeps(\PDO $pdo, TextRepository $texts): \App\Handlers\ProgramDeps
    {
        $flows = new UserFlowRepository($pdo);
        $users = new UserRepository($pdo);
        $programRepo = new \App\Repositories\ProgramRepository($pdo);
        $songRepo = new \App\Repositories\SongRepository($pdo);
        $programMessages = new \App\Services\ProgramMessageBuilder($texts, $programRepo);
        $programDelivery = new \App\Services\ProgramDeliveryService(
            $this->testConfig(),
            $users,
            $programRepo,
            $programMessages,
            $texts,
            new \App\Telegram\NutgramOutbound(Nutgram::fake()),
            new \App\Repositories\ProgramNotifyDeliveryRepository($pdo),
        );
        $interactiveMenus = new \App\Telegram\InteractiveMenuService(new UserOpenMenuRepository($pdo));

        return new \App\Handlers\ProgramDeps(
            $this->testConfig(),
            $users,
            $flows,
            $programRepo,
            $songRepo,
            $programMessages,
            $programDelivery,
            $texts,
            $interactiveMenus,
            AchievementServiceFactory::make($this->testConfig(), $pdo, $texts),
        );
    }

    private function fakeGroupBot(int $telegramId, int $chatId): Nutgram
    {
        $bot = Nutgram::fake();
        $user = new User();
        $user->id = $telegramId;
        $user->is_bot = false;
        $user->first_name = 'Тест';
        $bot->setCommonUser($user);

        $chat = new Chat();
        $chat->id = $chatId;
        $chat->type = ChatType::SUPERGROUP;
        $bot->setCommonChat($chat);

        return $bot;
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: (string) self::CONDUCTOR_TG,
            adminChatId: (string) self::ADMIN_CHAT_ID,
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