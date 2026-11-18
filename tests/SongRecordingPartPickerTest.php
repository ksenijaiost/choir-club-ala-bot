<?php

declare(strict_types=1);

namespace App\Tests;

use App\Tests\Support\MaterialsTestDeps;
use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\MaterialsDeps;
use App\Handlers\SongsCallbackHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\MaterialsService;
use App\Services\SongDeliveryService;
use App\Services\SongRecordingPartPicker;
use App\Telegram\InteractiveMenuService;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class SongRecordingPartPickerTest extends TestCase
{
    private const MEMBER_TG = 95101;

    public function testMemberHomeShowsOwnPartFirstAndOtherPartsButton(): void
    {
        $deps = $this->deps();
        $admin = $this->seedAdmin($deps);
        $member = $deps->users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);
        $songId = $deps->songs->create('Гимн Воланда');
        $deps->materials->insertRecording($songId, ['soprano_1'], 'r1', 'u1', (int) $admin['id'], null);
        $deps->materials->insertRecording($songId, ['alto_1'], 'r2', 'u2', (int) $admin['id'], null);

        $built = $this->picker($deps)->build($songId, 'Гимн Воланда', $member, SongRecordingPartPicker::VIEW_HOME);
        self::assertNotNull($built);
        $callbacks = $this->callbackData($built['keyboard']);
        self::assertContains('snd:rp:' . $songId . ':soprano_1', $callbacks);
        self::assertContains('snd:pr:oth:' . $songId, $callbacks);
        self::assertStringContainsString('⭐', $this->buttonLabel($built['keyboard'], 'snd:rp:' . $songId . ':soprano_1'));
        self::assertNotContains('snd:rp:' . $songId . ':alto_1', $callbacks);
    }

    public function testOtherPartsScreenAndBackToHome(): void
    {
        $deps = $this->deps();
        $admin = $this->seedAdmin($deps);
        $member = $deps->users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);
        $songId = $deps->songs->create('Гимн Воланда');
        $deps->materials->insertRecording($songId, ['soprano_1'], 'r1', 'u1', (int) $admin['id'], null);
        $deps->materials->insertRecording($songId, ['alto_1'], 'r2', 'u2', (int) $admin['id'], null);

        $others = $this->picker($deps)->build($songId, 'Гимн Воланда', $member, SongRecordingPartPicker::VIEW_OTHERS);
        self::assertNotNull($others);
        $otherCallbacks = $this->callbackData($others['keyboard']);
        self::assertContains('snd:rp:' . $songId . ':alto_1', $otherCallbacks);
        self::assertContains('snd:pr:hom:' . $songId, $otherCallbacks);

        $home = $this->picker($deps)->build($songId, 'Гимн Воланда', $member, SongRecordingPartPicker::VIEW_HOME);
        self::assertNotNull($home);
        self::assertContains('snd:pr:oth:' . $songId, $this->callbackData($home['keyboard']));
    }

    public function testNoRecordingsForOwnPartShowsMessageAndOtherParts(): void
    {
        $deps = $this->deps();
        $admin = $this->seedAdmin($deps);
        $member = $deps->users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);
        $songId = $deps->songs->create('Гимн');
        $deps->materials->insertRecording($songId, ['bass'], 'r1', 'u1', (int) $admin['id'], null);

        $built = $this->picker($deps)->build($songId, 'Гимн', $member, SongRecordingPartPicker::VIEW_HOME);
        self::assertNotNull($built);
        self::assertStringContainsString(
            $deps->texts->get('songs.recordings_no_for_your_part'),
            $built['text'],
        );
        $callbacks = $this->callbackData($built['keyboard']);
        self::assertNotContains('snd:rp:' . $songId . ':soprano_1', $callbacks);
        self::assertContains('snd:pr:oth:' . $songId, $callbacks);
    }

    public function testConductorSeesAllPartsInVoiceOrder(): void
    {
        $deps = $this->deps();
        $admin = $this->seedAdmin($deps);
        $deps->users->create(95102, 'Дирижёр', null, UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $conductor = $deps->users->findByTelegramId(95102);
        self::assertNotNull($conductor);

        $songId = $deps->songs->create('Хор');
        $deps->materials->insertRecording($songId, ['tenor'], 't1', 'ut', (int) $admin['id'], null);
        $deps->materials->insertRecording($songId, ['soprano_1'], 's1', 'us', (int) $admin['id'], null);

        $built = $this->picker($deps)->build($songId, 'Хор', $conductor, SongRecordingPartPicker::VIEW_HOME);
        self::assertNotNull($built);
        $callbacks = $this->callbackData($built['keyboard']);
        self::assertContains('snd:rp:' . $songId . ':soprano_1', $callbacks);
        self::assertContains('snd:rp:' . $songId . ':tenor', $callbacks);
        self::assertNotContains('snd:pr:oth:' . $songId, $callbacks);

        $partCallbacks = array_values(array_filter(
            $callbacks,
            static fn (string $c): bool => str_starts_with($c, 'snd:rp:' . $songId . ':'),
        ));
        self::assertSame(['snd:rp:' . $songId . ':soprano_1', 'snd:rp:' . $songId . ':tenor'], $partCallbacks);
    }

    public function testListPartsOrderedByVoicePartCodes(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $materials = new MaterialRepository($pdo);
        $songs = new SongRepository($pdo);
        $users = new UserRepository($pdo);
        $uploaderId = $users->create(1, 'A', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $songId = $songs->create('Порядок');
        $materials->insertRecording($songId, ['bass'], 'b', 'ub', $uploaderId, null);
        $materials->insertRecording($songId, ['soprano_1'], 's', 'us', $uploaderId, null);

        self::assertSame(['soprano_1', 'bass'], $materials->listPartsWithRecordingsForSongOrdered($songId));
    }

    public function testMyRecordingsPickerDoesNotSendAudio(): void
    {
        $deps = $this->deps();
        $admin = $this->seedAdmin($deps);
        $member = $deps->users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);
        $songId = $deps->songs->create('Гимн');
        $deps->materials->insertRecording($songId, ['soprano_1'], 'r1', 'u1', (int) $admin['id'], null);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::MEMBER_TG));
        $bot->hearCallbackQueryData('snd:pr:' . $songId);
        SongsCallbackHandler::handle($bot, $deps, $deps->delivery, 'snd:pr:' . $songId);

        $bot->assertCalled('sendAudio', 0);
        $bot->assertCalled('sendMessage', 1);
    }

    private function picker(MaterialsDeps $deps): SongRecordingPartPicker
    {
        return new SongRecordingPartPicker($deps->materials, $deps->texts);
    }

    /**
     * @return list<string>
     */
    private function callbackData(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup $keyboard): array
    {
        $callbacks = [];
        foreach ($keyboard->inline_keyboard ?? [] as $row) {
            foreach ($row as $button) {
                if (isset($button->callback_data)) {
                    $callbacks[] = (string) $button->callback_data;
                }
            }
        }

        return $callbacks;
    }

    private function buttonLabel(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup $keyboard, string $callback): string
    {
        foreach ($keyboard->inline_keyboard ?? [] as $row) {
            foreach ($row as $button) {
                if (($button->callback_data ?? '') === $callback) {
                    return (string) ($button->text ?? '');
                }
            }
        }

        return '';
    }

    private function deps(): MaterialsDeps
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(95100, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songRepo = new SongRepository($pdo);
        $materialRepo = new MaterialRepository($pdo);
        return MaterialsTestDeps::build(
            $this->testConfig(),
            $users,
            new UserFlowRepository($pdo),
            $songRepo,
            $materialRepo,
            $texts,
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function seedAdmin(MaterialsDeps $deps): array
    {
        $admin = $deps->users->findByTelegramId(95100);
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