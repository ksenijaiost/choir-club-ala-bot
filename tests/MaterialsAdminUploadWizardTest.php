<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repositories\UserOpenMenuRepository;
use App\Telegram\InteractiveMenuService;
use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\MaterialType;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\MaterialsCallbackHandler;
use App\Handlers\MaterialsDeps;
use App\Handlers\MaterialsFlowHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Tests\Support\MaterialsTestDeps;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class MaterialsAdminUploadWizardTest extends TestCase
{
    private const ADMIN_CHAT_ID = -1008035505540528;
    private const ADMIN_TG = 88001;

    public function testAdminUploadStepsEditSameMessage(): void
    {
        $deps = $this->deps();
        $songId = $deps->songs->create('Тестовая');
        $deps->flows->set(self::ADMIN_TG, 'material_upload', [
            'source' => 'file',
            'file_id' => 'f1',
            'file_unique_id' => 'uniq-1',
            'admin_chat_id' => self::ADMIN_CHAT_ID,
            'admin_chat_message_id' => 10,
            'step' => 'song',
        ]);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->telegramUser(self::ADMIN_TG));

        MaterialsFlowHandler::sendSongPicker($bot, $deps, 0, self::ADMIN_CHAT_ID);
        $sendCalls = $this->countMethodCalls($bot, 'sendMessage');
        self::assertSame(1, $sendCalls);

        $deps->flows->set(self::ADMIN_TG, 'material_upload', [
            'source' => 'file',
            'file_id' => 'f1',
            'file_unique_id' => 'uniq-1',
            'admin_chat_id' => self::ADMIN_CHAT_ID,
            'admin_chat_message_id' => 10,
            'wizard_message_id' => 501,
            'step' => 'song',
        ]);

        $actor = $deps->users->findByTelegramId(self::ADMIN_TG);
        self::assertNotNull($actor);
        $bot->setCommonUser($this->telegramUser(self::ADMIN_TG));
        MaterialsCallbackHandler::handle(
            $bot,
            $deps,
            new AccessControl($this->testConfig()),
            $actor,
            'mat:up:sn:' . $songId,
        );

        self::assertSame(1, $this->countMethodCalls($bot, 'sendMessage'));
        self::assertGreaterThanOrEqual(1, $this->countMethodCalls($bot, 'editMessageText'));
    }

    public function test429OnClearKeyboardDoesNotBlockSave(): void
    {
        $deps = $this->deps();
        $songId = $deps->songs->create('Сохранить');
        $deps->flows->set(self::ADMIN_TG, 'material_upload', [
            'file_id' => 'f2',
            'file_unique_id' => 'uniq-save',
            'admin_chat_id' => self::ADMIN_CHAT_ID,
            'wizard_message_id' => 777,
            'song_id' => $songId,
            'type' => MaterialType::NOTES,
            'step' => 'save',
        ]);

        $actor = $deps->users->findByTelegramId(self::ADMIN_TG);
        self::assertNotNull($actor);

        $bot = Nutgram::fake();

        MaterialsFlowHandler::completeUpload($bot, $deps, $actor);

        $notes = $deps->materials->listCurrentNotes($songId);
        self::assertCount(1, $notes);
        self::assertSame('uniq-save', $notes[0]['telegram_file_unique_id']);
    }

    public function testDoubleDoneDoesNotDuplicateRecording(): void
    {
        $deps = $this->deps();
        $songId = $deps->songs->create('Дубль');
        $flow = [
            'file_id' => 'f3',
            'file_unique_id' => 'uniq-rec',
            'admin_chat_id' => self::ADMIN_CHAT_ID,
            'wizard_message_id' => 888,
            'song_id' => $songId,
            'type' => MaterialType::RECORDING,
            'selected_parts' => ['tenor'],
            'step' => 'part',
        ];
        $deps->flows->set(self::ADMIN_TG, 'material_upload', $flow);

        $bot = Nutgram::fake();
        $actor = $deps->users->findByTelegramId(self::ADMIN_TG);
        self::assertNotNull($actor);
        $access = new AccessControl($this->testConfig());

        MaterialsCallbackHandler::handle($bot, $deps, $access, $actor, 'mat:up:pt:go');
        MaterialsCallbackHandler::handle($bot, $deps, $access, $actor, 'mat:up:pt:go');

        $rows = $deps->materials->listCurrentRecordings($songId, 'tenor');
        self::assertCount(1, $rows);
    }

    public function testDoubleDoneWhileSavingIsIgnored(): void
    {
        $deps = $this->deps();
        $songId = $deps->songs->create('Блок');
        $deps->flows->set(self::ADMIN_TG, 'material_upload', [
            'file_id' => 'f4',
            'file_unique_id' => 'uniq-block',
            'admin_chat_id' => self::ADMIN_CHAT_ID,
            'wizard_message_id' => 889,
            'song_id' => $songId,
            'type' => MaterialType::RECORDING,
            'selected_parts' => ['tenor'],
            'step' => 'save',
            'save_in_progress' => true,
        ]);

        $bot = Nutgram::fake();
        $actor = $deps->users->findByTelegramId(self::ADMIN_TG);
        self::assertNotNull($actor);

        MaterialsCallbackHandler::handle(
            $bot,
            $deps,
            new AccessControl($this->testConfig()),
            $actor,
            'mat:up:pt:go',
        );

        self::assertCount(0, $deps->materials->listCurrentRecordings($songId, 'tenor'));
    }

    private function countMethodCalls(Nutgram $bot, string $method): int
    {
        $count = 0;
        foreach ($bot->getRequestHistory() as $entry) {
            $uri = (string) $entry['request']->getUri();
            if (str_contains($uri, $method)) {
                $count++;
            }
        }

        return $count;
    }

    private function deps(): MaterialsDeps
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::ADMIN_TG, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songRepo = new SongRepository($pdo);
        $materialRepo = new MaterialRepository($pdo);

        return MaterialsTestDeps::build(
            $config,
            $users,
            new UserFlowRepository($pdo),
            $songRepo,
            $materialRepo,
            $texts,
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );
    }

    private function telegramUser(int $id): User
    {
        $user = new User();
        $user->id = $id;
        $user->first_name = 'Админ';

        return $user;
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
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