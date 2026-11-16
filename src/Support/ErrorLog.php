<?php

declare(strict_types=1);

namespace App\Support;

use App\Bootstrap;
use App\Config;
use SergiX44\Nutgram\Nutgram;

final class ErrorLog
{
    private const MAX_TRACE_LINES = 20;

    public static function log(Config $config, \Throwable $e, array $context = []): void
    {
        try {
            $dir = Bootstrap::basePath() . '/storage/logs';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }

            $tz = new \DateTimeZone($config->timezone);
            $day = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
            $path = $dir . '/bot-' . $day . '.log';

            $line = self::formatLine($config, $e, $context, $tz);
            @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // never break the request path
        }
    }

    public static function pathForDate(string $date): string
    {
        return Bootstrap::basePath() . '/storage/logs/bot-' . $date . '.log';
    }

    /**
     * @return list<string>
     */
    public static function tailLines(string $path, int $maxLines): array
    {
        if (!is_file($path) || $maxLines < 1) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }

        if (count($lines) <= $maxLines) {
            return $lines;
        }

        return array_slice($lines, -$maxLines);
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function contextFromNutgram(Nutgram $bot): array
    {
        $update = $bot->update();
        if ($update === null) {
            return [];
        }

        $ctx = [
            'update_id' => $update->update_id ?? null,
        ];

        if ($update->message !== null) {
            $ctx['update_type'] = 'message';
            $ctx['chat_id'] = $update->message->chat?->id;
            $ctx['user_id'] = $update->message->from?->id;
        } elseif ($update->callback_query !== null) {
            $ctx['update_type'] = 'callback_query';
            $ctx['chat_id'] = $update->callback_query->message?->chat?->id;
            $ctx['user_id'] = $update->callback_query->from?->id;
        } elseif ($update->edited_message !== null) {
            $ctx['update_type'] = 'edited_message';
            $ctx['chat_id'] = $update->edited_message->chat?->id;
            $ctx['user_id'] = $update->edited_message->from?->id;
        } elseif ($update->channel_post !== null) {
            $ctx['update_type'] = 'channel_post';
            $ctx['chat_id'] = $update->channel_post->chat?->id;
            $ctx['user_id'] = null;
        } else {
            $ctx['update_type'] = 'other';
        }

        return $ctx;
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function contextFromWebhookInput(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($data)) {
            return [];
        }

        $ctx = ['update_id' => $data['update_id'] ?? null];

        if (isset($data['message']) && is_array($data['message'])) {
            $ctx['update_type'] = 'message';
            $ctx['chat_id'] = $data['message']['chat']['id'] ?? null;
            $ctx['user_id'] = $data['message']['from']['id'] ?? null;
        } elseif (isset($data['callback_query']) && is_array($data['callback_query'])) {
            $ctx['update_type'] = 'callback_query';
            $ctx['chat_id'] = $data['callback_query']['message']['chat']['id'] ?? null;
            $ctx['user_id'] = $data['callback_query']['from']['id'] ?? null;
        }

        return $ctx;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function formatLine(
        Config $config,
        \Throwable $e,
        array $context,
        \DateTimeZone $tz,
    ): string {
        $ts = (new \DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
        $message = self::redact($config, $e->getMessage());
        $file = $e->getFile() . ':' . $e->getLine();
        $trace = self::redact($config, self::shortTrace($e));

        $parts = [
            "[{$ts}]",
            $e::class,
            $message,
            $file,
        ];

        foreach (['source', 'update_id', 'update_type', 'chat_id', 'user_id'] as $key) {
            if (array_key_exists($key, $context) && $context[$key] !== null && $context[$key] !== '') {
                $parts[] = $key . '=' . $context[$key];
            }
        }

        $head = implode(' | ', $parts);

        return $head . "\n" . $trace . "\n---\n";
    }

    private static function shortTrace(\Throwable $e): string
    {
        $trace = explode("\n", $e->getTraceAsString());
        $trace = array_slice($trace, 0, self::MAX_TRACE_LINES);

        return implode("\n", $trace);
    }

    public static function redact(Config $config, string $text): string
    {
        $secrets = array_filter([
            $config->botToken,
            $config->webhookSecret,
            $config->adminPagesKey,
            $config->dbPassword,
        ], static fn (string $v): bool => trim($v) !== '');

        foreach ($secrets as $secret) {
            $text = str_replace($secret, '[скрыто]', $text);
        }

        $text = (string) preg_replace('/\d{8,}:[A-Za-z0-9_-]{20,}/', '[скрыто]', $text);

        return $text;
    }
}