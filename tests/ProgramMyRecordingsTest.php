<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
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
use App\Tests\Support\MaterialsTestDeps;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Handlers\ProgramDeps;
use App\Telegram\InteractiveMenuService;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class ProgramMyRecordingsTest extends TestCase
{
    private const MEMBER_TG = 96201;

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

    public function testHubThreeButtonsWithActiveProgram(): void
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
        self::assertContains('prg:mr:ls:0', $callbacks);
        self::assertContains('prg:mr:pk', $callbacks);
    }

    /**
     * @return array<string, mixed>
     */
    private function bundle(): array
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->config();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(96200, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $users->create(self::MEMBER_TG, 'Участник', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $flows = new UserFlowRepository($pdo);
        $interactive = new InteractiveMenuService(new UserOpenMenuRepository($pdo));
        $songs = new SongRepository($pdo);
        $materials = new MaterialRepository($pdo);
        $materialsDeps = MaterialsTestDeps::build($config, $users, $flows, $songs, $materials, $texts, $interactive);
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
        $programDeps = new ProgramDeps($config, $users, $flows, $programsRepo, $songs, $programMessages, $delivery, $texts, $interactive);

        return [
            'texts' => $texts,
            'users' => $users,
            'materials' => $materialsDeps,
            'programs' => $programDeps,
            'programsRepo' => $programsRepo,
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

    private function config(): Config
    {
        return new Config(
            botToken: 't',
            ownerTelegramId: '1',
            conductorTelegramId: '2',
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