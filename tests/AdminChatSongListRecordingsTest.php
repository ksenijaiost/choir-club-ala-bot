<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\MaterialType;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Domain\VoicePart;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserRepository;
use App\Services\AdminChatSongListPresenter;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class AdminChatSongListRecordingsTest extends TestCase
{
    private const ADMIN_TG = 92001;

    public function testTuttiRecordingLabel(): void
    {
        $deps = $this->presenter();
        $texts = $deps['texts'];
        $songId = $deps['songs']->create('Sweet dreams');
        $deps['materials']->insertRecording(
            $songId,
            VoicePart::CODES,
            'r1',
            'ru1',
            $deps['uploaderId'],
            null,
        );

        $body = implode("\n", $deps['presenter']->buildMessageChunks());

        self::assertStringContainsString(
            $texts->get('admin_chat.songs_block_recordings', ['detail' => 'тутти']),
            $body,
        );
        self::assertStringNotContainsString('<b>⚠️ Sweet dreams</b>', $body);
        self::assertStringNotContainsString('Нет записей', $body);
    }

    public function testMultipleRecordingsListedSeparately(): void
    {
        $deps = $this->presenter();
        $texts = $deps['texts'];
        $songId = $deps['songs']->create('Voce Chegou');

        $deps['materials']->insertRecording($songId, ['soprano_1', 'soprano_2'], 'r1', 'u1', $deps['uploaderId'], null);
        $deps['materials']->insertRecording($songId, ['alto_1'], 'r2', 'u2', $deps['uploaderId'], null);
        $deps['materials']->insertRecording($songId, ['tenor'], 'r3', 'u3', $deps['uploaderId'], null);

        $items = implode('; ', [
            'Сопрано 1 + Сопрано 2',
            'Альт 1',
            'Тенор',
        ]);
        $expected = $texts->get('admin_chat.songs_block_recordings', ['detail' => $items]);
        $body = implode("\n", $deps['presenter']->buildMessageChunks());

        self::assertStringContainsString($expected, $body);
    }

    public function testTuttiSortsBeforeOtherRecordings(): void
    {
        $deps = $this->presenter();
        $texts = $deps['texts'];
        $songId = $deps['songs']->create('Порядок');

        $deps['materials']->insertRecording($songId, ['bass'], 'r1', 'u1', $deps['uploaderId'], null);
        $deps['materials']->insertRecording($songId, VoicePart::CODES, 'r2', 'u2', $deps['uploaderId'], null);
        $deps['materials']->insertRecording($songId, ['soprano_1'], 'r3', 'u3', $deps['uploaderId'], null);

        $items = implode('; ', ['тутти', 'Сопрано 1', 'Бас']);
        $expected = $texts->get('admin_chat.songs_block_recordings', ['detail' => $items]);
        $body = implode("\n", $deps['presenter']->buildMessageChunks());

        self::assertStringContainsString($expected, $body);
    }

    public function testNoRecordingsKeepsPlaceholder(): void
    {
        $deps = $this->presenter();
        $texts = $deps['texts'];
        $deps['songs']->create('Пустая');

        $body = implode("\n", $deps['presenter']->buildMessageChunks());

        self::assertStringContainsString(
            $texts->get('admin_chat.songs_block_recordings', ['detail' => $texts->get('admin_chat.word_no')]),
            $body,
        );
    }

    /**
     * @return array{texts: TextRepository, songs: SongRepository, materials: MaterialRepository, presenter: AdminChatSongListPresenter, uploaderId: int}
     */
    private function presenter(): array
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(self::ADMIN_TG, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $songs = new SongRepository($pdo);
        $materials = new MaterialRepository($pdo);

        return [
            'texts' => $texts,
            'songs' => $songs,
            'materials' => $materials,
            'presenter' => new AdminChatSongListPresenter($songs, $materials, $texts),
            'uploaderId' => $uploaderId,
        ];
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
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
}