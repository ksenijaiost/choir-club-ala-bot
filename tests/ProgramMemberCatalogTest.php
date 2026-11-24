<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\HandlerRegistry;
use App\Handlers\ProgramDeps;
use App\Handlers\ProgramMemberHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\ProgramRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\ProgramDeliveryService;
use App\Services\ProgramMessageBuilder;
use App\Telegram\InteractiveMenuService;
use App\Tests\Support\MaterialsTestDeps;
use App\Tests\Support\NutgramTestConfig;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\RunningMode\Fake;
use SergiX44\Nutgram\RunningMode\RunningMode;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class ProgramMemberCatalogTest extends TestCase
{
    private const MEMBER_TG = 97101;

    private const CONDUCTOR_TG = 97102;

    public function testCatalogEmptySendsPlainText(): void
    {
        $bundle = $this->bundle();
        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));
        ProgramMemberHandler::openCatalog($bot, $bundle['programs'], $member);

        $bot->assertReplyText($bundle['texts']->get('programs.member_list_empty'));
    }

    public function testCatalogListsActiveProgram(): void
    {
        $bundle = $this->bundle();
        $admin = $bundle['users']->findByTelegramId(97100);
        self::assertNotNull($admin);
        $programId = $bundle['programsRepo']->create('Весна', (int) $admin['id'], '2030-03-01');

        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));
        ProgramMemberHandler::openCatalog($bot, $bundle['programs'], $member);

        $callbacks = $this->callbacks($bot);
        self::assertContains('prg:in:' . $programId, $callbacks);
        self::assertNotContains('prg:mr:ls:0', $callbacks);
        $bot->assertCalled('sendMessage');
    }

    public function testPrivateProgramsCommandOpensCatalog(): void
    {
        $bundle = $this->bundle();

        $bot = NutgramTestConfig::fakeBot();
        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));
        $bot->setCommonChat(Chat::make(id: self::MEMBER_TG, type: ChatType::PRIVATE));
        HandlerRegistry::register($bot, $bundle['texts'], $this->config(), $bundle['pdo']);

        $bot->hearText('/programs')
            ->reply()
            ->assertReplyText($bundle['texts']->get('programs.member_list_empty'));
    }

    public function testProgramsEditForbiddenForMember(): void
    {
        $bundle = $this->bundle();

        $bot = NutgramTestConfig::fakeBot();
        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));
        $bot->setCommonChat(Chat::make(id: self::MEMBER_TG, type: ChatType::PRIVATE));
        HandlerRegistry::register($bot, $bundle['texts'], $this->config(), $bundle['pdo']);

        $bot->hearText('/programs_edit')
            ->reply()
            ->assertReplyText($bundle['texts']->get('programs.forbidden'));
    }

    public function testProgramsEditOpensAdminForConductor(): void
    {
        $bundle = $this->bundle(withConductor: true);

        $bot = NutgramTestConfig::fakeBot();
        $bot->setCommonUser($this->tgUser(self::CONDUCTOR_TG));
        $bot->setCommonChat(Chat::make(id: self::CONDUCTOR_TG, type: ChatType::PRIVATE));
        HandlerRegistry::register($bot, $bundle['texts'], $this->config(), $bundle['pdo']);

        $bot->hearText('/programs_edit')
            ->willStartConversation()
            ->reply()
            ->assertCalled('sendMessage');

        $found = false;
        foreach ($bot->getRequestHistory() as $entry) {
            $body = (string) $entry['request']->getBody();
            if (str_contains($body, 'Программы концертов')) {
                $found = true;
            }
        }
        self::assertTrue($found);
    }

    public function testMemberOpensCardShowsActionButtons(): void
    {
        $bundle = $this->bundle();
        $admin = $bundle['users']->findByTelegramId(97100);
        self::assertNotNull($admin);
        $programId = $bundle['programsRepo']->create('Карточка', (int) $admin['id'], '2030-04-01');

        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = $this->botWithCallback(self::MEMBER_TG, 'prg:in:' . $programId);
        ProgramMemberHandler::editProgramCard($bot, $bundle['programs'], $programId, self::MEMBER_TG);

        $callbacks = $this->callbacks($bot);
        self::assertContains('prg:nd:' . $programId, $callbacks);
        self::assertContains('prg:pk:' . $programId, $callbacks);
    }

    /**
     * @return array<string, mixed>
     */
    private function bundle(bool $withConductor = false): array
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->config();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(97100, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $users->create(self::MEMBER_TG, 'Участник', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        if ($withConductor) {
            $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        }
        $flows = new UserFlowRepository($pdo);
        $interactive = new InteractiveMenuService(new UserOpenMenuRepository($pdo));
        $songs = new SongRepository($pdo);
        $materialsRepo = new MaterialRepository($pdo);
        $materialsDeps = MaterialsTestDeps::build($config, $users, $flows, $songs, $materialsRepo, $texts, $interactive);
        $programsRepo = new ProgramRepository($pdo);
        $programMessages = new ProgramMessageBuilder($texts, $programsRepo);
        $delivery = new ProgramDeliveryService(
            $config,
            $users,
            $programsRepo,
            $programMessages,
            $texts,
            new RecordingTelegramOutbound(),
            new \App\Repositories\ProgramNotifyDeliveryRepository($pdo),
        );
        $programDeps = new ProgramDeps($config, $users, $flows, $programsRepo, $songs, $programMessages, $delivery, $texts, $interactive);

        return [
            'texts' => $texts,
            'pdo' => $pdo,
            'users' => $users,
            'materials' => $materialsDeps,
            'programs' => $programDeps,
            'programsRepo' => $programsRepo,
        ];
    }

    private function botWithCallback(int $telegramId, string $data): Nutgram
    {
        $bot = Nutgram::fake();
        $bot->setCommonUser($this->tgUser($telegramId));
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'id' => '1',
            'data' => $data,
            'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'T'],
            'message' => [
                'message_id' => 100,
                'date' => 1_703_892_479,
                'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ]);
        $mode = $bot->getContainer()->get(RunningMode::class);
        if ($mode instanceof Fake) {
            $updateProperty = new \ReflectionProperty(Fake::class, 'update');
            $updateProperty->setAccessible(true);
            $update = $updateProperty->getValue($mode);
            if ($update instanceof Update) {
                $botUpdate = new \ReflectionProperty(Nutgram::class, 'update');
                $botUpdate->setAccessible(true);
                $botUpdate->setValue($bot, $update);
            }
        }

        return $bot;
    }

    private function config(): Config
    {
        return new Config(
            botToken: 't',
            ownerTelegramId: '1',
            conductorTelegramId: (string) self::CONDUCTOR_TG,
            adminChatId: '',
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
            fakeNow: '2030-01-01T12:00:00+05:00',
        );
    }

    private function tgUser(int $id): User
    {
        $u = new User();
        $u->id = $id;
        $u->is_bot = false;
        $u->first_name = 'Тест';

        return $u;
    }

    /**
     * @return list<string>
     */
    private function callbacks(Nutgram $bot): array
    {
        $found = [];
        foreach ($bot->getRequestHistory() as $entry) {
            $body = (string) $entry['request']->getBody();
            if (preg_match_all('/"callback_data":"([^"]+)"/', $body, $m)) {
                foreach ($m[1] as $cb) {
                    $found[] = $cb;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function callbacksFromLastEdit(Nutgram $bot): array
    {
        foreach (array_reverse($bot->getRequestHistory()) as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'editMessageText')) {
                continue;
            }
            $body = json_decode((string) $entry['request']->getBody(), true) ?: [];
            $markup = $body['reply_markup'] ?? null;
            if (is_string($markup)) {
                $markup = json_decode($markup, true);
            }
            $keyboard = is_array($markup) ? ($markup['inline_keyboard'] ?? []) : [];
            $callbacks = [];
            foreach ($keyboard as $row) {
                foreach ($row as $button) {
                    if (isset($button['callback_data'])) {
                        $callbacks[] = (string) $button['callback_data'];
                    }
                }
            }

            return $callbacks;
        }

        return $this->callbacks($bot);
    }
}