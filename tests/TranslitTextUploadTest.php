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
use App\Services\MaterialsService;
use App\Services\SongDeliveryService;
use App\Services\TranslitUploadCommand;
use App\Telegram\TelegramMessageChunker;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class TranslitTextUploadTest extends TestCase
{
    private const ADMIN_CHAT_ID = -1008035505540528;
    private const ADMIN_TG = 88001;
    private const MEMBER_TG = 88002;

    public function testExtractFromReplyPreservesNewlines(): void
    {
        $reply = new Message();
        $reply->text = "Строка 1\n\nСтрока 2";

        $message = new Message();
        $message->text = '/translit';
        $message->reply_to_message = $reply;

        self::assertSame("Строка 1\n\nСтрока 2", TranslitUploadCommand::extractText($message));
    }

    public function testExtractFromSameMessageAfterCommand(): void
    {
        $message = new Message();
        $message->text = "/translit\nПривет\nМир";

        self::assertSame("Привет\nМир", TranslitUploadCommand::extractText($message));
    }

    public function testEmptyTranslitCommandReturnsNullBody(): void
    {
        $message = new Message();
        $message->text = '/translit';

        self::assertNull(TranslitUploadCommand::extractText($message));
    }

    public function testUploadViaCommandThenSongSelection(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $deps = $this->materialsDeps($texts);
        $admin = $this->seedAdmin($deps);

        $deps->flows->set(self::ADMIN_TG, 'material_upload', [
            'source' => 'text_translit',
            'text_content' => "У-у-у\nНа небе",
            'type' => MaterialType::TRANSLIT,
            'step' => 'song',
            'admin_chat_id' => self::ADMIN_CHAT_ID,
        ]);

        $songId = $deps->songs->create('Новая песня');
        $bot = Nutgram::fake();
        $bot->setCommonUser($this->telegramUser(self::ADMIN_TG));

        MaterialsCallbackHandler::handle(
            $bot,
            $deps,
            new AccessControl($this->testConfig()),
            $admin,
            'mat:up:sn:' . $songId,
        );

        $current = $deps->materials->findCurrentSingleton($songId, MaterialType::TRANSLIT);
        self::assertNotNull($current);
        self::assertSame("У-у-у\nНа небе", $current['text_content']);
        self::assertNull($current['telegram_file_id']);
        $bot->assertReplyText(
            $texts->get('materials.text_notes_saved_new', ['song' => 'Новая песня']),
            index: 1,
        );
    }

    public function testMemberSongCardUsesTextNotesButtonLabel(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $deps = $this->materialsDeps($texts);
        $song = $deps->songs->findById($deps->songs->create('Тест'));
        self::assertNotNull($song);

        self::assertSame('Заметки (транслит и пр.)', $texts->get('buttons.translit'));

        $bot = Nutgram::fake();
        $deps->delivery->sendSongCard($bot, $song);
        $bot->assertReplyText($texts->get('songs.card_header', ['title' => 'Тест']));
    }

    public function testAppendEndMergesExistingText(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $deps = $this->materialsDeps($texts);
        $admin = $this->seedAdmin($deps);
        $songId = $deps->songs->create('Склейка');

        $deps->service->saveTextUpload($admin, $songId, MaterialType::TRANSLIT, 'Старый', null);
        $deps->service->saveTextUpload(
            $admin,
            $songId,
            MaterialType::TRANSLIT,
            'Новый',
            null,
            false,
            'append_end',
        );

        $expected = 'Старый' . MaterialsService::TEXT_NOTES_SEPARATOR . 'Новый';
        $current = $deps->materials->findCurrentSingleton($songId, MaterialType::TRANSLIT);
        self::assertSame($expected, $current['text_content'] ?? null);

        $bot = Nutgram::fake();
        $deps->delivery->sendSingletonFile($bot, $songId, MaterialType::TRANSLIT);
        $bot->assertReplyText($expected);
    }

    public function testAppendStartMergesExistingText(): void
    {
        $deps = $this->materialsDeps(TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json'));
        $admin = $this->seedAdmin($deps);
        $songId = $deps->songs->create('Начало');

        $deps->service->saveTextUpload($admin, $songId, MaterialType::TRANSLIT, 'Старый', null);
        $deps->service->saveTextUpload(
            $admin,
            $songId,
            MaterialType::TRANSLIT,
            'Новый',
            null,
            false,
            'append_start',
        );

        $expected = 'Новый' . MaterialsService::TEXT_NOTES_SEPARATOR . 'Старый';
        $current = $deps->materials->findCurrentSingleton($songId, MaterialType::TRANSLIT);
        self::assertSame($expected, $current['text_content']);
    }

    public function testReplaceExistingTranslitArchivesPrevious(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $deps = $this->materialsDeps($texts);
        $admin = $this->seedAdmin($deps);
        $songId = $deps->songs->create('Песня');

        $deps->service->saveTextUpload($admin, $songId, MaterialType::TRANSLIT, 'Старый', null);
        $deps->service->saveTextUpload($admin, $songId, MaterialType::TRANSLIT, 'Новый', null, false, 'replace');

        $current = $deps->materials->findCurrentSingleton($songId, MaterialType::TRANSLIT);
        self::assertSame('Новый', $current['text_content'] ?? null);

        $stmt = $deps->materials;
        $pdo = $this->pdoFromDeps($deps);
        $archived = $pdo->query(
            'SELECT COUNT(*) FROM materials WHERE song_id = ' . $songId . " AND type = 'translit' AND is_current = 0"
        );
        self::assertSame(1, (int) $archived->fetchColumn());
    }

    public function testReplaceConfirmFlowForTextTranslit(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $deps = $this->materialsDeps($texts);
        $admin = $this->seedAdmin($deps);
        $songId = $deps->songs->create('Песня');

        $deps->service->saveTextUpload($admin, $songId, MaterialType::TRANSLIT, 'Версия 1', null);

        $deps->flows->set(self::ADMIN_TG, 'material_upload', [
            'source' => 'text_translit',
            'text_content' => 'Версия 2',
            'type' => MaterialType::TRANSLIT,
            'song_id' => $songId,
            'admin_chat_id' => self::ADMIN_CHAT_ID,
        ]);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->telegramUser(self::ADMIN_TG));
        MaterialsFlowHandler::completeUpload($bot, $deps, $admin);

        $bot->assertReplyText($texts->get('materials.text_notes_upload_choice', ['title' => 'Песня']));

        MaterialsCallbackHandler::handle(
            $bot,
            $deps,
            new AccessControl($this->testConfig()),
            $admin,
            'mat:up:tr:end',
        );

        $expected = 'Версия 1' . MaterialsService::TEXT_NOTES_SEPARATOR . 'Версия 2';
        self::assertSame($expected, $deps->materials->findCurrentSingleton($songId, MaterialType::TRANSLIT)['text_content'] ?? null);
        $bot->assertReplyText(
            $texts->get('materials.text_notes_saved_append_end', ['song' => 'Песня'])
                . "\n"
                . $texts->get('materials.archived_notice'),
            index: 2,
        );
    }

    public function testUploadChoiceReplaceMode(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $deps = $this->materialsDeps($texts);
        $admin = $this->seedAdmin($deps);
        $songId = $deps->songs->create('Песня');

        $deps->service->saveTextUpload($admin, $songId, MaterialType::TRANSLIT, 'Версия 1', null);

        $deps->flows->set(self::ADMIN_TG, 'material_upload', [
            'source' => 'text_translit',
            'text_content' => 'Версия 2',
            'type' => MaterialType::TRANSLIT,
            'song_id' => $songId,
            'admin_chat_id' => self::ADMIN_CHAT_ID,
        ]);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->telegramUser(self::ADMIN_TG));
        MaterialsFlowHandler::completeUpload($bot, $deps, $admin);

        MaterialsCallbackHandler::handle(
            $bot,
            $deps,
            new AccessControl($this->testConfig()),
            $admin,
            'mat:up:tr:repl',
        );

        self::assertSame('Версия 2', $deps->materials->findCurrentSingleton($songId, MaterialType::TRANSLIT)['text_content'] ?? null);
        $bot->assertReplyText(
            $texts->get('materials.text_notes_saved_replace', ['song' => 'Песня'])
                . "\n"
                . $texts->get('materials.archived_notice'),
            index: 2,
        );
    }

    public function testLegacyFileNotesAllowReplaceOnlyInFlow(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $deps = $this->materialsDeps($texts);
        $admin = $this->seedAdmin($deps);
        $songId = $deps->songs->create('Файл');

        $deps->materials->insert(
            $songId,
            MaterialType::TRANSLIT,
            null,
            'doc-file',
            'uniq-file-notes',
            (int) $admin['id'],
            null,
            true,
            null,
        );

        $deps->flows->set(self::ADMIN_TG, 'material_upload', [
            'source' => 'text_translit',
            'text_content' => 'Текстом',
            'type' => MaterialType::TRANSLIT,
            'song_id' => $songId,
            'admin_chat_id' => self::ADMIN_CHAT_ID,
        ]);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->telegramUser(self::ADMIN_TG));
        MaterialsFlowHandler::completeUpload($bot, $deps, $admin);

        $bot->assertReplyText($texts->get('materials.text_notes_file_only', ['title' => 'Файл']));
    }

    public function testDeliverySendsTextAndSplitsLongContent(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $deps = $this->materialsDeps($texts);
        $admin = $this->seedAdmin($deps);
        $songId = $deps->songs->create('Длинная');

        $short = "А\nБ";
        $deps->service->saveTextUpload($admin, $songId, MaterialType::TRANSLIT, $short, null, false, 'replace');

        $bot = Nutgram::fake();
        $deps->delivery->sendSingletonFile($bot, $songId, MaterialType::TRANSLIT);
        $bot->assertReplyText($short);

        $longLine = str_repeat('я', 3000);
        $long = $longLine . "\n" . $longLine;
        $deps->service->saveTextUpload($admin, $songId, MaterialType::TRANSLIT, $long, null, false, 'replace');

        $chunks = TelegramMessageChunker::splitByLines($long);
        self::assertGreaterThan(1, count($chunks));
        self::assertTrue(mb_strlen($chunks[0]) <= TelegramMessageChunker::MAX_MESSAGE_LENGTH);

        $bot = Nutgram::fake();
        $deps->delivery->sendSingletonFile($bot, $songId, MaterialType::TRANSLIT);
        $bot->assertCalled('sendMessage', count($chunks));
    }

    public function testDeliveryFallsBackToFileForLegacyTranslit(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $deps = $this->materialsDeps($texts);
        $admin = $this->seedAdmin($deps);
        $songId = $deps->songs->create('Файловая');

        $deps->materials->insert(
            $songId,
            MaterialType::TRANSLIT,
            null,
            'doc-file',
            'unique-translit-legacy',
            (int) $admin['id'],
            null,
            true,
            null,
        );

        $bot = Nutgram::fake();
        $deps->delivery->sendSingletonFile($bot, $songId, MaterialType::TRANSLIT);
        $bot->assertCalled('sendDocument');
    }

    public function testMemberWithoutUploadRightsGetsNoFlow(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $deps = $this->materialsDeps($texts);
        $deps->users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $access = new AccessControl($this->testConfig());
        $member = $deps->users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);
        self::assertFalse($access->canUploadMaterials($member));
    }

    private function seedAdmin(MaterialsDeps $deps): array
    {
        $deps->users->create(self::ADMIN_TG, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $admin = $deps->users->findByTelegramId(self::ADMIN_TG);
        self::assertNotNull($admin);

        return $admin;
    }

    private function materialsDeps(TextRepository $texts): MaterialsDeps
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $songRepo = new SongRepository($pdo);
        $materialRepo = new MaterialRepository($pdo);
        $materialsService = new MaterialsService($materialRepo, $songRepo, $texts);

        return new MaterialsDeps(
            $this->testConfig(),
            $users,
            $flows,
            $songRepo,
            $materialRepo,
            $materialsService,
            new SongDeliveryService($materialRepo, $texts, $materialsService),
            $texts,
                    new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );
    }

    private function pdoFromDeps(MaterialsDeps $deps): \PDO
    {
        $ref = new \ReflectionProperty(MaterialRepository::class, 'pdo');
        $ref->setAccessible(true);
        /** @var \PDO $pdo */
        $pdo = $ref->getValue($deps->materials);

        return $pdo;
    }

    private function telegramUser(int $id): User
    {
        $user = new User();
        $user->id = $id;
        $user->first_name = 'User';

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