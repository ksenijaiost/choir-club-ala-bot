<?php

declare(strict_types=1);

namespace App\Tests;

use App\Tests\Support\AchievementServiceFactory;
use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\HandlerRegistry;
use App\Handlers\PaymentAdminHandler;
use App\Handlers\PaymentAdminMonthHandler;
use App\Handlers\PaymentCallbackHandler;
use App\Handlers\PaymentDeps;
use App\Handlers\PaymentMemberHandler;
use App\Repositories\BotSettingsRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\NotificationRecipientResolver;
use App\Services\PaymentAdminMonthPresenter;
use App\Services\PaymentPeriod;
use App\Services\PaymentReminderTextService;
use App\Services\PaymentTexts;
use App\Telegram\InteractiveMenuService;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class PaymentAdminMonthTest extends TestCase
{
    private const CONDUCTOR_TG = 96_100;

    public function testAdminMonthChoicesOrder(): void
    {
        putenv('FAKE_NOW=2026-05-15T12:00:00+05:00');
        $local = new \DateTimeImmutable('2026-05-15 12:00:00', new \DateTimeZone('Asia/Almaty'));
        $choices = PaymentPeriod::adminMonthChoices($local);
        self::assertCount(4, $choices);
        self::assertSame(['year' => 2026, 'month' => 6], $choices[0]);
        self::assertSame(['year' => 2026, 'month' => 5], $choices[1]);
        self::assertSame(['year' => 2026, 'month' => 4], $choices[2]);
        self::assertSame(['year' => 2026, 'month' => 3], $choices[3]);
        putenv('FAKE_NOW');
    }

    public function testMemberClaimWindow(): void
    {
        putenv('FAKE_NOW=2026-05-15T12:00:00+05:00');
        $local = new \DateTimeImmutable('2026-05-15 12:00:00', new \DateTimeZone('Asia/Almaty'));
        $window = PaymentPeriod::memberClaimWindow($local);
        self::assertSame(['year' => 2026, 'month' => 4], $window[0]);
        self::assertSame(['year' => 2026, 'month' => 5], $window[1]);
        self::assertSame(['year' => 2026, 'month' => 6], $window[2]);
        self::assertTrue(PaymentPeriod::isInMemberClaimWindow($local, 2026, 4));
        self::assertFalse(PaymentPeriod::isInMemberClaimWindow($local, 2026, 3));
        putenv('FAKE_NOW');
    }

    public function testHomeKeyboardListsFourMonths(): void
    {
        putenv('FAKE_NOW=2026-05-15T12:00:00+05:00');
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $deps = $this->paymentDeps($this->testConfig(), $this->pdo(), $texts);
        $keyboard = PaymentAdminHandler::buildHomeKeyboard($deps);
        $payload = json_encode($keyboard, JSON_THROW_ON_ERROR);
        self::assertIsString($payload);
        self::assertSame(4, substr_count($payload, 'pay:adm:'));
        putenv('FAKE_NOW');
    }

    public function testMonthScreenListsAllBuckets(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $uAwait = $users->create(96101, 'Ждёт', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $uPaid = $users->create(96102, 'Оплатил', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $uDef = $users->create(96103, 'Позже', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);
        $uPlain = $users->create(96104, 'Пусто', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дир');
        $conductor = $users->findByTelegramId(self::CONDUCTOR_TG);
        self::assertNotNull($conductor);

        $payments = new PaymentRecordRepository($pdo);
        $payments->claimByMember($uAwait, 2026, 5);
        $payments->confirmByConductor($uPaid, 2026, 5, (int) $conductor['id']);
        $payments->deferByMember($uDef, 2026, 5, 'на неделе');

        $deps = $this->paymentDeps($this->testConfig(), $pdo, $texts);
        $buckets = PaymentAdminMonthPresenter::classify($deps, 2026, 5);
        $text = PaymentAdminMonthPresenter::buildMonthScreenText($texts, $deps->paymentTexts, 2026, 5, $buckets);

        self::assertStringContainsString('Ждёт', $text);
        self::assertStringContainsString('Оплатил', $text);
        self::assertStringContainsString('Позже', $text);
        self::assertStringContainsString('на неделе', $text);
        self::assertStringContainsString('Пусто', $text);
        self::assertLessThanOrEqual(4000, mb_strlen($text));

        $emptyBuckets = [
            'awaiting' => [],
            'paid' => [],
            'unpaid' => [],
        ];
        $emptyText = PaymentAdminMonthPresenter::buildMonthScreenText($texts, $deps->paymentTexts, 2026, 5, $emptyBuckets);
        self::assertSame(3, substr_count($emptyText, $texts->get('payment.admin_month_empty_line')));
    }

    public function testMoveToMonthSuccessAndConflict(): void
    {
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $uid = $users->create(96110, 'Перенос', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $payments = new PaymentRecordRepository($pdo);
        $payments->deferByMember($uid, 2026, 4, 'коммент');

        self::assertTrue($payments->moveToMonth($uid, 2026, 4, 2026, 5));
        self::assertNull($payments->find($uid, 2026, 4));
        $moved = $payments->find($uid, 2026, 5);
        self::assertNotNull($moved);
        self::assertTrue($payments->isMemberDeferred($moved));
        self::assertSame('коммент', $moved['member_comment']);

        $payments->claimByMember($uid, 2026, 6);
        self::assertFalse($payments->moveToMonth($uid, 2026, 5, 2026, 6));
    }

    public function testMemberPayOkRejectsOutsideWindow(): void
    {
        putenv('FAKE_NOW=2026-05-15T12:00:00+05:00');
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $tid = 96120;
        $users->create($tid, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $deps = $this->paymentDeps($this->testConfig(), $pdo, $texts);
        $bot = Nutgram::fake();

        PaymentCallbackHandler::handle($bot, $deps, new AccessControl($this->testConfig()), 'pay:ok:2026:3', $tid);
        $member = $users->findByTelegramId($tid);
        self::assertNotNull($member);
        self::assertNull($deps->payments->find((int) $member['id'], 2026, 3));

        PaymentCallbackHandler::handle($bot, $deps, new AccessControl($this->testConfig()), 'pay:ok:2026:6', $tid);
        self::assertTrue($deps->payments->hasMemberClaim($deps->payments->find((int) $member['id'], 2026, 6)));
        putenv('FAKE_NOW');
    }

    public function testMemberScreenShowsNextMonthButton(): void
    {
        putenv('FAKE_NOW=2026-05-15T12:00:00+05:00');
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $tid = 96121;
        $users->create($tid, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $deps = $this->paymentDeps($this->testConfig(), $pdo, $texts);
        $member = $users->findByTelegramId($tid);
        self::assertNotNull($member);

        $bot = Nutgram::fake();
        PaymentMemberHandler::openHome($bot, $deps, $member);

        $bot->assertRaw(static function (\GuzzleHttp\Psr7\Request $request): bool {
            $body = (string) $request->getBody();

            return str_contains($body, 'pay:ok:2026:4')
                && str_contains($body, 'pay:ok:2026:5')
                && str_contains($body, 'pay:ok:2026:6')
                && str_contains($body, 'pay:later:2026:5');
        });
        putenv('FAKE_NOW');
    }

    public function testConfirmByNameViaCallback(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        $conductor = $users->findByTelegramId(self::CONDUCTOR_TG);
        $uid = $users->create(96130, 'Аня', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $payments = new PaymentRecordRepository($pdo);
        $payments->claimByMember($uid, 2026, 5);

        $deps = $this->paymentDeps($this->testConfig(), $pdo, $texts);
        $bot = $this->fakeBotWithMessage(self::CONDUCTOR_TG);
        PaymentAdminMonthHandler::handleCallback(
            $bot,
            $deps,
            new AccessControl($this->testConfig()),
            $conductor,
            'pay:am:cx:' . $uid . ':2026:5:0',
        );

        self::assertTrue($payments->isConductorConfirmed($payments->find($uid, 2026, 5)));
    }

    public function testAdminMarkAndUnmarkSilent(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        $conductor = $users->findByTelegramId(self::CONDUCTOR_TG);
        $uid = $users->create(96131, 'Борис', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);

        $payments = new PaymentRecordRepository($pdo);
        $deps = $this->paymentDeps($this->testConfig(), $pdo, $texts);
        $bot = $this->fakeBotWithMessage(self::CONDUCTOR_TG);

        PaymentAdminMonthHandler::handleCallback(
            $bot,
            $deps,
            new AccessControl($this->testConfig()),
            $conductor,
            'pay:am:mk:' . $uid . ':2026:5',
        );
        self::assertTrue($payments->isConductorConfirmed($payments->find($uid, 2026, 5)));

        PaymentAdminMonthHandler::handleCallback(
            $bot,
            $deps,
            new AccessControl($this->testConfig()),
            $conductor,
            'pay:am:ux:' . $uid . ':2026:5',
        );
        self::assertFalse($payments->isConductorConfirmed($payments->find($uid, 2026, 5)));
    }

    public function testPayAmCallbacksRoutedViaHandlerRegistry(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');

        $bot = $this->fakePrivateBot(self::CONDUCTOR_TG);
        HandlerRegistry::register($bot, $texts, $this->testConfig(), $pdo);

        $bot->hearCallbackQueryData('pay:adm:2026:5')
            ->willStartConversation()
            ->reply()
            ->assertCalled('answerCallbackQuery')
            ->assertCalled('editMessageText');

        $bot->hearCallbackQueryData('pay:am:cf:2026:5:0')
            ->willStartConversation()
            ->reply()
            ->assertCalled('answerCallbackQuery')
            ->assertCalled('editMessageText');
    }

    public function testCallbackDataLengthWithinLimit(): void
    {
        $samples = [
            'pay:am:mx:999999:2026:12:2027:1',
            'pay:am:cx:999999:2026:12:99',
            'pay:adm:2026:12',
        ];
        foreach ($samples as $data) {
            self::assertLessThanOrEqual(64, strlen($data), $data);
        }
    }

    private function fakeBotWithMessage(int $telegramId): Nutgram
    {
        $bot = Nutgram::fake();
        $user = new User();
        $user->id = $telegramId;
        $user->first_name = 'Дирижёр';
        $bot->setCommonUser($user);

        $message = new Message();
        $message->message_id = 1;
        $message->chat = new Chat();
        $message->chat->id = $telegramId;
        $message->reply_markup = InlineKeyboardMarkup::make();

        $bot->willReceivePartial([
            'callback_query' => [
                'id' => '1',
                'from' => ['id' => $telegramId, 'first_name' => 'Дирижёр'],
                'data' => 'pay:am:cf:2026:5:0',
                'message' => $message,
            ],
        ]);

        return $bot;
    }

    private function fakePrivateBot(int $telegramId): Nutgram
    {
        $bot = Nutgram::fake();
        $user = new User();
        $user->id = $telegramId;
        $user->is_bot = false;
        $user->first_name = 'Тест';
        $bot->setCommonUser($user);

        $chat = new Chat();
        $chat->id = $telegramId;
        $chat->type = ChatType::PRIVATE;
        $bot->setCommonChat($chat);

        return $bot;
    }

    private function pdo(): \PDO
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        return $pdo;
    }

    private function paymentDeps(Config $config, \PDO $pdo, TextRepository $texts): PaymentDeps
    {
        $settings = new BotSettingsRepository($pdo);
        $userRepo = new UserRepository($pdo);

        return new PaymentDeps(
            $config,
            $userRepo,
            new PaymentRecordRepository($pdo),
            new PaymentTexts($texts),
            new NotificationRecipientResolver($config, $userRepo),
            $texts,
            $settings,
            new UserFlowRepository($pdo),
            new PaymentReminderTextService($settings, $texts, new PaymentTexts($texts)),
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
            AchievementServiceFactory::make($config, $pdo, $texts)
        );
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: (string) self::CONDUCTOR_TG,
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
            fakeNow: getenv('FAKE_NOW') !== false ? (string) getenv('FAKE_NOW') : null,
        );
    }
}