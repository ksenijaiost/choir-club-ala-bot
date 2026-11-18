<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Domain\VoicePart;
use App\Handlers\MaterialsCallbackHandler;
use App\Handlers\MaterialsDeps;
use App\Handlers\MaterialsFlowHandler;
use App\Handlers\MaterialsRecordingEditHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\SongRecordingAvailability;
use App\Tests\Support\MaterialsTestDeps;
use App\Telegram\InteractiveMenuService;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class RecordingEditTest extends TestCase
{
    private const UPLOADER_TG = 90111;
    private const MEMBER_TG = 90112;

    public function testAvailabilityLinesOrderAndTuttiSeparate(): void
    {
        $deps = $this->deps();
        $texts = $deps['texts'];
        $songId = $deps['songs']->create('Порядок');

        $availability = new SongRecordingAvailability($deps['materials'], $texts);
        $emptyHave = $availability->formatHaveLine($songId);
        self::assertStringContainsString($texts->get('materials.recordings_have_line', ['parts' => '—']), $emptyHave);

        $deps['materials']->insertRecording($songId, ['bass'], 'r1', 'u1', $deps['uploaderId'], null);
        $deps['materials']->insertRecording($songId, ['soprano_1'], 'r2', 'u2', $deps['uploaderId'], null);
        $deps['materials']->insertRecording($songId, VoicePart::CODES, 'r3', 'u3', $deps['uploaderId'], null);

        $haveLine = $availability->formatHaveLine($songId);
        $missingLine = $availability->formatMissingLine($songId);

        self::assertSame(
            $texts->get('materials.recordings_have_line', [
                'parts' => implode(', ', [
                    $texts->get('parts.soprano_1'),
                    $texts->get('parts.bass'),
                    $texts->get('admin_chat.recording_tutti'),
                ]),
            ]),
            $haveLine,
        );

        $expectedMissing = [];
        foreach (VoicePart::CODES as $code) {
            if (in_array($code, ['soprano_1', 'bass'], true)) {
                continue;
            }
            $expectedMissing[] = $texts->get('parts.' . $code);
        }

        self::assertSame(
            $texts->get('materials.recordings_missing_line', ['parts' => implode(', ', $expectedMissing)]),
            $missingLine,
        );
    }

    public function testUploadWizardShowsExistingRecordingsHint(): void
    {
        $deps = $this->deps();
        $texts = $deps['texts'];
        $songId = $deps['songs']->create('Подсказка');
        $deps['materials']->insertRecording($songId, ['alto_1'], 'r1', 'u1', $deps['uploaderId'], null);

        $deps['flows']->set(self::UPLOADER_TG, 'material_upload', [
            'step' => 'part',
            'song_id' => $songId,
            'type' => 'recording',
        ]);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->telegramUser(self::UPLOADER_TG));
        MaterialsFlowHandler::sendRecordingPartPicker($bot, $deps['handlerDeps'], self::UPLOADER_TG);

        $payload = $this->lastSendMessageText($bot);
        $hint = (new SongRecordingAvailability($deps['materials'], $texts))->formatUploadHint($songId);
        self::assertStringContainsString($hint, $payload);
    }

    public function testUploadWizardShowsNoRecordingsHintWhenEmpty(): void
    {
        $deps = $this->deps();
        $texts = $deps['texts'];
        $songId = $deps['songs']->create('Пусто');

        $deps['flows']->set(self::UPLOADER_TG, 'material_upload', [
            'step' => 'part',
            'song_id' => $songId,
            'type' => 'recording',
        ]);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->telegramUser(self::UPLOADER_TG));
        MaterialsFlowHandler::sendRecordingPartPicker($bot, $deps['handlerDeps'], self::UPLOADER_TG);

        $payload = $this->lastSendMessageText($bot);
        self::assertStringContainsString($texts->get('materials.recordings_upload_none'), $payload);
    }

    public function testPartChangePersists(): void
    {
        $deps = $this->deps();
        $songId = $deps['songs']->create('Смена');
        $materialId = $deps['materials']->insertRecording($songId, ['tenor'], 'r1', 'u1', $deps['uploaderId'], null);

        $deps['materials']->setVoiceParts($materialId, ['baritone']);
        $parts = $deps['materials']->listVoiceParts($materialId);
        self::assertSame(['baritone'], $parts);
    }

    public function testMemberCannotOpenRecordingEdit(): void
    {
        $deps = $this->deps();
        $songId = $deps['songs']->create('Закрыто');
        $bot = Nutgram::fake();
        $bot->setCommonUser($this->telegramUser(self::MEMBER_TG));

        $member = $deps['users']->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $handled = MaterialsCallbackHandler::handle(
            $bot,
            $deps['handlerDeps'],
            $deps['access'],
            $member,
            'mat:mg:erec:' . $songId,
        );

        self::assertTrue($handled);
        self::assertSame(0, $this->countMethodCalls($bot, 'sendMessage'));
    }

    public function testApplyPartChoiceUpdatesEditorText(): void
    {
        $deps = $this->deps();
        $texts = $deps['texts'];
        $songId = $deps['songs']->create('Редактор');
        $materialId = $deps['materials']->insertRecording($songId, ['tenor'], 'r1', 'u1', $deps['uploaderId'], null);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->telegramUser(self::UPLOADER_TG));

        $deps['flows']->set(self::UPLOADER_TG, MaterialsRecordingEditHandler::FLOW, [
            'song_id' => $songId,
            'material_id' => $materialId,
            'wizard_chat_id' => self::UPLOADER_TG,
            'wizard_message_id' => 42,
        ]);

        MaterialsRecordingEditHandler::applyPartChoice(
            $bot,
            $deps['handlerDeps'],
            self::UPLOADER_TG,
            $materialId,
            'baritone',
        );

        self::assertSame(['baritone'], $deps['materials']->listVoiceParts($materialId));
        $bot->assertCalled('editMessageText');
    }

    /**
     * @return array<string, mixed>
     */
    private function deps(): array
    {
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(self::UPLOADER_TG, 'Uploader', 'tenor', UserRole::OWNER, UserStatus::ACTIVE);
        $users->create(self::MEMBER_TG, 'Member', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $materials = new MaterialRepository($pdo);
        $songs = new SongRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $menus = new InteractiveMenuService(new UserOpenMenuRepository($pdo));
        $handlerDeps = MaterialsTestDeps::build(
            $config,
            $users,
            $flows,
            $songs,
            $materials,
            $texts,
            $menus,
        );

        return [
            'users' => $users,
            'uploaderId' => $uploaderId,
            'materials' => $materials,
            'songs' => $songs,
            'flows' => $flows,
            'texts' => $texts,
            'handlerDeps' => $handlerDeps,
            'access' => new AccessControl($config),
        ];
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: (string) self::UPLOADER_TG,
            conductorTelegramId: '',
            adminChatId: '-1001',
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

    private function telegramUser(int $telegramId): User
    {
        $user = new User();
        $user->id = $telegramId;

        return $user;
    }

    private function lastSendMessageText(Nutgram $bot): string
    {
        $body = $this->lastBodyForMethod($bot, 'sendMessage');

        return (string) ($body['text'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function lastBodyForMethod(Nutgram $bot, string $method): array
    {
        foreach (array_reverse($bot->getRequestHistory()) as $entry) {
            if (str_contains($entry['request']->getUri()->getPath(), $method)) {
                return json_decode((string) $entry['request']->getBody(), true) ?: [];
            }
        }

        self::fail('Expected request for ' . $method);

        return [];
    }

    private function countMethodCalls(Nutgram $bot, string $method): int
    {
        $count = 0;
        foreach ($bot->getRequestHistory() as $entry) {
            if (str_contains((string) $entry['request']->getUri(), $method)) {
                $count++;
            }
        }

        return $count;
    }
}