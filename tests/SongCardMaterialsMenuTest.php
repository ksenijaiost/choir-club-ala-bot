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
use App\Services\MaterialsService;
use App\Services\SongDeliveryService;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;

final class SongCardMaterialsMenuTest extends TestCase
{
    private TextRepository $texts;
    private SongRepository $songs;
    private MaterialRepository $materials;
    private SongDeliveryService $delivery;
    private UserRepository $users;
    private int $uploaderId;

    protected function setUp(): void
    {
        $this->texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $this->users = new UserRepository($pdo);
        $this->uploaderId = $this->users->create(90001, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $this->songs = new SongRepository($pdo);
        $this->materials = new MaterialRepository($pdo);
        $this->delivery = new SongDeliveryService(
            $this->materials,
            $this->songs,
            $this->texts,
            new MaterialsService($this->materials, $this->songs, $this->texts),
        );
    }

    public function testSongWithNoMaterialsShowsOnlyClose(): void
    {
        $song = $this->song('Пустая');
        $member = $this->member(90002, 'soprano_1');

        $bot = Nutgram::fake();
        $this->delivery->sendSongCard($bot, $song, $member);

        $expected = $this->texts->get('songs.card_header', ['title' => 'Пустая'])
            . "\n\n"
            . $this->texts->get('songs.card_no_materials');
        $bot->assertReplyText($expected);

        $callbacks = $this->callbackDataFromLastMessage($bot);
        self::assertSame(['ui:x'], $callbacks);
    }

    public function testSongWithOnlyNotesShowsNotesButton(): void
    {
        $song = $this->song('Ноты');
        $this->materials->insert((int) $song['id'], MaterialType::NOTES, null, 'n1', 'nu1', $this->uploaderId, null, true);
        $member = $this->member(90003, 'tenor');

        $bot = Nutgram::fake();
        $this->delivery->sendSongCard($bot, $song, $member);

        $callbacks = $this->callbackDataFromLastMessage($bot);
        self::assertContains('snd:nt:' . $song['id'], $callbacks);
        self::assertNotContains('snd:tr:' . $song['id'], $callbacks);
        self::assertNotContains('snd:pr:' . $song['id'], $callbacks);
    }

    public function testSongWithOnlyTranslitShowsTranslitButton(): void
    {
        $song = $this->song('Заметки');
        $service = new MaterialsService($this->materials, $this->songs, $this->texts);
        $admin = $this->users->findById($this->uploaderId);
        self::assertNotNull($admin);
        $service->saveTextUpload($admin, (int) $song['id'], MaterialType::TRANSLIT, 'Текст', null);
        $member = $this->member(90004, 'bass');

        $bot = Nutgram::fake();
        $this->delivery->sendSongCard($bot, $song, $member);

        $callbacks = $this->callbackDataFromLastMessage($bot);
        self::assertContains('snd:tr:' . $song['id'], $callbacks);
        self::assertNotContains('snd:nt:' . $song['id'], $callbacks);
        self::assertNotContains('snd:pr:' . $song['id'], $callbacks);
    }

    public function testMemberDoesNotSeeRecordingsForOtherPartOnly(): void
    {
        $song = $this->song('Чужая партия');
        $this->materials->insertRecording((int) $song['id'], ['alto_1'], 'r1', 'ru1', $this->uploaderId, null);
        $member = $this->member(90005, 'soprano_1');

        $bot = Nutgram::fake();
        $this->delivery->sendSongCard($bot, $song, $member);

        $callbacks = $this->callbackDataFromLastMessage($bot);
        self::assertNotContains('snd:pr:' . $song['id'], $callbacks);
    }

    public function testConductorSeesRecordingsForAnyPart(): void
    {
        $song = $this->song('Дирижёр');
        $this->materials->insertRecording((int) $song['id'], ['baritone'], 'r2', 'ru2', $this->uploaderId, null);
        $this->users->create(90006, 'Дирижёр', null, UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $conductor = $this->users->findByTelegramId(90006);
        self::assertNotNull($conductor);

        $bot = Nutgram::fake();
        $this->delivery->sendSongCard($bot, $song, $conductor);

        $callbacks = $this->callbackDataFromLastMessage($bot);
        self::assertContains('snd:pr:' . $song['id'], $callbacks);
    }

    public function testMemberSeesTuttiRecording(): void
    {
        $song = $this->song('Тутти');
        $this->materials->insertRecording((int) $song['id'], VoicePart::CODES, 'r3', 'ru3', $this->uploaderId, null);
        $member = $this->member(90007, 'tenor');

        $bot = Nutgram::fake();
        $this->delivery->sendSongCard($bot, $song, $member);

        $callbacks = $this->callbackDataFromLastMessage($bot);
        self::assertContains('snd:pr:' . $song['id'], $callbacks);
    }

    /**
     * @return list<string>
     */
    private function callbackDataFromLastMessage(Nutgram $bot): array
    {
        $history = $bot->getRequestHistory();
        self::assertNotEmpty($history);
        $last = $history[array_key_last($history)];
        $body = (string) $last['request']->getBody();
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $keyboard = $payload['reply_markup']['inline_keyboard'] ?? [];
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
     * @return array<string, mixed>
     */
    private function song(string $title): array
    {
        $song = $this->songs->findById($this->songs->create($title));
        self::assertNotNull($song);

        return $song;
    }

    /**
     * @return array<string, mixed>
     */
    private function member(int $telegramId, string $part): array
    {
        $this->users->create($telegramId, 'Участник', $part, UserRole::MEMBER, UserStatus::ACTIVE);
        $user = $this->users->findByTelegramId($telegramId);
        self::assertNotNull($user);

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