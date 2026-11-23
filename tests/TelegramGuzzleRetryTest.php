<?php

declare(strict_types=1);

namespace App\Tests;

use App\Telegram\TelegramGuzzleRetry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class TelegramGuzzleRetryTest extends TestCase
{
    public function testConnectExceptionOnFirstAttemptIsRetriedSuccessfully(): void
    {
        $request = new Request('POST', 'https://api.telegram.org/bot123:abc/sendMessage');
        $mock = new MockHandler([
            new ConnectException('cURL error 28: Resolving timed out after 5001 milliseconds', $request),
            new Response(200, [], '{"ok":true}'),
        ]);
        $stack = HandlerStack::create($mock);
        TelegramGuzzleRetry::applyRetryMiddleware($stack);
        $client = new Client(['handler' => $stack]);

        $response = $client->send($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $mock->count());
    }

    public function testThreeConnectExceptionsThrow(): void
    {
        $request = new Request('POST', 'https://api.telegram.org/bot123:abc/getMe');
        $mock = new MockHandler([
            new ConnectException('Connection refused', $request),
            new ConnectException('Connection refused', $request),
            new ConnectException('Connection refused', $request),
        ]);
        $stack = HandlerStack::create($mock);
        TelegramGuzzleRetry::applyRetryMiddleware($stack);
        $client = new Client(['handler' => $stack]);

        $this->expectException(ConnectException::class);
        $client->send($request);
    }

    public function testBadRequestIsNotRetried(): void
    {
        $request = new Request('POST', 'https://api.telegram.org/bot123:abc/sendMessage');
        $mock = new MockHandler([
            new Response(400, [], '{"ok":false,"description":"Bad Request"}'),
        ]);
        $stack = HandlerStack::create($mock);
        TelegramGuzzleRetry::applyRetryMiddleware($stack);
        $client = new Client(['handler' => $stack, 'http_errors' => false]);

        $response = $client->send($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $mock->count());
    }

    public function testGatewayErrorIsRetried(): void
    {
        $request = new Request('POST', 'https://api.telegram.org/bot123:abc/sendMessage');
        $mock = new MockHandler([
            new Response(502, [], 'Bad Gateway'),
            new Response(200, [], '{"ok":true}'),
        ]);
        $stack = HandlerStack::create($mock);
        TelegramGuzzleRetry::applyRetryMiddleware($stack);
        $client = new Client(['handler' => $stack]);

        $response = $client->send($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $mock->count());
    }

    public function testReadTimeoutIsNotRetried(): void
    {
        self::assertFalse(TelegramGuzzleRetry::decider(
            0,
            new Request('POST', 'https://api.telegram.org/bot1:x/sendMessage'),
            null,
            new RequestException(
                'cURL error 28: Operation timed out after 5000 milliseconds',
                new Request('POST', 'test'),
            ),
        ));
    }

    public function testLogLineOmitsToken(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'tg-retry-log-');
        self::assertNotFalse($logFile);
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            TelegramGuzzleRetry::decider(
                0,
                new Request('POST', 'https://api.telegram.org/bot999888:Zz_top_secret/sendMessage'),
                null,
                new ConnectException('DNS fail', new Request('POST', 'https://api.telegram.org/bot999888:Zz_top_secret/sendMessage')),
            );
        } finally {
            ini_set('error_log', $previous !== false ? $previous : '');
        }

        $content = (string) file_get_contents($logFile);
        unlink($logFile);

        self::assertStringContainsString('[tg-retry] attempt 1 for sendMessage:', $content);
        self::assertStringNotContainsString('Zz_top_secret', $content);
        self::assertStringNotContainsString('999888', $content);
    }

    public function testNutgramFactoryClientOptionsIncludeRetryHandler(): void
    {
        $options = TelegramGuzzleRetry::clientOptions();
        self::assertSame(5.0, $options['connect_timeout']);
        self::assertInstanceOf(HandlerStack::class, $options['handler']);
    }
}