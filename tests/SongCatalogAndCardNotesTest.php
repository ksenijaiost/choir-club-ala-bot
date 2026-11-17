<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\MaterialType;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\MaterialsDeps;
use App\Handlers\SongMemberHandler;
use App\Handlers\SongsCallbackHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AdminChatSongListPresenter;
use App\Services\MaterialsService;
use App\Services\SongDeliveryService;
use App\Telegram\InteractiveMenuService;
use App\Telegram\TelegramMessageChunker;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class SongCatalogAndCardNotesTest extends TestCase
{
    private const ADMIN_CHAT = -1009001;
    private const MEMBER_TG = 93001;

    public function testCatalogIsSingleMessageWithCombinedHint(): void
    {
        $deps = $this->materialsDeps();
        $admin = $deps->users->findByTelegramId(93003);
        self::assertNotNull($admin);
        $songId = $deps->songs->create('Les sans papier');
        $deps->materials->insert($songId, MaterialType::NOTES, null, 'n1', 'u1', (int) $admin['id'], null, true);
        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::MEMBER_TG));

        SongMemberHandler::beginSearch($bot, $deps, self::MEMBER_TG);

        self::assertSame(1, $this->countApiCalls($bot, 'sendMessage'));
        $bot->assertReplyText(
            $deps->texts->get('songs.catalog_header', ['page' => '1', 'pages' => '1']),
        );
    }

    public function testCatalogPaginationEditsMessage(): void
    {
        $deps = $this->materialsDeps();
        $admin = $deps->users->findByTelegramId(93003);
        self::assertNotNull($admin);
        for ($i = 0; $i < 12; $i++) {
            $songId = $deps->songs->create('Песня ' . $i);
            $deps->materials->insert($songId, MaterialType::NOTES, null, 'f' . $i, 'u' . $i, (int) $admin['id'], null, true);
        }

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::MEMBER_TG));
        SongMemberHandler::sendMaterialCatalog($bot, $deps, 0);
        self::assertSame(1, $this->countApiCalls($bot, 'sendMessage'));

        $bot->hearCallbackQueryData('snd:cat:pg:1')
            ->willStartConversation()
            ->reply();
        SongsCallbackHandler::handle($bot, $deps, $deps->delivery, 'snd:cat:pg:1');

        self::assertGreaterThanOrEqual(1, $this->countApiCalls($bot, 'editMessageText'));
    }

    public function testShortTextNotesInCardWithoutTranslitButton(): void
    {
        $deps = $this->materialsDeps();
        $admin = $deps->users->findByTelegramId(93003);
        $songId = $deps->songs->create('Короткая');
        $deps->service->saveTextUpload($admin, $songId, MaterialType::TRANSLIT, "Одна\nДве", null);
        $member = $deps->users->findByTelegramId(self::MEMBER_TG);

        $bot = Nutgram::fake();
        $deps->delivery->sendSongCard($bot, $deps->songs->findById($songId) ?? [], $member);
        $callbacks = $this->callbackDataFromLastMessage($bot);

        self::assertNotContains('snd:tr:' . $songId, $callbacks);
        $history = $bot->getRequestHistory();
        $body = json_decode((string) $history[array_key_last($history)]['request']->getBody(), true);
        self::assertStringContainsString('Одна', (string) ($body['text'] ?? ''));
    }

    public function testLongNotesAddsFullButton(): void
    {
        $deps = $this->materialsDeps();
        $admin = $deps->users->findByTelegramId(93003);
        $songId = $deps->songs->create('Длинная');
        $long = implode("\n", array_map(static fn (int $i): string => 'Строка ' . $i, range(1, 12)));
        $deps->service->saveTextUpload($admin, $songId, MaterialType::TRANSLIT, $long, null);
        $member = $deps->users->findByTelegramId(self::MEMBER_TG);

        $bot = Nutgram::fake();
        $deps->delivery->sendSongCard($bot, $deps->songs->findById($songId) ?? [], $member);

        $callbacks = $this->callbackDataFromLastMessage($bot);
        self::assertContains('snd:tr:' . $songId, $callbacks);
    }

    public function testFileOnlyNotesKeepTranslitButton(): void
    {
        $deps = $this->materialsDeps();
        $admin = $deps->users->findByTelegramId(93003);
        $songId = $deps->songs->create('Файл');
        $deps->service->saveUpload(
            $admin,
            $songId,
            MaterialType::TRANSLIT,
            null,
            'file-id',
            'uniq-file',
            null,
        );
        $member = $deps->users->findByTelegramId(self::MEMBER_TG);

        $bot = Nutgram::fake();
        $deps->delivery->sendSongCard($bot, $deps->songs->findById($songId) ?? [], $member);
        $callbacks = $this->callbackDataFromLastMessage($bot);

        self::assertContains('snd:tr:' . $songId, $callbacks);
    }

    public function testAdminSongsNestedFormatAndZeroNotes(): void
    {
        $deps = $this->materialsDeps();
        $songId = $deps->songs->create('Jekabsone — Father Thunder');
        $presenter = new AdminChatSongListPresenter($deps->songs, $deps->materials, $deps->texts);
        $chunk = $presenter->buildMessageChunks()[0];

        self::assertStringContainsString('<b>Jekabsone — Father Thunder</b>', $chunk);
        self::assertStringContainsString('• ноты: 0', $chunk);
        self::assertStringContainsString('• заметки: нет', $chunk);
        self::assertStringContainsString('• записи: нет', $chunk);
    }

    public function testAdminSongsChunksDoNotSplitInsideSongBlock(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $songs = new SongRepository($pdo);
        for ($i = 0; $i < 120; $i++) {
            $songs->create('Очень длинное название песни для чанков ' . $i);
        }

        $presenter = new AdminChatSongListPresenter($songs, new MaterialRepository($pdo), $texts);
        $chunks = $presenter->buildMessageChunks();
        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(TelegramMessageChunker::MAX_MESSAGE_LENGTH, mb_strlen($chunk));
            self::assertSame(0, preg_match('/<b>[^<]*$/u', $chunk));
        }
    }

    private function materialsDeps(): MaterialsDeps
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(93003, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songRepo = new SongRepository($pdo);
        $materialRepo = new MaterialRepository($pdo);
        $service = new MaterialsService($materialRepo, $songRepo, $texts);
        $delivery = new SongDeliveryService($materialRepo, $songRepo, $texts, $service);

        return new MaterialsDeps(
            $config,
            $users,
            new UserFlowRepository($pdo),
            $songRepo,
            $materialRepo,
            $service,
            $delivery,
            $texts,
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );
    }

    private function countApiCalls(Nutgram $bot, string $method): int
    {
        $count = 0;
        foreach ($bot->getRequestHistory() as $entry) {
            if (str_contains((string) $entry['request']->getUri(), $method)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function callbackDataFromLastMessage(Nutgram $bot): array
    {
        $history = $bot->getRequestHistory();
        $body = json_decode((string) $history[array_key_last($history)]['request']->getBody(), true);
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
            adminChatId: (string) self::ADMIN_CHAT,
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