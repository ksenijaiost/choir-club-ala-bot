<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\PaymentAdminMemberHandler;
use App\Handlers\PaymentDeps;
use App\Repositories\BotSettingsRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\NotificationRecipientResolver;
use App\Services\PaymentPeriod;
use App\Services\PaymentReminderTextService;
use App\Services\PaymentTexts;
use App\Telegram\InteractiveMenuService;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class PaymentAdminMemberTest extends TestCase
{
    public function testUnconfirmByConductorClearsOnlyConductorFields(): void
    {
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $uid = $users->create(9001, 'Тест', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->upsertConductor(9099, 'Дир');
        $conductor = $users->findByTelegramId(9099);
        self::assertNotNull($conductor);
        $payments = new PaymentRecordRepository($pdo);
        $payments->deferByMember($uid, 2026, 3, 'позже');
        $payments->claimByMember($uid, 2026, 3);
        $payments->confirmByConductor($uid, 2026, 3, (int) $conductor['id']);

        $payments->unconfirmByConductor($uid, 2026, 3);
        $row = $payments->find($uid, 2026, 3);
        self::assertNotNull($row);
        self::assertFalse($payments->isConductorConfirmed($row));
        self::assertTrue($payments->hasMemberClaim($row));
        self::assertSame('позже', $row['member_comment']);
    }

    public function testBulkPaidThroughWithPriorPayment(): void
    {
        putenv('FAKE_NOW=2026-06-15T12:00:00+05:00');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $uid = $users->create(9010, 'Диапазон', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $payments = new PaymentRecordRepository($pdo);
        $payments->confirmByConductor($uid, 2026, 2, 1);

        $end = ['year' => 2026, 'month' => 5];
        $start = PaymentPeriod::nextMonth(2026, 2);
        foreach (PaymentPeriod::monthsInclusive($start, $end) as $ym) {
            $payments->confirmByConductor($uid, $ym['year'], $ym['month'], 1);
        }

        self::assertTrue($payments->isConductorConfirmed($payments->find($uid, 2026, 2)));
        self::assertTrue($payments->isConductorConfirmed($payments->find($uid, 2026, 3)));
        self::assertTrue($payments->isConductorConfirmed($payments->find($uid, 2026, 4)));
        self::assertTrue($payments->isConductorConfirmed($payments->find($uid, 2026, 5)));
        self::assertFalse($payments->isConductorConfirmed($payments->find($uid, 2026, 6)));

        putenv('FAKE_NOW');
    }

    public function testBulkPaidThroughWithoutPriorPayment(): void
    {
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $uid = $users->create(9011, 'Один', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);
        $payments = new PaymentRecordRepository($pdo);
        $payments->confirmByConductor($uid, 2026, 4, 1);
        self::assertNull($payments->find($uid, 2026, 3));
        self::assertTrue($payments->isConductorConfirmed($payments->find($uid, 2026, 4)));
    }

    public function testBulkDoesNotReconfirmExisting(): void
    {
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $uid = $users->create(9012, 'Нет дубля', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->upsertConductor(9013, 'Дир2');
        $c1 = $users->findByTelegramId(9013);
        self::assertNotNull($c1);
        $payments = new PaymentRecordRepository($pdo);
        $payments->confirmByConductor($uid, 2026, 1, (int) $c1['id']);
        $before = $payments->find($uid, 2026, 1);
        self::assertNotNull($before);

        $payments->confirmByConductor($uid, 2026, 2, (int) $c1['id']);
        $after = $payments->find($uid, 2026, 1);
        self::assertSame((string) $before['conductor_confirmed_at'], (string) $after['conductor_confirmed_at']);
        self::assertSame((int) $before['confirmed_by_user_id'], (int) $after['confirmed_by_user_id']);
    }

    public function testMemberCannotUseAdminMemberCallbacks(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $tid = 9201;
        $users->create($tid, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId($tid);
        self::assertNotNull($member);

        $deps = $this->paymentDeps($config, $pdo, $texts, $users);
        $bot = Nutgram::fake();
        $handled = PaymentAdminMemberHandler::handleCallback(
            $bot,
            $deps,
            new AccessControl($config),
            $member,
            'pa:p:0',
        );

        self::assertTrue($handled);
    }

    public function testMemberPickerButtonWithoutPaymentsShowsNameOnly(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $paymentTexts = new PaymentTexts($texts);

        $labelNoPaid = $texts->get('payment.admin_member_picker_button_name_only', [
            'name' => 'Без оплат',
        ]);
        self::assertSame('Без оплат', $labelNoPaid);
        self::assertStringNotContainsString(' — ', $labelNoPaid);

        $ph = $paymentTexts->monthYearPlaceholders(2026, 3);
        $labelPaid = $texts->get('payment.admin_member_picker_button', [
            'name' => 'С оплатой',
            'month' => $ph['month'],
            'year' => $ph['year'],
        ]);
        self::assertSame('С оплатой — март 2026', $labelPaid);
    }

    public function testPickerPaginationSlicesTenMembers(): void
    {
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        for ($i = 0; $i < 11; $i++) {
            $users->create(9300 + $i, 'Плательщик ' . $i, 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        }

        $all = $users->listActivePayers();
        self::assertCount(11, $all);
        self::assertCount(10, array_slice($all, 0, 10));
        self::assertCount(1, array_slice($all, 10, 10));
    }

    public function testMonthWindowDoesNotExceedCurrentMonth(): void
    {
        putenv('FAKE_NOW=2026-05-12T12:00:00+05:00');
        $local = new \DateTimeImmutable('2026-05-12 12:00:00', new \DateTimeZone('Asia/Almaty'));
        $months = PaymentPeriod::monthWindowEndingAt($local, 0);
        self::assertCount(12, $months);
        $last = $months[11];
        self::assertSame(2026, $last['year']);
        self::assertSame(5, $last['month']);

        $older = PaymentPeriod::monthWindowEndingAt($local, 12);
        self::assertSame(2025, $older[11]['year']);
        self::assertSame(5, $older[11]['month']);
        putenv('FAKE_NOW');
    }

    public function testCallbackDataLengthWithinLimit(): void
    {
        $data = 'pa:by:999999:2026:12:120';
        self::assertLessThanOrEqual(64, strlen($data));
    }

    public function testBulkConfirmViaCallback(): void
    {
        putenv('FAKE_NOW=2026-06-01T12:00:00+05:00');
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->upsertConductor(9400, 'Дирижёр');
        $conductor = $users->findByTelegramId(9400);
        $mid = $users->create(9401, 'Аня', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $payments = new PaymentRecordRepository($pdo);
        $payments->confirmByConductor($mid, 2026, 2, (int) $conductor['id']);

        $deps = $this->paymentDeps($config, $pdo, $texts, $users);
        $bot = $this->fakeBotWithMessage();
        PaymentAdminMemberHandler::handleCallback(
            $bot,
            $deps,
            new AccessControl($config),
            $conductor,
            'pa:by:' . $mid . ':2026:5:0',
        );

        self::assertTrue($payments->isConductorConfirmed($payments->find($mid, 2026, 3)));
        self::assertTrue($payments->isConductorConfirmed($payments->find($mid, 2026, 4)));
        self::assertTrue($payments->isConductorConfirmed($payments->find($mid, 2026, 5)));
        self::assertFalse($payments->isConductorConfirmed($payments->find($mid, 2026, 6)));
        putenv('FAKE_NOW');
    }

    public function testConductorUnconfirmViaCallback(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->upsertConductor(9400, 'Дирижёр');
        $conductor = $users->findByTelegramId(9400);
        $mid = $users->create(9401, 'Аня', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $payments = new PaymentRecordRepository($pdo);
        $payments->confirmByConductor($mid, 2026, 1, (int) $conductor['id']);

        $deps = $this->paymentDeps($config, $pdo, $texts, $users);
        $bot = $this->fakeBotWithMessage();
        PaymentAdminMemberHandler::handleCallback(
            $bot,
            $deps,
            new AccessControl($config),
            $conductor,
            'pa:tu:' . $mid . ':2026:1:0',
        );

        self::assertFalse($payments->isConductorConfirmed($payments->find($mid, 2026, 1)));
    }

    private function fakeBotWithMessage(): Nutgram
    {
        $bot = Nutgram::fake();
        $user = new User();
        $user->id = 9400;
        $user->first_name = 'Дирижёр';
        $bot->setCommonUser($user);

        $message = new Message();
        $message->message_id = 1;
        $message->chat = new \SergiX44\Nutgram\Telegram\Types\Chat\Chat();
        $message->chat->id = 9400;
        $message->reply_markup = InlineKeyboardMarkup::make();

        $bot->willReceivePartial([
            'callback_query' => [
                'id' => '1',
                'from' => ['id' => 9400, 'first_name' => 'Дирижёр'],
                'data' => 'pa:p:0',
                'message' => $message,
            ],
        ]);

        return $bot;
    }

    private function pdo(): \PDO
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        return $pdo;
    }

    private function paymentDeps(Config $config, \PDO $pdo, TextRepository $texts, UserRepository $users): PaymentDeps
    {
        $settings = new BotSettingsRepository($pdo);

        return new PaymentDeps(
            $config,
            $users,
            new PaymentRecordRepository($pdo),
            new PaymentTexts($texts),
            new NotificationRecipientResolver($config, $users),
            $texts,
            $settings,
            new UserFlowRepository($pdo),
            new PaymentReminderTextService($settings, $texts, new PaymentTexts($texts)),
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '9399',
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