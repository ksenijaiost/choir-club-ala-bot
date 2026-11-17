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
use App\Handlers\MaterialsDeps;
use App\Handlers\SongsCallbackHandler;
use App\Handlers\SongMemberHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\MaterialsService;
use App\Services\SongDeliveryService;
use App\Telegram\InteractiveMenuService;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class MyRecordingsDeliveryTest extends TestCase
{
    private const MEMBER_TG = 94101;

    public function testRecordingCaptionIncludesSongTitleAndTutti(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(94100, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $songs = new SongRepository($pdo);
        $songId = $songs->create('Les <sans> papier');
        $materials = new MaterialRepository($pdo);
        $materials->insertRecording($songId, VoicePart::CODES, 'aud1', 'un1', $uploaderId, null);

        $service = new MaterialsService($materials, $songs, $texts);
        $row = $materials->listCurrentRecordingsAllParts($songId)[0];
        $caption = $service->recordingCaption('Les <sans> papier', $row);

        self::assertStringContainsString('<b>Les &lt;sans&gt; papier</b>', $caption);
        self::assertStringContainsString('Партии: тутти', $caption);
    }

    public function testMyRecordingsButtonShowsSongListWithoutAudio(): void
    {
        $deps = $this->deps();
        $admin = $this->seedAdmin($deps);
        $member = $deps->users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $songId = $deps->songs->create('Voce Chegou');
        $deps->materials->insertRecording($songId, ['soprano_1'], 'a1', 'u1', (int) $admin['id'], null);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::MEMBER_TG));
        SongMemberHandler::openMyRecordings($bot, $deps, $deps->delivery, $member);

        $bot->assertCalled('sendMessage', 1);
        $bot->assertCalled('sendAudio', 0);
        $bot->assertReplyText(
            $deps->texts->get('songs.my_recordings_pick_header', [
                'part' => $deps->texts->get('parts.soprano_1'),
                'page' => '1',
                'pages' => '1',
            ]),
        );
        $callbacks = $this->callbackDataFromLastMessage($bot);
        self::assertContains('snd:my:' . $songId . ':soprano_1', $callbacks);
    }

    public function testSongSelectionSendsAudioWithCaptionAndNotesPreview(): void
    {
        $deps = $this->deps();
        $admin = $this->seedAdmin($deps);

        $songId = $deps->songs->create('Voce Chegou');
        $deps->materials->insertRecording($songId, ['soprano_1'], 'a1', 'u1', (int) $admin['id'], null);
        $long = implode("\n", array_map(static fn (int $i): string => 'Строка ' . $i, range(1, 12)));
        $deps->service->saveTextUpload($admin, $songId, MaterialType::TRANSLIT, $long, null);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::MEMBER_TG));
        SongsCallbackHandler::handle($bot, $deps, $deps->delivery, 'snd:my:' . $songId . ':soprano_1');

        $bot->assertCalled('sendAudio', 1);
        $data = FakeNutgram::getActualData($this->lastRequestForMethod($bot, 'sendAudio'));
        self::assertStringContainsString('Voce Chegou', (string) ($data['caption'] ?? ''));

        $previewMessages = array_filter(
            $this->bodiesForMethod($bot->getRequestHistory(), 'sendMessage'),
            static fn (array $body): bool => str_contains((string) ($body['text'] ?? ''), 'Строка 1'),
        );
        self::assertCount(1, $previewMessages);
        $callbacks = $this->callbackDataFromBody(array_values($previewMessages)[0]);
        self::assertContains('snd:tr:' . $songId, $callbacks);
    }

    public function testSongWithoutRecordingsForPartNotInList(): void
    {
        $deps = $this->deps();
        $admin = $this->seedAdmin($deps);
        $member = $deps->users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $visibleId = $deps->songs->create('Видимая');
        $hiddenId = $deps->songs->create('Только бас');
        $deps->materials->insertRecording($visibleId, ['soprano_1'], 'a1', 'u1', (int) $admin['id'], null);
        $deps->materials->insertRecording($hiddenId, ['bass'], 'b1', 'ub', (int) $admin['id'], null);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::MEMBER_TG));
        SongMemberHandler::openMyRecordings($bot, $deps, $deps->delivery, $member);

        $callbacks = $this->callbackDataFromLastMessage($bot);
        self::assertContains('snd:my:' . $visibleId . ':soprano_1', $callbacks);
        self::assertNotContains('snd:my:' . $hiddenId . ':soprano_1', $callbacks);
    }

    public function testEmptyRecordingsShowsDedicatedMessage(): void
    {
        $deps = $this->deps();
        $member = $deps->users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::MEMBER_TG));
        SongMemberHandler::openMyRecordings($bot, $deps, $deps->delivery, $member);

        $bot->assertCalled('sendAudio', 0);
        $bot->assertReplyText($deps->texts->get('songs.my_recordings_empty'));
    }

    public function testSongWithoutNotesSkipsPreviewOnSelection(): void
    {
        $deps = $this->deps();
        $admin = $this->seedAdmin($deps);

        $songId = $deps->songs->create('Только аудио');
        $deps->materials->insertRecording($songId, ['soprano_1'], 'a1', 'u1', (int) $admin['id'], null);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::MEMBER_TG));
        SongsCallbackHandler::handle($bot, $deps, $deps->delivery, 'snd:my:' . $songId . ':soprano_1');

        $bot->assertCalled('sendAudio', 1);
        $bot->assertCalled('sendMessage', 0);
    }

    public function testShortNotesPreviewWithoutExtraButtonOnSelection(): void
    {
        $deps = $this->deps();
        $admin = $this->seedAdmin($deps);

        $songId = $deps->songs->create('Короткая');
        $deps->materials->insertRecording($songId, ['soprano_1'], 'a1', 'u1', (int) $admin['id'], null);
        $deps->service->saveTextUpload($admin, $songId, MaterialType::TRANSLIT, "Одна\nДве", null);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::MEMBER_TG));
        SongsCallbackHandler::handle($bot, $deps, $deps->delivery, 'snd:my:' . $songId . ':soprano_1');

        $previewMessages = array_filter(
            $this->bodiesForMethod($bot->getRequestHistory(), 'sendMessage'),
            static fn (array $body): bool => str_contains((string) ($body['text'] ?? ''), 'Одна'),
        );
        self::assertCount(1, $previewMessages);
        $callbacks = $this->callbackDataFromBody(array_values($previewMessages)[0]);
        self::assertNotContains('snd:tr:' . $songId, $callbacks);
    }

  /**
     * @return list<string>
     */
    private function callbackDataFromLastMessage(Nutgram $bot): array
    {
        $history = $bot->getRequestHistory();
        $body = json_decode((string) $history[array_key_last($history)]['request']->getBody(), true);

        return $this->callbackDataFromBody($body ?: []);
    }

    private function lastRequestForMethod(Nutgram $bot, string $method): mixed
    {
        foreach (array_reverse($bot->getRequestHistory()) as $entry) {
            if (str_contains($entry['request']->getUri()->getPath(), $method)) {
                return $entry['request'];
            }
        }

        self::fail('Expected request for ' . $method);

        return null;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return list<array<string, mixed>>
     */
    private function bodiesForMethod(array $history, string $method): array
    {
        $bodies = [];
        foreach ($history as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), $method)) {
                continue;
            }
            $bodies[] = json_decode((string) $entry['request']->getBody(), true) ?: [];
        }

        return $bodies;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private function callbackDataFromBody(array $body): array
    {
        $keyboard = $body['reply_markup']['inline_keyboard'] ?? [];
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

    private function deps(): MaterialsDeps
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(94102, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songRepo = new SongRepository($pdo);
        $materialRepo = new MaterialRepository($pdo);
        $service = new MaterialsService($materialRepo, $songRepo, $texts);

        return new MaterialsDeps(
            $this->testConfig(),
            $users,
            new UserFlowRepository($pdo),
            $songRepo,
            $materialRepo,
            $service,
            new SongDeliveryService($materialRepo, $songRepo, $texts, $service),
            $texts,
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function seedAdmin(MaterialsDeps $deps): array
    {
        $admin = $deps->users->findByTelegramId(94102);
        self::assertNotNull($admin);

        return $admin;
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->id = $id;
        $user->first_name = 'U';

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