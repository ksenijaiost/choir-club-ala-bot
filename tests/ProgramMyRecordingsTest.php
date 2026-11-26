<?php

declare(strict_types=1);

namespace App\Tests;

use App\Tests\Support\AchievementServiceFactory;
use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\MaterialType;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\ProgramMemberHandler;
use App\Handlers\SongMemberHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\ProgramRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\ProgramDeliveryService;
use App\Services\ProgramMessageBuilder;
use App\Telegram\InlineMenuHelper;
use App\Tests\Support\MaterialsTestDeps;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Handlers\ProgramDeps;
use App\Telegram\InteractiveMenuService;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Fake;
use SergiX44\Nutgram\RunningMode\RunningMode;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Testing\FakeNutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class ProgramMyRecordingsTest extends TestCase
{
    private const MEMBER_TG = 96201;

    private const CONDUCTOR_TG = 96202;

    public function testHubTwoButtonsWithoutPrograms(): void
    {
        $bundle = $this->bundle();
        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));
        SongMemberHandler::openMyRecordings($bot, $bundle['materials'], $bundle['materials']->delivery, $member, $bundle['programs']);

        $bot->assertReplyText($bundle['texts']->get('programs.hub_header'));
        $callbacks = $this->callbacks($bot);
        self::assertContains('prg:mr:pk', $callbacks);
        self::assertNotContains('prg:mr:ls:0', $callbacks);
    }

    public function testHubTwoButtonsWithActiveProgram(): void
    {
        $bundle = $this->bundle();
        $admin = $bundle['users']->findByTelegramId(96200);
        self::assertNotNull($admin);
        $bundle['programsRepo']->create('Весна', (int) $admin['id'], '2030-01-01');

        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));
        ProgramMemberHandler::openHub($bot, $bundle['programs'], $bundle['materials'], $member);

        $callbacks = $this->callbacks($bot);
        self::assertNotContains('prg:mr:ls:0', $callbacks);
        self::assertContains('prg:mr:pk', $callbacks);
    }

    public function testProgramSendAllButtonWhenMoreThanOneRecording(): void
    {
        $bundle = $this->bundle();
        $admin = $bundle['users']->findByTelegramId(96200);
        self::assertNotNull($admin);
        $programId = $bundle['programsRepo']->create('Концерт', (int) $admin['id'], '2030-06-01');
        $songA = $bundle['songs']->create('Песня A');
        $songB = $bundle['songs']->create('Песня B');
        $bundle['programsRepo']->toggleSong($programId, $songA);
        $bundle['programsRepo']->toggleSong($programId, $songB);
        $bundle['materialsRepo']->insertRecording($songA, ['soprano_1'], 'fa', 'ua', (int) $admin['id'], null);
        $bundle['materialsRepo']->insertRecording($songB, ['soprano_1'], 'fb', 'ub', (int) $admin['id'], null);

        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = $this->botWithCallback(self::MEMBER_TG, 'prg:mo:' . $programId);
        ProgramMemberHandler::handleCallback(
            $bot,
            $bundle['programs'],
            $bundle['materials'],
            $member,
            'prg:mo:' . $programId,
        );

        $callbacks = $this->callbacksFromLastEdit($bot);
        self::assertContains('prg:mo:' . $programId . ':al', $callbacks);
    }

    public function testProgramSendAllButtonHiddenWhenSingleRecording(): void
    {
        $bundle = $this->bundle();
        $admin = $bundle['users']->findByTelegramId(96200);
        self::assertNotNull($admin);
        $programId = $bundle['programsRepo']->create('Соло', (int) $admin['id'], '2030-06-01');
        $songA = $bundle['songs']->create('Одна');
        $bundle['programsRepo']->toggleSong($programId, $songA);
        $bundle['materialsRepo']->insertRecording($songA, ['soprano_1'], 'fa', 'ua', (int) $admin['id'], null);

        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = $this->botWithCallback(self::MEMBER_TG, 'prg:mo:' . $programId);
        ProgramMemberHandler::handleCallback(
            $bot,
            $bundle['programs'],
            $bundle['materials'],
            $member,
            'prg:mo:' . $programId,
        );

        $callbacks = $this->callbacksFromLastEdit($bot);
        self::assertNotContains('prg:mo:' . $programId . ':al', $callbacks);
    }

    public function testProgramSendAllButtonOnSecondPageSendsWholeProgram(): void
    {
        $bundle = $this->bundle();
        $admin = $bundle['users']->findByTelegramId(96200);
        self::assertNotNull($admin);
        $programId = $bundle['programsRepo']->create('Длинная', (int) $admin['id'], '2030-06-01');

        for ($i = 0; $i < InlineMenuHelper::PAGE_SIZE + 1; ++$i) {
            $songId = $bundle['songs']->create('Номер ' . $i);
            $bundle['programsRepo']->toggleSong($programId, $songId);
            $bundle['materialsRepo']->insertRecording($songId, ['soprano_1'], 'f' . $i, 'u' . $i, (int) $admin['id'], null);
        }

        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = $this->botWithCallback(self::MEMBER_TG, 'prg:mo:' . $programId . ':pg:1');
        ProgramMemberHandler::handleCallback(
            $bot,
            $bundle['programs'],
            $bundle['materials'],
            $member,
            'prg:mo:' . $programId . ':pg:1',
        );

        $callbacks = $this->callbacksFromLastEdit($bot);
        self::assertContains('prg:mo:' . $programId . ':al', $callbacks);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));
        ProgramMemberHandler::handleCallback(
            $bot,
            $bundle['programs'],
            $bundle['materials'],
            $member,
            'prg:mo:' . $programId . ':al',
        );

        $bot->assertCalled('sendAudio', InlineMenuHelper::PAGE_SIZE + 1);
    }

    public function testProgramSendAllPreservesProgramOrderNotAlphabetical(): void
    {
        $bundle = $this->bundle();
        $admin = $bundle['users']->findByTelegramId(96200);
        self::assertNotNull($admin);
        $programId = $bundle['programsRepo']->create('Порядок', (int) $admin['id'], '2030-06-01');
        $titles = ['Яблоко', 'Банан', 'Вишня'];
        foreach ($titles as $title) {
            $songId = $bundle['songs']->create($title);
            $bundle['programsRepo']->toggleSong($programId, $songId);
            $bundle['materialsRepo']->insertRecording($songId, ['soprano_1'], 'f-' . $title, 'u-' . $title, (int) $admin['id'], null);
        }

        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));
        ProgramMemberHandler::handleCallback(
            $bot,
            $bundle['programs'],
            $bundle['materials'],
            $member,
            'prg:mo:' . $programId . ':al',
        );

        $captions = $this->audioCaptions($bot);
        self::assertCount(3, $captions);
        self::assertStringContainsString('Яблоко', $captions[0]);
        self::assertStringContainsString('Банан', $captions[1]);
        self::assertStringContainsString('Вишня', $captions[2]);
    }

    public function testProgramSkipsSongsWithoutRecordingsForPart(): void
    {
        $bundle = $this->bundle();
        $admin = $bundle['users']->findByTelegramId(96200);
        self::assertNotNull($admin);
        $programId = $bundle['programsRepo']->create('Фильтр', (int) $admin['id'], '2030-06-01');
        $visible = $bundle['songs']->create('Видимая');
        $hidden = $bundle['songs']->create('Только бас');
        $bundle['programsRepo']->toggleSong($programId, $visible);
        $bundle['programsRepo']->toggleSong($programId, $hidden);
        $bundle['materialsRepo']->insertRecording($visible, ['soprano_1'], 'fv', 'uv', (int) $admin['id'], null);
        $bundle['materialsRepo']->insertRecording($hidden, ['bass'], 'fb', 'ub', (int) $admin['id'], null);
        $extra = $bundle['songs']->create('Вторая сопрано');
        $bundle['programsRepo']->toggleSong($programId, $extra);
        $bundle['materialsRepo']->insertRecording($extra, ['soprano_1'], 'fe', 'ue', (int) $admin['id'], null);

        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = $this->botWithCallback(self::MEMBER_TG, 'prg:mo:' . $programId);
        ProgramMemberHandler::handleCallback(
            $bot,
            $bundle['programs'],
            $bundle['materials'],
            $member,
            'prg:mo:' . $programId,
        );

        $callbacks = $this->callbacksFromLastEdit($bot);
        self::assertContains('snd:my:' . $visible . ':soprano_1', $callbacks);
        self::assertContains('snd:my:' . $extra . ':soprano_1', $callbacks);
        self::assertNotContains('snd:my:' . $hidden . ':soprano_1', $callbacks);
    }

    public function testConductorGetsAllPartsMemberOnlyOwn(): void
    {
        $bundle = $this->bundle(withConductor: true);
        $admin = $bundle['users']->findByTelegramId(96200);
        self::assertNotNull($admin);
        $programId = $bundle['programsRepo']->create('Партии', (int) $admin['id'], '2030-06-01');
        $song = $bundle['songs']->create('Микс');
        $bundle['programsRepo']->toggleSong($programId, $song);
        $bundle['materialsRepo']->insertRecording($song, ['soprano_1'], 'fs', 'us', (int) $admin['id'], null);
        $bundle['materialsRepo']->insertRecording($song, ['bass'], 'fb', 'ub', (int) $admin['id'], null);

        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);
        $conductor = $bundle['users']->findByTelegramId(self::CONDUCTOR_TG);
        self::assertNotNull($conductor);

        $memberBot = Nutgram::fake();
        $memberBot->setCommonUser($this->tgUser(self::MEMBER_TG));
        ProgramMemberHandler::handleCallback(
            $memberBot,
            $bundle['programs'],
            $bundle['materials'],
            $member,
            'prg:mo:' . $programId . ':al',
        );
        $memberBot->assertCalled('sendAudio', 1);

        $conductorBot = Nutgram::fake();
        $conductorBot->setCommonUser($this->tgUser(self::CONDUCTOR_TG));
        ProgramMemberHandler::handleCallback(
            $conductorBot,
            $bundle['programs'],
            $bundle['materials'],
            $conductor,
            'prg:mo:' . $programId . ':al',
        );
        $conductorBot->assertCalled('sendAudio', 2);
    }

    public function testProgramBulkSendNoTranslitBetweenSongs(): void
    {
        $bundle = $this->bundle();
        $admin = $bundle['users']->findByTelegramId(96200);
        self::assertNotNull($admin);
        $programId = $bundle['programsRepo']->create('Без транслита', (int) $admin['id'], '2030-06-01');
        $songA = $bundle['songs']->create('А');
        $songB = $bundle['songs']->create('Б');
        $bundle['programsRepo']->toggleSong($programId, $songA);
        $bundle['programsRepo']->toggleSong($programId, $songB);
        $bundle['materialsRepo']->insertRecording($songA, ['soprano_1'], 'fa', 'ua', (int) $admin['id'], null);
        $bundle['materialsRepo']->insertRecording($songB, ['soprano_1'], 'fb', 'ub', (int) $admin['id'], null);
        $bundle['materials']->service->saveTextUpload($admin, $songA, MaterialType::TRANSLIT, 'Заметка только A', null);

        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));
        ProgramMemberHandler::handleCallback(
            $bot,
            $bundle['programs'],
            $bundle['materials'],
            $member,
            'prg:mo:' . $programId . ':al',
        );

        foreach ($this->messageBodies($bot) as $body) {
            self::assertStringNotContainsString('Заметка только A', (string) ($body['text'] ?? ''));
        }
    }

    public function testProgramBulkSendProgressMessageWhenFourOrMore(): void
    {
        $bundle = $this->bundle();
        $admin = $bundle['users']->findByTelegramId(96200);
        self::assertNotNull($admin);
        $programId = $bundle['programsRepo']->create('Четверо', (int) $admin['id'], '2030-06-01');
        for ($i = 0; $i < 4; ++$i) {
            $songId = $bundle['songs']->create('S' . $i);
            $bundle['programsRepo']->toggleSong($programId, $songId);
            $bundle['materialsRepo']->insertRecording($songId, ['soprano_1'], 'f' . $i, 'u' . $i, (int) $admin['id'], null);
        }

        $member = $bundle['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));
        ProgramMemberHandler::handleCallback(
            $bot,
            $bundle['programs'],
            $bundle['materials'],
            $member,
            'prg:mo:' . $programId . ':al',
        );

        $progress = $bundle['texts']->get('songs.my_recordings_sending_all', ['count' => '4']);
        $found = false;
        foreach ($this->messageBodies($bot) as $body) {
            if (($body['text'] ?? '') === $progress) {
                $found = true;
            }
        }
        self::assertTrue($found);
        $bot->assertCalled('sendAudio', 4);
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
        $users->create(96200, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $users->create(self::MEMBER_TG, 'Участник', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        if ($withConductor) {
            $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        }
        $flows = new UserFlowRepository($pdo);
        $interactive = new InteractiveMenuService(new UserOpenMenuRepository($pdo));
        $songs = new SongRepository($pdo);
        $materialsRepo = new MaterialRepository($pdo);
        $materialsDeps = MaterialsTestDeps::build($config, $pdo, $users, $flows, $songs, $materialsRepo, $texts, $interactive);
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
        $programDeps = new ProgramDeps($config, $users, $flows, $programsRepo, $songs, $programMessages, $delivery, $texts, $interactive, AchievementServiceFactory::make($config, $pdo, $texts));

        return [
            'texts' => $texts,
            'users' => $users,
            'materials' => $materialsDeps,
            'materialsRepo' => $materialsRepo,
            'programs' => $programDeps,
            'programsRepo' => $programsRepo,
            'songs' => $songs,
        ];
    }

    private function tgUser(int $id): User
    {
        $u = new User();
        $u->id = $id;
        $u->is_bot = false;
        $u->first_name = 'Тест';

        return $u;
    }

    private function botWithCallback(int $telegramId, string $data = 'prg:mr:hub'): Nutgram
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
        $this->bindHeardUpdate($bot);

        return $bot;
    }

    private function bindHeardUpdate(Nutgram $bot): void
    {
        $mode = $bot->getContainer()->get(RunningMode::class);
        if (!$mode instanceof Fake) {
            return;
        }
        $updateProperty = new \ReflectionProperty(Fake::class, 'update');
        $updateProperty->setAccessible(true);
        $update = $updateProperty->getValue($mode);
        if (!$update instanceof Update) {
            return;
        }

        $botUpdate = new \ReflectionProperty(Nutgram::class, 'update');
        $botUpdate->setAccessible(true);
        $botUpdate->setValue($bot, $update);
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
            $body = FakeNutgram::getActualData($entry['request']);
            if (!is_array($body)) {
                continue;
            }

            return $this->callbacksFromKeyboard($body);
        }

        return $this->callbacks($bot);
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private function callbacksFromKeyboard(array $body): array
    {
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

    /**
     * @return list<string>
     */
    private function audioCaptions(Nutgram $bot): array
    {
        $captions = [];
        foreach ($bot->getRequestHistory() as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'sendAudio')) {
                continue;
            }
            $data = FakeNutgram::getActualData($entry['request']);
            $captions[] = (string) ($data['caption'] ?? '');
        }

        return $captions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messageBodies(Nutgram $bot): array
    {
        $bodies = [];
        foreach ($bot->getRequestHistory() as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'sendMessage')) {
                continue;
            }
            $bodies[] = json_decode((string) $entry['request']->getBody(), true) ?: [];
        }

        return $bodies;
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
}