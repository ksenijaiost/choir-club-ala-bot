<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\HandlerRegistry;
use App\Repositories\RegistrationRepository;
use App\Repositories\UserRepository;
use App\Services\AdminChatMemberListPresenter;
use App\Services\RegistrationAdminMessageBuilder;
use App\Services\UserRolePresenter;
use App\Telegram\TelegramMessageChunker;
use App\Text\TextRepository;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use SergiX44\Nutgram\Testing\FakeNutgram as FakeNutgramClass;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class RegistrationProfileAndModerationTest extends TestCase
{
    private const ADMIN_CHAT_ID = -100_8801;
    private const APPLICANT_TG = 88010;
    private const MODERATOR_TG = 88020;

    public function testNewRequestMessageContainsProfileLinkAndUsername(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $builder = new RegistrationAdminMessageBuilder($texts, $this->testConfig());
        $applicant = [
            'display_name' => 'Ксения',
            'telegram_id' => 123456789,
            'telegram_username' => 'ksenija',
        ];
        $tgUser = new User();
        $tgUser->first_name = 'Ксения';
        $tgUser->last_name = 'Иост';

        $html = $builder->buildNewRequest($applicant, 'Сопрано 1', $tgUser);

        self::assertStringContainsString('tg://user?id=123456789', $html);
        self::assertStringContainsString('https://t.me/ksenija', $html);
        self::assertStringContainsString('ID: 123456789', $html);
        self::assertStringContainsString('Принять или отклонить?', $html);
    }

    public function testNewRequestWithoutUsernameOmitsAtLink(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $builder = new RegistrationAdminMessageBuilder($texts, $this->testConfig());
        $html = $builder->buildNewRequest([
            'display_name' => 'Иван',
            'telegram_id' => 42,
            'telegram_username' => null,
        ], 'Тенор', null);

        self::assertStringContainsString('tg://user?id=42', $html);
        self::assertStringNotContainsString('https://t.me/', $html);
        self::assertStringContainsString('ID: 42', $html);
    }

    public function testSpecialCharactersEscapedInNewRequest(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $builder = new RegistrationAdminMessageBuilder($texts, $this->testConfig());
        $html = $builder->buildNewRequest([
            'display_name' => 'A & B <script>',
            'telegram_id' => 99,
            'telegram_username' => 'x',
        ], 'Бас', null);

        self::assertStringContainsString('A &amp; B &lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testResolvedAcceptedMessageFormat(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $builder = new RegistrationAdminMessageBuilder($texts, $this->testConfig());
        $html = $builder->buildResolvedAccepted([
            'display_name' => 'Новенькая',
            'telegram_id' => self::APPLICANT_TG,
            'telegram_username' => 'newbie',
        ], 'Сопрано 1', [
            'display_name' => 'Модератор',
        ]);

        self::assertStringContainsString('✅ Заявка принята', $html);
        self::assertStringContainsString('tg://user?id=' . self::APPLICANT_TG, $html);
        self::assertStringContainsString('Принял(а):', $html);
        self::assertStringNotContainsString('Принять или отклонить?', $html);
    }

    public function testMembersListUsesProfileLinksAndChunksPreserveHtml(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        for ($i = 0; $i < 120; $i++) {
            $id = $users->create(90_000 + $i, 'Участник ' . $i, 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
            $users->updateTelegramUsername($id, 'user' . $i);
        }

        $presenter = new AdminChatMemberListPresenter($users, $texts, new UserRolePresenter($texts));
        $chunks = $presenter->buildMessageChunks();
        self::assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(TelegramMessageChunker::MAX_MESSAGE_LENGTH, mb_strlen($chunk));
            self::assertSame(
                substr_count($chunk, '<a '),
                substr_count($chunk, '</a>'),
                'Each chunk must have balanced anchor tags',
            );
            self::assertStringContainsString('tg://user?id=', $chunk);
        }
    }

    public function testAcceptReplacesAdminMessageAndClearsButtons(): void
    {
        $ctx = $this->seedModerationScenario();
        $bot = $this->moderationBot($ctx['pdo']);

        $this->dispatchRegistrationReview($bot, 'rr:a:' . $ctx['requestId']);

        $bot->assertCalled('editMessageText');
        $this->assertEditMessageTextContains($bot, function (array $body): bool {
            $text = (string) ($body['text'] ?? '');
            if (($body['parse_mode'] ?? null) !== 'HTML') {
                return false;
            }
            $replyMarkup = $body['reply_markup'] ?? null;
            if (is_string($replyMarkup)) {
                $replyMarkup = json_decode($replyMarkup, true);
            }
            if (($replyMarkup['inline_keyboard'] ?? null) !== []) {
                return false;
            }

            return str_contains($text, '✅ Заявка принята')
                && ! str_contains($text, 'Принять или отклонить?')
                && str_contains($text, 'tg://user?id=' . self::APPLICANT_TG)
                && str_contains($text, 'Принял(а):');
        });
    }

    public function testRejectReplacesAdminMessage(): void
    {
        $ctx = $this->seedModerationScenario();
        $bot = $this->moderationBot($ctx['pdo']);
        $this->dispatchRegistrationReview($bot, 'rr:r:' . $ctx['requestId']);

        $bot->assertCalled('editMessageText');
        $this->assertEditMessageTextContains($bot, function (array $body): bool {
            $text = (string) ($body['text'] ?? '');

            return str_contains($text, '❌ Заявка отклонена')
                && str_contains($text, 'Отклонил(а):');
        });
    }

    public function testSecondClickShowsAlreadyHandled(): void
    {
        $ctx = $this->seedModerationScenario();
        $data = 'rr:a:' . $ctx['requestId'];

        $bot = $this->moderationBot($ctx['pdo']);
        $this->dispatchRegistrationReview($bot, $data);

        $bot = $this->moderationBot($ctx['pdo']);
        $this->dispatchRegistrationReview($bot, $data);

        $bot->assertCalled('answerCallbackQuery', 1);
    }

    private function dispatchRegistrationReview(Nutgram $bot, string $data): void
    {
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'id' => '1',
            'from' => [
                'id' => self::MODERATOR_TG,
                'is_bot' => false,
                'first_name' => 'Модератор',
            ],
            'message' => [
                'message_id' => 500,
                'date' => time(),
                'chat' => [
                    'id' => self::ADMIN_CHAT_ID,
                    'type' => 'supergroup',
                ],
                'text' => 'pending',
            ],
            'data' => $data,
        ])->reply();
    }

    private function moderationBot(\PDO $pdo): Nutgram
    {
        $config = $this->testConfig();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');

        $bot = Nutgram::fake();
        $chat = new Chat();
        $chat->id = self::ADMIN_CHAT_ID;
        $chat->type = ChatType::SUPERGROUP;
        $bot->setCommonChat($chat);
        $user = new User();
        $user->id = self::MODERATOR_TG;
        $user->first_name = 'Модератор';
        $bot->setCommonUser($user);

        HandlerRegistry::register($bot, $texts, $config, $pdo);

        return $bot;
    }

    /**
     * @param callable(array<string, mixed>): bool $assertion
     */
    private function assertEditMessageTextContains(Nutgram $bot, callable $assertion): void
    {
        $limit = $this->testingHistoryCount($bot);
        for ($i = 0; $i < $limit; $i++) {
            try {
                $bot->assertRaw(function (Request $request) use ($assertion): bool {
                    if ($request->getUri()->getPath() !== 'editMessageText') {
                        return false;
                    }
                    $body = FakeNutgram::getActualData($request);

                    return $assertion($body);
                }, $i);

                return;
            } catch (\PHPUnit\Framework\ExpectationFailedException) {
                continue;
            } catch (\TypeError) {
                continue;
            }
        }

        self::fail('No editMessageText request matched expectations.');
    }

    /**
     * @return array{pdo: \PDO, requestId: int}
     */
    private function seedModerationScenario(): array
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $registrations = new RegistrationRepository($pdo);

        $users->create(self::MODERATOR_TG, 'Модератор', 'alto_1', UserRole::ADMIN, UserStatus::ACTIVE);

        $appId = $users->create(self::APPLICANT_TG, 'Новенькая', 'soprano_1', UserRole::MEMBER, UserStatus::PENDING);
        $users->updateTelegramUsername($appId, 'newbie');
        $users->updateVoicePart($appId, 'soprano_1');

        $requestId = $registrations->create($appId);

        return [
            'pdo' => $pdo,
            'requestId' => $requestId,
        ];
    }

    private function testingHistoryCount(Nutgram $bot): int
    {
        if (!$bot instanceof FakeNutgramClass) {
            return 0;
        }

        $history = new \ReflectionProperty(FakeNutgramClass::class, 'testingHistory');
        $history->setAccessible(true);

        return count($history->getValue($bot));
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