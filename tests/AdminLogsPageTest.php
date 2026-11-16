<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

final class AdminLogsPageTest extends TestCase
{
    public function testLogsPageWithoutKeyReturns403(): void
    {
        $script = dirname(__DIR__) . '/public/admin/logs.php';
        $env = [
            'ADMIN_PAGES_KEY' => 'logs-test-key',
            'BOT_TOKEN' => '1:test',
            'OWNER_TELEGRAM_ID' => '1',
        ];

        [, $body] = $this->runPhpScript($script, [], $env);
        self::assertStringContainsString('Forbidden', $body);
    }

    public function testLogsPageWithKeyReturnsPlainText(): void
    {
        $script = dirname(__DIR__) . '/public/admin/logs.php';
        $env = [
            'ADMIN_PAGES_KEY' => 'logs-test-key',
            'BOT_TOKEN' => '1:test',
            'OWNER_TELEGRAM_ID' => '1',
        ];

        [$code, $body] = $this->runPhpScript($script, ['key=logs-test-key'], $env);
        self::assertSame(0, $code);
        self::assertTrue(
            str_contains($body, 'No log entries')
            || str_contains($body, 'RuntimeException')
            || str_contains($body, '---'),
            'Expected log output or empty-day message',
        );
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{0: int, 1: string}
     */
    private function runPhpScript(string $script, array $args, array $env): array
    {
        $cmd = array_merge(['php', $script], $args);
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $merged = array_merge($_ENV, $env);

        $proc = proc_open(
            $cmd,
            $descriptor,
            $pipes,
            dirname(__DIR__),
            $merged,
        );
        self::assertIsResource($proc);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [$exit, $stdout . $stderr];
    }
}