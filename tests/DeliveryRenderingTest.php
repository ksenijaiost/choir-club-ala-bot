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
use App\Handlers\ProgramAdminHandler;
use App\Repositories\BotSettingsRepository;
use App\Repositories\BroadcastRepository;
use App\Repositories\ProgramNotifyDeliveryRepository;
use App\Repositories\ProgramRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AdminChatResolver;
use App\Services\BroadcastDeliveryService;
use App\Services\BroadcastKeyboardBuilder;
use App\Services\BroadcastStatusPresenter;
use App\Services\ProgramDeliveryService;
use App\Services\ProgramMessageBuilder;
use App\Services\TelegramDeliveryReason;
use App\Services\UserRolePresenter;
use App\Telegram\GroupFlowSession;
use App\Telegram\InteractiveMenuService;
use App\Tests\Support\ThrowingRecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\RunningMode;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class DeliveryRenderingTest extends TestCase
{
    private const ADMIN_CHAT = -1009001;

    private const CONDUCTOR_TG = 97101;

    private const BLOCKED_TG = 1008205296;

    public function testProgramNotifyUsesHtmlEscape(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->config());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $programs = new ProgramRepository($pdo);
        $users = new UserRepository($pdo);
        $adminId = $users->create(self::CONDUCTOR_TG, 'А', 'tenor', UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $users->create(97002, 'Б', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $songs = new SongRepository($pdo);
        $songId = $songs->create('A & B <test>');
        $programId = $programs->create('Prog <>&', $adminId, null);
        $programs->toggleSong($programId, $songId);

        $builder = new ProgramMessageBuilder($texts, $programs);
        $body = $builder->notifyBody($programs->find($programId) ?? [], false);

        self::assertStringContainsString('<b>', $body);
        self::assertStringContainsString('Prog &lt;&gt;&amp;', $body);
        self::assertStringContainsString('A &amp; B &lt;test&gt;', $body);
        self::assertStringNotContainsString('<test>', $body);
    }

    public function testTelegramDeliveryReasonMapping(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');

        self::assertSame(
            TelegramDeliveryReason::BLOCKED,
            TelegramDeliveryReason::codeFromThrowable(new TelegramException('Forbidden: bot was blocked by the user', 403)),
        );
        self::assertSame(
            TelegramDeliveryReason::NEVER_STARTED,
            TelegramDeliveryReason::codeFromThrowable(new TelegramException('Bad Request: chat not found', 400)),
        );
        self::assertStringContainsString(
            'заблокирован',
            TelegramDeliveryReason::label($texts, TelegramDeliveryReason::BLOCKED),
        );

        $summary = TelegramDeliveryReason::formatSummary($texts, 2, 3, [
            ['name' => 'Иван', 'reason_label' => TelegramDeliveryReason::label($texts, TelegramDeliveryReason::BLOCKED)],
        ]);
        self::assertStringContainsString('Доставлено 2 из 3', $summary);
        self::assertStringContainsString('Иван', $summary);
    }

    public function testProgramDetailEditUsesHtmlParseMode(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->config();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        (new BotSettingsRepository($pdo))->set('admin_chat_id', (string) self::ADMIN_CHAT);

        $users = new UserRepository($pdo);
        $adminId = $users->create(self::CONDUCTOR_TG, 'Дирижёр', 'tenor', UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $programs = new ProgramRepository($pdo);
        $programId = $programs->create('Тест <x>', $adminId, null);
        $songs = new SongRepository($pdo);
        $programs->toggleSong($programId, $songs->create('Песня & co'));

        $bot = $this->fakeGroupBot(self::CONDUCTOR_TG, self::ADMIN_CHAT);
        $this->hearAdminChatCallback($bot, self::CONDUCTOR_TG, 'prg:st:' . $programId, 99);

        $flows = new UserFlowRepository($pdo);
        $programMessages = new ProgramMessageBuilder($texts, $programs);
        $delivery = new ProgramDeliveryService(
            $config,
            $users,
            $programs,
            $programMessages,
            $texts,
            new ThrowingRecordingTelegramOutbound(self::BLOCKED_TG),
            new ProgramNotifyDeliveryRepository($pdo),
        );
        $deps = new \App\Handlers\ProgramDeps(
            $config,
            $users,
            $flows,
            $programs,
            $songs,
            $programMessages,
            $delivery,
            $texts,
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
            AchievementServiceFactory::make($config, $pdo, $texts),
        );
        $access = new AccessControl($config, new AdminChatResolver(new BotSettingsRepository($pdo), $config));
        $actor = $users->findByTelegramId(self::CONDUCTOR_TG);
        self::assertNotNull($actor);

        ProgramAdminHandler::handleCallback($bot, $deps, $access, $actor, 'prg:st:' . $programId);

        $parseModeHtml = false;
        $escapedTitle = false;
        foreach ($bot->getRequestHistory() as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'editMessageText')) {
                continue;
            }
            $rawBody = (string) $entry['request']->getBody();
            if (str_contains($rawBody, "parse_mode\r\n\r\nHTML") || str_contains($rawBody, 'parse_mode')) {
                if (str_contains($rawBody, 'HTML')) {
                    $parseModeHtml = true;
                }
            }
            if (str_contains($rawBody, '&lt;x&gt;')) {
                $escapedTitle = true;
            }
        }
        self::assertTrue($parseModeHtml, 'editMessageText must use HTML parse mode');
        self::assertTrue($escapedTitle, 'program title metacharacters must be escaped');
    }

    public function testBroadcastWizardSendsSummaryToGroupChat(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->config();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        (new BotSettingsRepository($pdo))->set('admin_chat_id', (string) self::ADMIN_CHAT);

        $users = new UserRepository($pdo);
        $users->create(self::CONDUCTOR_TG, 'Дирижёр', null, UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $users->create(88002, 'Анна', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(self::BLOCKED_TG, 'Blocked', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $broadcasts = new BroadcastRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $rolePresenter = new UserRolePresenter($texts);
        $keyboards = new BroadcastKeyboardBuilder($texts);
        $outbound = new ThrowingRecordingTelegramOutbound(self::BLOCKED_TG);
        $delivery = new BroadcastDeliveryService($users, $broadcasts, $texts, $rolePresenter, $keyboards, $outbound);
        $handlerDeps = new \App\Handlers\BroadcastDeps(
            $config,
            $users,
            $broadcasts,
            $flows,
            $texts,
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
            $delivery,
            new BroadcastStatusPresenter($texts, $broadcasts, $users),
            $keyboards,
            $rolePresenter,
            $outbound,
        );

        $ctx = [
            'step' => 'preview',
            'kind' => BroadcastRepository::KIND_TEXT,
            'text' => 'Hello',
            GroupFlowSession::CTX_CHAT => self::ADMIN_CHAT,
            GroupFlowSession::CTX_OWNER => self::CONDUCTOR_TG,
        ];
        $flows->set(self::CONDUCTOR_TG, BroadcastWizardHandler::FLOW, $ctx);
        (new UserOpenMenuRepository($pdo))->upsert(self::CONDUCTOR_TG, self::ADMIN_CHAT, 12);

        $bot = $this->fakeGroupBot(self::CONDUCTOR_TG, self::ADMIN_CHAT);
        $this->hearAdminChatCallback($bot, self::CONDUCTOR_TG, 'bc:w:sn', 12);

        $author = $users->findByTelegramId(self::CONDUCTOR_TG);
        self::assertNotNull($author);
        $access = new AccessControl($config, new AdminChatResolver(new BotSettingsRepository($pdo), $config));
        BroadcastWizardHandler::handleCallback($bot, $handlerDeps, $access, $author, 'bc:w:sn');

        $summaryToGroup = false;
        foreach ($bot->getRequestHistory() as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'sendMessage')) {
                continue;
            }
            $data = json_decode((string) $entry['request']->getBody(), true);
            if ((int) ($data['chat_id'] ?? 0) === self::ADMIN_CHAT
                && str_contains((string) ($data['text'] ?? ''), 'Доставлено')
            ) {
                $summaryToGroup = true;
            }
        }
        self::assertTrue($summaryToGroup);
    }

    private function config(): Config
    {
        return new Config(
            botToken: 't',
            ownerTelegramId: '1',
            conductorTelegramId: (string) self::CONDUCTOR_TG,
            adminChatId: (string) self::ADMIN_CHAT,
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

    private function hearAdminChatCallback(Nutgram $bot, int $telegramId, string $data, int $messageId): void
    {
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'id' => '1',
            'data' => $data,
            'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'T'],
            'message' => [
                'message_id' => $messageId,
                'date' => 1_703_892_479,
                'chat' => ['id' => self::ADMIN_CHAT, 'type' => ChatType::SUPERGROUP->value],
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
}