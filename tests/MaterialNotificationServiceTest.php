<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\MaterialType;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Domain\VoicePart;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserRepository;
use App\Services\MaterialNotificationService;
use App\Services\MaterialsService;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Tests\Support\ThrowingRecordingTelegramOutbound;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class MaterialNotificationServiceTest extends TestCase
{
    public function testRecordingNotifiesOwnPartOnly(): void
    {
        $deps = $this->deps();
        $texts = $deps['texts'];
        $song = ['id' => $deps['songId'], 'title' => 'Les sans papier'];

        $deps['notifier']->afterMaterialSaved(
            $deps['uploader'],
            [
                'type' => MaterialType::RECORDING,
                'song' => $song,
                'voice_parts' => ['soprano_1'],
            ],
        );

        $chatIds = array_column($deps['outbound']->messages, 'chat_id');
        self::assertContains($deps['soprano1Tg'], $chatIds);
        self::assertNotContains($deps['uploaderTg'], $chatIds);
        self::assertNotContains($deps['tenorTg'], $chatIds);
        self::assertNotContains($deps['conductorTg'], $chatIds);

        $text = $deps['outbound']->messages[0]['text'];
        self::assertSame(
            $texts->get('notifications.material_recording', [
                'title' => 'Les sans papier',
                'part' => $texts->get('parts.soprano_1'),
            ]),
            $text,
        );
    }

    public function testTuttiNotifiesAllExceptAuthor(): void
    {
        $deps = $this->deps();
        $song = ['id' => $deps['songId'], 'title' => 'Общая'];

        $deps['notifier']->afterMaterialSaved(
            $deps['uploader'],
            [
                'type' => MaterialType::RECORDING,
                'song' => $song,
                'voice_parts' => VoicePart::CODES,
            ],
        );

        $chatIds = array_column($deps['outbound']->messages, 'chat_id');
        self::assertContains($deps['soprano1Tg'], $chatIds);
        self::assertContains($deps['tenorTg'], $chatIds);
        self::assertNotContains($deps['uploaderTg'], $chatIds);
        self::assertNotContains($deps['conductorTg'], $chatIds);
    }

    public function testConductorReceivesAllRecordingPartsWhenEnabled(): void
    {
        $deps = $this->deps();
        $deps['users']->setMaterialNotificationsEnabled((int) $deps['conductor']['id'], true);
        $conductor = $deps['users']->findById((int) $deps['conductor']['id']);
        self::assertNotNull($conductor);

        $song = ['id' => $deps['songId'], 'title' => 'Для дирижёра'];

        $deps['notifier']->afterMaterialSaved(
            $deps['uploader'],
            [
                'type' => MaterialType::RECORDING,
                'song' => $song,
                'voice_parts' => ['bass'],
            ],
        );

        $chatIds = array_column($deps['outbound']->messages, 'chat_id');
        self::assertContains($deps['conductorTg'], $chatIds);
        self::assertNotContains($deps['uploaderTg'], $chatIds);
    }

    public function testDisabledProfileSkipsUser(): void
    {
        $deps = $this->deps();
        $deps['users']->setMaterialNotificationsEnabled((int) $deps['soprano1']['id'], false);

        $song = ['id' => $deps['songId'], 'title' => 'Выкл'];

        $deps['notifier']->afterMaterialSaved(
            $deps['uploader'],
            [
                'type' => MaterialType::RECORDING,
                'song' => $song,
                'voice_parts' => ['soprano_1'],
            ],
        );

        $chatIds = array_column($deps['outbound']->messages, 'chat_id');
        self::assertNotContains($deps['soprano1Tg'], $chatIds);
    }

    public function testNotesNotifyEveryoneExceptAuthor(): void
    {
        $deps = $this->deps();
        $song = ['id' => $deps['songId'], 'title' => 'Ноты'];

        $deps['notifier']->afterMaterialSaved(
            $deps['uploader'],
            [
                'type' => MaterialType::NOTES,
                'song' => $song,
                'voice_parts' => [],
            ],
        );

        $chatIds = array_column($deps['outbound']->messages, 'chat_id');
        self::assertContains($deps['soprano1Tg'], $chatIds);
        self::assertContains($deps['tenorTg'], $chatIds);
        self::assertNotContains($deps['uploaderTg'], $chatIds);
    }

    public function testReassignmentNotifiesNewPart(): void
    {
        $deps = $this->deps();
        $song = ['id' => $deps['songId'], 'title' => 'Перенос'];

        $deps['notifier']->afterRecordingReassigned($song, ['alto_1'], (int) $deps['uploader']['id']);

        $chatIds = array_column($deps['outbound']->messages, 'chat_id');
        self::assertContains($deps['alto1Tg'], $chatIds);
        self::assertNotContains($deps['uploaderTg'], $chatIds);
        self::assertNotContains($deps['soprano1Tg'], $chatIds);
    }

    public function testDefaultConductorOffMemberOn(): void
    {
        $deps = $this->deps();
        self::assertFalse($deps['users']->materialNotificationsEnabled($deps['conductor']));
        self::assertTrue($deps['users']->materialNotificationsEnabled($deps['soprano1']));
    }

    public function testBlockedRecipientDoesNotStopBroadcast(): void
    {
        $blockedTg = 1008205296;
        $outbound = new ThrowingRecordingTelegramOutbound($blockedTg);
        $deps = $this->deps($outbound);
        $deps['users']->create($blockedTg, 'Blocked', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $song = ['id' => $deps['songId'], 'title' => 'С блоком'];

        $deps['notifier']->afterMaterialSaved(
            $deps['uploader'],
            [
                'type' => MaterialType::NOTES,
                'song' => $song,
                'voice_parts' => [],
            ],
        );

        $chatIds = array_column($outbound->recordedMessages(), 'chat_id');
        self::assertContains($deps['soprano1Tg'], $chatIds);
        self::assertContains($deps['tenorTg'], $chatIds);
        self::assertNotContains($blockedTg, $chatIds);
    }

    public function testOpenSongButtonInKeyboard(): void
    {
        $deps = $this->deps();
        $song = ['id' => $deps['songId'], 'title' => 'Кнопка'];

        $deps['notifier']->afterMaterialSaved(
            $deps['uploader'],
            [
                'type' => MaterialType::NOTES,
                'song' => $song,
                'voice_parts' => [],
            ],
        );

        self::assertNotEmpty($deps['outbound']->messages);
        self::assertTrue($deps['outbound']->messages[0]['has_keyboard']);
    }

    public function testProfileToggleFlipsState(): void
    {
        $deps = $this->deps();
        $id = (int) $deps['soprano1']['id'];
        self::assertFalse($deps['users']->toggleMaterialNotifications($id));
        self::assertFalse($deps['users']->materialNotificationsEnabled($deps['users']->findById($id) ?? []));
        self::assertTrue($deps['users']->toggleMaterialNotifications($id));
        self::assertTrue($deps['users']->materialNotificationsEnabled($deps['users']->findById($id) ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    private function deps(?TelegramOutbound $outbound = null): array
    {
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $users = new UserRepository($pdo);
        $uploaderId = $users->create(70101, 'Uploader', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $sopranoId = $users->create(70102, 'Soprano', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $tenorId = $users->create(70103, 'Tenor', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $altoId = $users->create(70104, 'Alto', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $conductorId = $users->create(70105, 'Conductor', null, UserRole::CONDUCTOR, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Test song');
        $materials = new MaterialRepository($pdo);
        $service = new MaterialsService($materials, $songs, $texts);
        $outbound ??= new RecordingTelegramOutbound();
        $notifier = new MaterialNotificationService($users, $texts, $service, $outbound);

        return [
            'users' => $users,
            'texts' => $texts,
            'notifier' => $notifier,
            'outbound' => $outbound,
            'songId' => $songId,
            'uploaderTg' => 70101,
            'soprano1Tg' => 70102,
            'tenorTg' => 70103,
            'alto1Tg' => 70104,
            'conductorTg' => 70105,
            'uploader' => $users->findById($uploaderId) ?? [],
            'soprano1' => $users->findById($sopranoId) ?? [],
            'conductor' => $users->findById($conductorId) ?? [],
        ];
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
            adminChatId: '-1001',
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