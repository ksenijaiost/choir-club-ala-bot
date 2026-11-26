<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\MaterialsCallbackHandler;
use App\Handlers\MaterialsDeps;
use App\Handlers\MaterialsRecordingEditHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AdminChatSongListPresenter;
use App\Tests\Support\MaterialsTestDeps;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Telegram\InteractiveMenuService;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class RecordingMultiPartEditTest extends TestCase
{
    private const UPLOADER_TG = 90301;
    private const SOPRANO_TG = 90302;
    private const BASS_TG = 90303;

    public function testToggleAndSaveMultipleParts(): void
    {
        $deps = $this->deps();
        $songId = $deps['songs']->create('Мульти');
        $materialId = $deps['materials']->insertRecording($songId, ['bass'], 'r1', 'u1', $deps['uploaderId'], null);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::UPLOADER_TG));
        MaterialsRecordingEditHandler::openEditor($bot, $deps['handlerDeps'], self::UPLOADER_TG, $materialId);

        MaterialsRecordingEditHandler::toggleDraftPart($bot, $deps['handlerDeps'], self::UPLOADER_TG, $materialId, 'baritone');
        MaterialsRecordingEditHandler::saveDraftParts($bot, $deps['handlerDeps'], self::UPLOADER_TG, $materialId);

        self::assertSame(['baritone', 'bass'], $deps['materials']->listVoiceParts($materialId));
    }

    public function testSaveEmptySelectionShowsAlert(): void
    {
        $deps = $this->deps();
        $songId = $deps['songs']->create('Пусто');
        $materialId = $deps['materials']->insertRecording($songId, ['tenor'], 'r1', 'u1', $deps['uploaderId'], null);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::UPLOADER_TG));
        $deps['flows']->set(self::UPLOADER_TG, MaterialsRecordingEditHandler::FLOW, [
            'song_id' => $songId,
            'material_id' => $materialId,
            'draft_parts' => [],
            'wizard_chat_id' => self::UPLOADER_TG,
            'wizard_message_id' => 1,
        ]);

        MaterialsRecordingEditHandler::saveDraftParts($bot, $deps['handlerDeps'], self::UPLOADER_TG, $materialId);

        self::assertSame(['tenor'], $deps['materials']->listVoiceParts($materialId));
        self::assertSame(
            $deps['texts']->get('materials.recording_edit_pick_one'),
            $this->lastAnswerCallbackText($bot),
        );
    }

    public function testNotificationsOnlyForNewlyAddedParts(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $users = new UserRepository($pdo);
        $uploaderId = $users->create(self::UPLOADER_TG, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $users->create(self::SOPRANO_TG, 'Соп', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(self::BASS_TG, 'Бас', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $materials = new MaterialRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $songId = $songs->create('Уведомление');
        $materialId = $materials->insertRecording($songId, ['bass'], 'r1', 'u1', $uploaderId, null);

        $outbound = new RecordingTelegramOutbound();
        $handlerDeps = MaterialsTestDeps::build(
            $this->testConfig(),
                        $pdo,
            $users,
            $flows,
            $songs,
            $materials,
            $texts,
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
            $outbound,
        );

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::UPLOADER_TG));
        $flows->set(self::UPLOADER_TG, MaterialsRecordingEditHandler::FLOW, [
            'song_id' => $songId,
            'material_id' => $materialId,
            'draft_parts' => ['bass', 'soprano_1'],
            'wizard_chat_id' => self::UPLOADER_TG,
            'wizard_message_id' => 5,
        ]);

        MaterialsRecordingEditHandler::saveDraftParts($bot, $handlerDeps, self::UPLOADER_TG, $materialId);

        $notified = array_column($outbound->messages, 'chat_id');
        self::assertContains(self::SOPRANO_TG, $notified);
        self::assertNotContains(self::BASS_TG, $notified);
    }

    public function testSongsListWarnsPartialCoverage(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $uploaderId = $users->create(self::UPLOADER_TG, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $songs = new SongRepository($pdo);
        $materials = new MaterialRepository($pdo);
        $songId = $songs->create('Частично');
        $materials->insertRecording($songId, ['bass'], 'r1', 'u1', $uploaderId, null);

        $body = implode("\n", (new AdminChatSongListPresenter($songs, $materials, $texts))->buildMessageChunks());
        self::assertStringContainsString('<b>⚠️ Частично</b>', $body);
        self::assertStringContainsString('Нет записей', $body);
    }

    public function testCallbackMultiSelectFlow(): void
    {
        $deps = $this->deps();
        $uploader = $deps['users']->findByTelegramId(self::UPLOADER_TG);
        self::assertNotNull($uploader);
        $songId = $deps['songs']->create('Колбэк');
        $materialId = $deps['materials']->insertRecording($songId, ['bass'], 'r1', 'u1', $deps['uploaderId'], null);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::UPLOADER_TG));

        MaterialsCallbackHandler::handle($bot, $deps['handlerDeps'], $deps['access'], $uploader, 'mat:mg:erec:tk:' . $materialId . ':baritone');
        MaterialsCallbackHandler::handle($bot, $deps['handlerDeps'], $deps['access'], $uploader, 'mat:mg:erec:sv:' . $materialId);

        self::assertSame(['baritone', 'bass'], $deps['materials']->listVoiceParts($materialId));
    }

    private function lastAnswerCallbackText(Nutgram $bot): string
    {
        foreach (array_reverse($bot->getRequestHistory()) as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'answerCallbackQuery')) {
                continue;
            }
            $body = json_decode((string) $entry['request']->getBody(), true);

            return (string) ($body['text'] ?? '');
        }

        return '';
    }

    /**
     * @return array{
     *     users: UserRepository,
     *     uploaderId: int,
     *     materials: MaterialRepository,
     *     songs: SongRepository,
     *     flows: UserFlowRepository,
     *     texts: TextRepository,
     *     handlerDeps: MaterialsDeps,
     *     access: AccessControl
     * }
     */
    private function deps(): array
    {
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $users = new UserRepository($pdo);
        $uploaderId = $users->create(self::UPLOADER_TG, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $materials = new MaterialRepository($pdo);
        $songs = new SongRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $handlerDeps = MaterialsTestDeps::build(
            $config,
                        $pdo,
            $users,
            $flows,
            $songs,
            $materials,
            $texts,
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );

        return [
            'users' => $users,
            'uploaderId' => $uploaderId,
            'materials' => $materials,
            'songs' => $songs,
            'flows' => $flows,
            'texts' => $texts,
            'handlerDeps' => $handlerDeps,
            'access' => new AccessControl($config),
        ];
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->id = $id;
        $user->first_name = 'T';

        return $user;
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
}