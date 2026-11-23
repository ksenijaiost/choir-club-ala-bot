<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\BroadcastRecipientHandler;
use App\Handlers\BroadcastWizardHandler;
use App\Repositories\BroadcastRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\BroadcastDeliveryService;
use App\Services\BroadcastKeyboardBuilder;
use App\Services\BroadcastStatusPresenter;
use App\Services\UserRolePresenter;
use App\Telegram\InteractiveMenuService;
use App\Telegram\TelegramMessageChunker;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Tests\Support\ThrowingRecordingTelegramOutbound;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class BroadcastTest extends TestCase
{
    private const CONDUCTOR_TG = 88001;

    private const MEMBER_TG = 88002;

    private const MEMBER2_TG = 88003;

    private const BLOCKED_TG = 1008205296;

    public function testAccessControl(): void
    {
        $access = new AccessControl($this->testConfig());
        $member = ['status' => UserStatus::ACTIVE, 'role' => UserRole::MEMBER];
        self::assertFalse($access->canManageBroadcasts($member));
        self::assertTrue($access->canManageBroadcasts([
            'status' => UserStatus::ACTIVE,
            'role' => UserRole::CONDUCTOR,
        ]));
    }

    public function testWizardRejectsTooManyOptions(): void
    {
        $deps = $this->buildDeps();
        $deps['flows']->set(self::CONDUCTOR_TG, BroadcastWizardHandler::FLOW, [
            'step' => 'options',
            'kind' => BroadcastRepository::KIND_POLL,
            'text' => 'Вопрос?',
            'options' => array_fill(0, BroadcastRepository::MAX_OPTIONS, 'вариант'),
            'multi' => false,
            'allow_custom' => false,
        ]);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::CONDUCTOR_TG));
        $handled = BroadcastWizardHandler::handleFlowMessage(
            $bot,
            $deps['handlerDeps'],
            $deps['access'],
            self::CONDUCTOR_TG,
            'ещё один',
        );
        self::assertTrue($handled);
        $state = $deps['flows']->get(self::CONDUCTOR_TG);
        self::assertSame(
            BroadcastRepository::MAX_OPTIONS,
            count($state['context']['options'] ?? []),
        );
    }

    public function testDeliverySkipsBlockedRecipient(): void
    {
        $outbound = new ThrowingRecordingTelegramOutbound(self::BLOCKED_TG);
        $deps = $this->buildDeps($outbound);
        $deps['users']->create(self::BLOCKED_TG, 'Blocked', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $author = $deps['users']->findByTelegramId(self::CONDUCTOR_TG);
        self::assertNotNull($author);
        $id = $deps['broadcasts']->createText((int) $author['id'], 'Тест доставки');
        $stats = $deps['delivery']->deliverToParticipants($id, $author);

        self::assertGreaterThanOrEqual(2, $stats['sent']);
        self::assertSame(1, $stats['failed']);
        $chatIds = array_column($outbound->recordedMessages(), 'chat_id');
        self::assertNotContains(self::BLOCKED_TG, $chatIds);
    }

    public function testMarkRead(): void
    {
        $deps = $this->buildDeps();
        $author = $deps['users']->findByTelegramId(self::CONDUCTOR_TG);
        $member = $deps['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($author);
        self::assertNotNull($member);
        $id = $deps['broadcasts']->createText((int) $author['id'], 'Прочитай');
        $deps['broadcasts']->addRecipient($id, (int) $member['id']);
        $deps['broadcasts']->markRead($id, (int) $member['id']);
        $rows = $deps['broadcasts']->listRecipients($id);
        self::assertNotNull($rows[0]['read_at'] ?? null);
    }

    public function testPollSingleChoiceReplacesPrevious(): void
    {
        $deps = $this->buildDeps();
        $author = $deps['users']->findByTelegramId(self::CONDUCTOR_TG);
        $member = $deps['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($author);
        self::assertNotNull($member);
        $id = $deps['broadcasts']->createPoll((int) $author['id'], 'Выбор?', ['A', 'B'], false, false);
        $options = $deps['broadcasts']->listOptions($id);
        $deps['broadcasts']->setAnswerOption($id, (int) $member['id'], (int) $options[0]['id'], false);
        $deps['broadcasts']->setAnswerOption($id, (int) $member['id'], (int) $options[1]['id'], false);
        $selected = $deps['broadcasts']->selectedOptionIds($id, (int) $member['id']);
        self::assertSame([(int) $options[1]['id']], $selected);
    }

    public function testPollMultiToggle(): void
    {
        $deps = $this->buildDeps();
        $author = $deps['users']->findByTelegramId(self::CONDUCTOR_TG);
        $member = $deps['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($author);
        self::assertNotNull($member);
        $id = $deps['broadcasts']->createPoll((int) $author['id'], 'Мульти?', ['A', 'B'], true, false);
        $options = $deps['broadcasts']->listOptions($id);
        $optA = (int) $options[0]['id'];
        $deps['broadcasts']->setAnswerOption($id, (int) $member['id'], $optA, true);
        $deps['broadcasts']->setAnswerOption($id, (int) $member['id'], $optA, true);
        self::assertSame([], $deps['broadcasts']->selectedOptionIds($id, (int) $member['id']));
    }

    public function testCustomAnswerFlow(): void
    {
        $deps = $this->buildDeps();
        $author = $deps['users']->findByTelegramId(self::CONDUCTOR_TG);
        $member = $deps['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($author);
        self::assertNotNull($member);
        $id = $deps['broadcasts']->createPoll((int) $author['id'], 'Свой?', ['A', 'B'], false, true);
        $deps['flows']->set(self::MEMBER_TG, BroadcastRecipientHandler::FLOW_CUSTOM, ['broadcast_id' => $id]);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->user(self::MEMBER_TG));
        self::assertTrue(BroadcastRecipientHandler::handleFlowMessage(
            $bot,
            $deps['handlerDeps'],
            self::MEMBER_TG,
            'Мой ответ',
        ));
        self::assertSame('Мой ответ', $deps['broadcasts']->findCustomAnswer($id, (int) $member['id']));
    }

    public function testStatusDisambiguatesDuplicateNames(): void
    {
        $deps = $this->buildDeps();
        $author = $deps['users']->findByTelegramId(self::CONDUCTOR_TG);
        self::assertNotNull($author);
        $deps['users']->create(self::MEMBER2_TG, 'Анна', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $m1 = $deps['users']->findByTelegramId(self::MEMBER_TG);
        $m2 = $deps['users']->findByTelegramId(self::MEMBER2_TG);
        self::assertNotNull($m1);
        self::assertNotNull($m2);
        $deps['users']->updateTelegramUsername((int) $m1['id'], 'anna_one');
        $deps['users']->updateTelegramUsername((int) $m2['id'], 'anna_two');
        $deps['users']->updateDisplayName((int) $m1['id'], 'Анна');
        $deps['users']->updateDisplayName((int) $m2['id'], 'Анна');

        $id = $deps['broadcasts']->createText((int) $author['id'], 'Тест имён');
        $deps['broadcasts']->addRecipient($id, (int) $m1['id']);
        $deps['broadcasts']->addRecipient($id, (int) $m2['id']);
        $deps['broadcasts']->markRead($id, (int) $m1['id']);

        $chunks = $deps['status']->statusChunks($id);
        $joined = implode("\n", $chunks);
        self::assertStringContainsString('@anna_one', $joined);
        self::assertStringContainsString('@anna_two', $joined);
    }

    public function testLongStatusSplitsAtFourThousand(): void
    {
        $blocks = [];
        for ($i = 0; $i < 200; $i++) {
            $blocks[] = '• ' . str_repeat('Имя', 20) . ' ' . $i;
        }
        $chunks = TelegramMessageChunker::splitByBlocks($blocks, BroadcastStatusPresenter::CHUNK_LIMIT);
        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(BroadcastStatusPresenter::CHUNK_LIMIT, mb_strlen($chunk));
        }

        $deps = $this->buildDeps();
        $author = $deps['users']->findByTelegramId(self::CONDUCTOR_TG);
        self::assertNotNull($author);
        $id = $deps['broadcasts']->createText((int) $author['id'], 'Статус');
        $chunks = $deps['status']->statusChunks($id);
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(BroadcastStatusPresenter::CHUNK_LIMIT, mb_strlen($chunk));
        }
    }

    public function testCloseBroadcast(): void
    {
        $deps = $this->buildDeps();
        $author = $deps['users']->findByTelegramId(self::CONDUCTOR_TG);
        self::assertNotNull($author);
        $id = $deps['broadcasts']->createPoll((int) $author['id'], 'Закрыть?', ['Да', 'Нет'], false, false);
        $deps['broadcasts']->close($id);
        $row = $deps['broadcasts']->find($id);
        self::assertNotNull($row);
        self::assertSame(BroadcastRepository::STATUS_CLOSED, $row['status']);
    }

    public function testCallbackDataLengthWithinLimit(): void
    {
        $samples = [
            'bc:rd:999999999',
            'bc:pv:999999999:999999999',
            'bc:cs:999999999',
            'bc:cx:999999999',
            'bc:st:999999999',
            'bc:rf:999999999',
            'bc:cl:999999999',
            'bc:cy:999999999',
            'bc:cn:999999999',
            'bc:w:kt:t',
            'bc:w:op:done',
        ];
        foreach ($samples as $data) {
            self::assertLessThanOrEqual(64, strlen($data), $data);
        }
    }

    public function testReadButtonInKeyboard(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $builder = new BroadcastKeyboardBuilder($texts);
        $keyboard = $builder->buildTextKeyboard(42, false);
        $data = (string) ($keyboard->inline_keyboard[0][0]->callback_data ?? '');
        self::assertSame('bc:rd:42', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDeps(?TelegramOutbound $outbound = null): array
    {
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $users = new UserRepository($pdo);
        $users->create(self::CONDUCTOR_TG, 'Дирижёр', null, UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $users->create(self::MEMBER_TG, 'Анна', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $broadcasts = new BroadcastRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $rolePresenter = new UserRolePresenter($texts);
        $keyboards = new BroadcastKeyboardBuilder($texts);
        $outbound ??= new RecordingTelegramOutbound();
        $delivery = new BroadcastDeliveryService($users, $broadcasts, $texts, $rolePresenter, $keyboards, $outbound);
        $status = new BroadcastStatusPresenter($texts, $broadcasts, $users);
        $interactiveMenus = new InteractiveMenuService(new UserOpenMenuRepository($pdo));
        $handlerDeps = new \App\Handlers\BroadcastDeps(
            $config,
            $users,
            $broadcasts,
            $flows,
            $texts,
            $interactiveMenus,
            $delivery,
            $status,
            $keyboards,
            $rolePresenter,
            $outbound,
        );

        return [
            'users' => $users,
            'broadcasts' => $broadcasts,
            'flows' => $flows,
            'delivery' => $delivery,
            'status' => $status,
            'handlerDeps' => $handlerDeps,
            'access' => new AccessControl($config),
        ];
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
            fakeNow: null,
        );
    }

    private function user(int $telegramId): User
    {
        $user = new User();
        $user->id = $telegramId;
        $user->is_bot = false;
        $user->first_name = 'Test';

        return $user;
    }
}