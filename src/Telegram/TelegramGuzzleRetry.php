<?php

declare(strict_types=1);

namespace App\Telegram;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TelegramGuzzleRetry
{
    public const MAX_ATTEMPTS = 3;

    private const CONNECT_TIMEOUT_SECONDS = 5.0;

    /**
     * @return array<string, mixed>
     */
    public static function clientOptions(): array
    {
        $stack = HandlerStack::create();
        self::applyRetryMiddleware($stack);

        return [
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'handler' => $stack,
        ];
    }

    public static function applyRetryMiddleware(HandlerStack $stack): void
    {
        $stack->push(
            Middleware::retry(
                self::decider(...),
                self::delayMilliseconds(...),
            ),
            'tg_retry',
        );
    }

    public static function decider(
        int $retries,
        RequestInterface $request,
        ?ResponseInterface $response = null,
        ?\Throwable $exception = null,
    ): bool {
        if ($retries >= self::MAX_ATTEMPTS - 1) {
            return false;
        }

        if (!self::isRetryable($response, $exception)) {
            return false;
        }

        self::logRetry($retries + 1, $request, $response, $exception);

        return true;
    }

    public static function delayMilliseconds(int $retries, ?ResponseInterface $response, RequestInterface $request): int
    {
        unset($response, $request);

        return match ($retries) {
            1 => 500,
            default => 1000,
        };
    }

    public static function isRetryable(?ResponseInterface $response, ?\Throwable $exception): bool
    {
        if ($exception instanceof ConnectException) {
            return true;
        }

        if ($response !== null) {
            $status = $response->getStatusCode();
            if (in_array($status, [502, 503, 504], true)) {
                return true;
            }

            if ($status >= 400 && $status < 500) {
                return false;
            }
        }

        if ($exception instanceof RequestException) {
            if (self::isReadTimeout($exception)) {
                return false;
            }

            return self::isTransientRequestError($exception);
        }

        return false;
    }

    public static function isReadTimeout(RequestException $exception): bool
    {
        $message = $exception->getMessage();
        if (!str_contains($message, 'Operation timed out') && !str_contains($message, 'cURL error 28')) {
            return false;
        }

        return str_contains($message, 'Operation timed out')
            && !str_contains($message, 'Resolving timed out')
            && !str_contains($message, 'Connection timed out');
    }

    private static function isTransientRequestError(RequestException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Resolving timed out')
            || str_contains($message, 'Connection timed out')
            || str_contains($message, 'Connection refused')
            || str_contains($message, 'Connection reset');
    }

    private static function logRetry(
        int $attempt,
        RequestInterface $request,
        ?ResponseInterface $response,
        ?\Throwable $exception,
    ): void {
        $method = self::apiMethod($request);
        $reason = self::sanitize($exception?->getMessage() ?? '');
        if ($reason === '' && $response !== null) {
            $reason = 'HTTP ' . $response->getStatusCode();
        }

        error_log('[tg-retry] attempt ' . $attempt . ' for ' . $method . ': ' . $reason);
    }

    public static function apiMethod(RequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        if (preg_match('#/bot[^/]+/([^/]+)#', $path, $matches) === 1) {
            return $matches[1];
        }

        return 'request';
    }

    public static function sanitize(string $text): string
    {
        $text = preg_replace('#https?://api\.telegram\.org/bot\d+:[^/\s]+#', 'https://api.telegram.org/bot', $text) ?? $text;
        $text = preg_replace('#/bot\d+:[^/\s]+#', '/bot', $text) ?? $text;

        return $text;
    }
}