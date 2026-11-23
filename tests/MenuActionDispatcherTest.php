<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\HandlerRegistry;
use App\Repositories\UserRepository;
use App\Text\TextRepository;
use App\Tests\Support\NutgramTestConfig;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class MenuActionDispatcherTest extends TestCase
{
    private const TG = 88_001;

    public function testSongCommandOpensSameFlowAsMenuButton(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(self::TG, 'Участник', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $countFor = static function (string $incoming) use ($texts, $config, $pdo): int {
            $bot = NutgramTestConfig::fakeBot();
            $bot->setCommonUser(User::make(id: self::TG, is_bot: false, first_name: 'U'));
            $bot->setCommonChat(Chat::make(id: self::TG, type: ChatType::PRIVATE));
            HandlerRegistry::register($bot, $texts, $config, $pdo);
            $bot->hearText($incoming)->reply();
            $count = 0;
            foreach ($bot->getRequestHistory() as $entry) {
                if (str_contains($entry['request']->getUri()->getPath(), 'sendMessage')) {
                    ++$count;
                }
            }

            return $count;
        };

        self::assertSame($countFor('/song'), $countFor($texts->get('menu.find_song')));
        self::assertGreaterThan(0, $countFor('/song'));
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
            webhookSecret: '',
            adminPagesKey: '',
            webhookUrl: '',
            fakeNow: null,
        );
    }
}