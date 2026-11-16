<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\MaterialType;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\ProfileHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\VoicePartRules;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class ConductorVoicePartTest extends TestCase
{
    public function testAppointConductorClearsVoicePart(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $memberId = $users->create(8801, 'Надима', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $users->appointConductor($memberId);
        $conductor = $users->findById($memberId);
        self::assertNotNull($conductor);
        self::assertSame(UserRole::CONDUCTOR, $conductor['role']);
        self::assertNull($conductor['voice_part']);
    }

    public function testUpsertConductorClearsVoicePart(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(8802, 'Бывший', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->upsertConductor(8802, 'Бывший');

        $conductor = $users->findByTelegramId(8802);
        self::assertNotNull($conductor);
        self::assertNull($conductor['voice_part']);
    }

    public function testMigrationSqlClearsConductorVoicePart(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $id = $users->create(8803, 'Дирижёр', 'soprano_1', UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $pdo->exec("UPDATE users SET voice_part = 'soprano_1' WHERE id = {$id}");

        $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/sqlite/009_conductor_no_voice_part.sql');
        foreach (Migrator::splitSql($sql) as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }

        self::assertNull($users->findById($id)['voice_part']);
    }

    public function testConductorInAllFilterNotInPartFilter(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $cid = $users->create(8804, 'Дирижёр', null, UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $memberId = $users->create(8805, 'Сопрано', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $all = array_map(static fn (array $r): int => (int) $r['id'], $users->listSingingMembersForAdmin(null));
        self::assertContains($cid, $all);

        $soprano = array_map(static fn (array $r): int => (int) $r['id'], $users->listSingingMembersForAdmin('soprano_1'));
        self::assertNotContains($cid, $soprano);
        self::assertContains($memberId, $soprano);
    }

    public function testConductorCannotSetVoicePartViaRepository(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $id = $users->create(8806, 'Дирижёр', null, UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $users->setVoicePart($id, 'tenor');

        self::assertNull($users->findById($id)['voice_part']);
    }

    public function testConductorProfileCallbackRejectsPartChange(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $tid = 8807;
        $users->create($tid, 'Дирижёр', null, UserRole::CONDUCTOR, UserStatus::ACTIVE);

        $bot = Nutgram::fake();
        $user = new User();
        $user->id = $tid;
        $bot->setCommonUser($user);

        $actor = $users->findByTelegramId($tid);
        self::assertNotNull($actor);

        ProfileHandler::handleCallback($bot, $texts, $users, $flows, 'prf:pt:tenor', $tid);
        $bot->assertCalled('answerCallbackQuery', 1);
        self::assertNull($users->findByTelegramId($tid)['voice_part']);
    }

    public function testConductorGetsAllPartRecordings(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(8810, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $songs = new SongRepository($pdo);
        $materials = new MaterialRepository($pdo);
        $songId = $songs->create('Тест');

        $materials->insertRecording($songId, ['tenor'], 'f1', 'u1', $uploaderId, null);
        $materials->insertRecording($songId, ['soprano_1'], 'f2', 'u2', $uploaderId, null);

        $all = $materials->listCurrentRecordingsAllParts($songId);
        self::assertCount(2, $all);
        self::assertNotEmpty($materials->listSongsWithRecordingsAnyPart());
    }

    public function testDemotedConductorLosesPartAndNeedsPrompt(): void
    {
        self::assertTrue(VoicePartRules::requiresAssignedVoicePart([
            'role' => UserRole::MEMBER,
            'voice_part' => null,
        ]));
    }

    public function testConductorHasNoPartLabelForDisplay(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        self::assertNull(VoicePartRules::partLabelForDisplay($texts, [
            'role' => UserRole::CONDUCTOR,
            'voice_part' => null,
        ]));
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