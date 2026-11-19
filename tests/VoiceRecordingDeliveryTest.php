<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\MaterialsDeps;
use App\Handlers\SongsCallbackHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\MaterialsService;
use App\Services\VoiceRecordingDeliveryReporter;
use App\Tests\Support\MaterialsTestDeps;
use App\Text\TextRepository;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class VoiceRecordingDeliveryTest extends TestCase
{
    private const MEMBER_TG = 94201;

    public function testVoiceForbiddenSendsOtherRecordingsAndSingleWarning(): void
    {
        $deps = $this->deps();
        $admin = $this->seedAdmin($deps);

        $idA = $deps->songs->create('Альфа');
        $idB = $deps->songs->create('Бета');
        $materials = $deps->materials;
        $id1 = $materials->insertRecording($idA, ['soprano_1'], 'voice1', 'u1', (int) $admin['id'], null);
        $materials->insertRecording($idB, ['soprano_1'], 'file2', 'u2', (int) $admin['id'], null);
        $materials->setSourceMessageUrl($id1, 'https://t.me/c/1234567890/42');

        $bot = Nutgram::fake(responses: [
            $this->telegramOkResponse(),
            $this->telegramErrorResponse('Bad Request: VOICE_MESSAGES_FORBIDDEN'),
            $this->telegramAudioOkResponse(),
            $this->telegramMessageOkResponse(),
        ]);
        $bot->setCommonUser($this->user(self::MEMBER_TG));

        SongsCallbackHandler::handle($bot, $deps, $deps->delivery, 'snd:my:all:soprano_1');

        $audioCalls = 0;
        foreach ($bot->getRequestHistory() as $entry) {
            if (str_contains($entry['request']->getUri()->getPath(), 'sendAudio')) {
                $audioCalls++;
            }
        }
        self::assertSame(2, $audioCalls);

        $warningBodies = [];
        foreach ($bot->getRequestHistory() as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'sendMessage')) {
                continue;
            }
            $body = json_decode((string) $entry['request']->getBody(), true) ?: [];
            $text = (string) ($body['text'] ?? '');
            if (str_contains($text, '⚠️')) {
                $warningBodies[] = $text;
            }
        }
        self::assertCount(1, $warningBodies);
        $warning = $warningBodies[0];
        self::assertStringContainsString('1', $warning);
        self::assertStringContainsString('Альфа', $warning);
        self::assertStringContainsString('https://t.me/c/1234567890/42', $warning);
    }

    public function testVoiceForbiddenNotLoggedAsExceptionWarning(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'choir-telegram-log-');
        self::assertNotFalse($logFile);
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            \App\Telegram\TelegramApiErrors::logTelegramIssue(
                'sendAudio',
                new \SergiX44\Nutgram\Telegram\Exceptions\TelegramException('Bad Request: VOICE_MESSAGES_FORBIDDEN', 400),
            );
        } finally {
            ini_set('error_log', $previous !== false ? $previous : '');
        }

        $content = (string) file_get_contents($logFile);
        unlink($logFile);

        self::assertStringContainsString('voice messages forbidden', $content);
        self::assertStringNotContainsString('sendAudio warning:', $content);
    }

    public function testReporterBuildsWarningWithoutLinksWhenNoneSet(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $service = new MaterialsService(
            new MaterialRepository(Connection::make($this->testConfig())),
            new SongRepository(Connection::make($this->testConfig())),
            $texts,
        );
        $reporter = new VoiceRecordingDeliveryReporter();
        $reporter->track('Песня', [
            'voice_parts' => ['tenor'],
            'source_message_url' => null,
        ], $service);

        $bot = Nutgram::fake(responses: [$this->telegramMessageOkResponse()]);
        $reporter->flush($bot, $texts, self::MEMBER_TG);

        $bot->assertCalled('sendMessage', 1);
        $body = json_decode((string) $bot->getRequestHistory()[0]['request']->getBody(), true);
        $text = (string) ($body['text'] ?? '');
        self::assertStringContainsString('1', $text);
        self::assertStringNotContainsString('https://t.me/', $text);
    }

  /**
     * @return MaterialsDeps
     */
    private function deps(): MaterialsDeps
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);

        return MaterialsTestDeps::build(
            $this->testConfig(),
            $users,
            new UserFlowRepository($pdo),
            new SongRepository($pdo),
            new MaterialRepository($pdo),
            $texts,
            new \App\Telegram\InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function seedAdmin(MaterialsDeps $deps): array
    {
        $id = $deps->users->create(94200, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $admin = $deps->users->findById($id);
        self::assertNotNull($admin);

        return $admin;
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->id = $id;
        $user->first_name = 'T';

        return $user;
    }

    private function telegramErrorResponse(string $description): Response
    {
        return new Response(400, [], json_encode([
            'ok' => false,
            'error_code' => 400,
            'description' => $description,
        ], JSON_THROW_ON_ERROR));
    }

    private function telegramOkResponse(): Response
    {
        return new Response(200, [], json_encode(['ok' => true, 'result' => true], JSON_THROW_ON_ERROR));
    }

    private function telegramAudioOkResponse(): Response
    {
        return new Response(200, [], json_encode([
            'ok' => true,
            'result' => [
                'message_id' => 2,
                'date' => time(),
                'chat' => ['id' => self::MEMBER_TG, 'type' => 'private'],
                'audio' => [
                    'file_id' => 'aud',
                    'file_unique_id' => 'u',
                    'duration' => 1,
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function telegramMessageOkResponse(): Response
    {
        return new Response(200, [], json_encode([
            'ok' => true,
            'result' => [
                'message_id' => 3,
                'date' => time(),
                'chat' => ['id' => self::MEMBER_TG, 'type' => 'private'],
                'text' => 'ok',
            ],
        ], JSON_THROW_ON_ERROR));
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