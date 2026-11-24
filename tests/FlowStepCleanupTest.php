<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Handlers\ProgramAdminHandler;
use App\Repositories\BotSettingsRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AdminChatResolver;
use App\Telegram\FlowStepCleanup;
use App\Telegram\GroupFlowSession;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\RunningMode;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class FlowStepCleanupTest extends TestCase
{
    private const ADMIN_CHAT_ID = -1007101;

    private const CONDUCTOR_TG = 71_001;

    public function testProgramCreatePurgesStepMessagesOnComplete(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        (new BotSettingsRepository($pdo))->set('admin_chat_id', (string) self::ADMIN_CHAT_ID);

        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        $conductor = $users->findByTelegramId(self::CONDUCTOR_TG);
        self::assertNotNull($conductor);
        $programId = (new \App\Repositories\ProgramRepository($pdo))->create('Тест', (int) $conductor['id'], null);

        $ctx = [
            GroupFlowSession::CTX_CHAT => self::ADMIN_CHAT_ID,
            GroupFlowSession::CTX_OWNER => self::CONDUCTOR_TG,
            'program_id' => $programId,
            FlowStepCleanup::CTX_PROMPTS => [
                ['chat_id' => self::ADMIN_CHAT_ID, 'message_id' => 501],
                ['chat_id' => self::ADMIN_CHAT_ID, 'message_id' => 502],
            ],
            FlowStepCleanup::CTX_USER_REPLIES => [
                ['chat_id' => self::ADMIN_CHAT_ID, 'message_id' => 601],
            ],
        ];

        $flows = new UserFlowRepository($pdo);
        $flows->set(self::CONDUCTOR_TG, ProgramAdminHandler::FLOW_CREATE_DATE, $ctx);

        $access = new AccessControl($config, new AdminChatResolver(new BotSettingsRepository($pdo), $config));
        $deps = $this->programDeps($pdo, $texts);

        $bot = $this->fakeGroupBot(self::CONDUCTOR_TG, self::ADMIN_CHAT_ID);
        $this->hearGroupText($bot, self::CONDUCTOR_TG, self::ADMIN_CHAT_ID, 601, '—');

        $handled = ProgramAdminHandler::handleFlowMessage(
            $bot,
            $deps,
            $access,
            self::CONDUCTOR_TG,
            '—',
            self::ADMIN_CHAT_ID,
        );
        self::assertTrue($handled);
        self::assertNull($flows->get(self::CONDUCTOR_TG));

        $deleted = $this->deletedMessageIds($bot);
        self::assertEqualsCanonicalizing([501, 502, 601], $deleted);
    }

    public function testProgramCancelPurgesStepMessages(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        (new BotSettingsRepository($pdo))->set('admin_chat_id', (string) self::ADMIN_CHAT_ID);

        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        $actor = $users->findByTelegramId(self::CONDUCTOR_TG);
        self::assertNotNull($actor);

        $ctx = [
            GroupFlowSession::CTX_CHAT => self::ADMIN_CHAT_ID,
            GroupFlowSession::CTX_OWNER => self::CONDUCTOR_TG,
            FlowStepCleanup::CTX_PROMPTS => [
                ['chat_id' => self::ADMIN_CHAT_ID, 'message_id' => 701],
            ],
            FlowStepCleanup::CTX_USER_REPLIES => [
                ['chat_id' => self::ADMIN_CHAT_ID, 'message_id' => 702],
            ],
        ];

        $flows = new UserFlowRepository($pdo);
        $flows->set(self::CONDUCTOR_TG, ProgramAdminHandler::FLOW_CREATE_TITLE, $ctx);

        $access = new AccessControl($config, new AdminChatResolver(new BotSettingsRepository($pdo), $config));
        $deps = $this->programDeps($pdo, $texts);

        $bot = $this->fakeGroupBot(self::CONDUCTOR_TG, self::ADMIN_CHAT_ID);
        $this->hearAdminChatCallback($bot, self::CONDUCTOR_TG, 'prg:cn', 800);

        $handled = ProgramAdminHandler::handleCallback($bot, $deps, $access, $actor, 'prg:cn');
        self::assertTrue($handled);
        self::assertNull($flows->get(self::CONDUCTOR_TG));

        $deleted = $this->deletedMessageIds($bot);
        self::assertEqualsCanonicalizing([701, 702], $deleted);
    }

    public function testStepPromptIsSingleMessageWithCancel(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $bot = $this->fakeGroupBot(self::CONDUCTOR_TG, self::ADMIN_CHAT_ID);

        $ctx = [];
        GroupFlowSession::bindGroup($ctx, self::ADMIN_CHAT_ID, self::CONDUCTOR_TG, 900);
        GroupFlowSession::sendTextPrompt(
            $bot,
            $texts,
            $texts->get('programs.prompt_create_title'),
            $ctx,
            'prg:cn',
        );

        $promptBodies = [];
        $tapCancel = $texts->get('common.tap_cancel_to_abort');
        $title = $texts->get('programs.prompt_create_title');
        foreach ($bot->getRequestHistory() as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'sendMessage')) {
                continue;
            }
            $body = (string) $entry['request']->getBody();
            if (str_contains($body, $title)) {
                $promptBodies[] = $body;
                self::assertStringContainsString($tapCancel, $body);
                self::assertStringContainsString('prg:cn', $body);
            }
            $decoded = json_decode($body, true);
            if (is_array($decoded) && ($decoded['text'] ?? '') === $tapCancel) {
                self::fail('Отдельное сообщение только с текстом отмены не ожидается');
            }
        }

        self::assertCount(1, $promptBodies);
    }

    /**
     * @return list<int>
     */
    private function deletedMessageIds(Nutgram $bot): array
    {
        $ids = [];
        foreach ($bot->getRequestHistory() as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'deleteMessage')) {
                continue;
            }
            $data = json_decode((string) $entry['request']->getBody(), true);
            if (!is_array($data)) {
                continue;
            }
            $ids[] = (int) ($data['message_id'] ?? 0);
        }

        return $ids;
    }

    private function hearGroupText(Nutgram $bot, int $telegramId, int $chatId, int $messageId, string $text): void
    {
        $bot->hearUpdateType(UpdateType::MESSAGE, [
            'message_id' => $messageId,
            'date' => 1_703_892_479,
            'text' => $text,
            'chat' => ['id' => $chatId, 'type' => ChatType::SUPERGROUP->value],
            'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'T'],
        ]);

        $mode = $bot->getContainer()->get(RunningMode::class);
        $modeRef = new \ReflectionObject($mode);
        $updateProp = $modeRef->getProperty('update');
        $updateProp->setAccessible(true);
        $botRef = new \ReflectionObject($bot);
        $botUpdate = $botRef->getProperty('update');
        $botUpdate->setAccessible(true);
        $botUpdate->setValue($bot, $updateProp->getValue($mode));
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
        $botRef = new \ReflectionObject($bot);
        $botUpdate = $botRef->getProperty('update');
        $botUpdate->setAccessible(true);
        $botUpdate->setValue($bot, $updateProp->getValue($mode));
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
        $interactiveMenus = new \App\Telegram\InteractiveMenuService(new \App\Repositories\UserOpenMenuRepository($pdo));

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