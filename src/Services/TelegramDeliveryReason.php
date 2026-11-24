<?php

declare(strict_types=1);

namespace App\Services;

use App\Text\TextRepository;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

final class TelegramDeliveryReason
{
    public const BLOCKED = 'blocked';

    public const NEVER_STARTED = 'never_started';

    public const DEACTIVATED = 'deactivated';

    public const VOICE_FORBIDDEN = 'voice_forbidden';

    public const OTHER = 'other';

    private const KNOWN_CODES = [
        self::BLOCKED,
        self::NEVER_STARTED,
        self::DEACTIVATED,
        self::VOICE_FORBIDDEN,
        self::OTHER,
    ];

    public static function codeFromThrowable(\Throwable $e): string
    {
        return self::codeFromMessage($e->getMessage(), $e instanceof TelegramException ? $e->getCode() : 0);
    }

    public static function codeFromRaw(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return self::OTHER;
        }

        $trimmed = trim($raw);
        if (in_array($trimmed, self::KNOWN_CODES, true)) {
            return $trimmed;
        }

        return self::codeFromMessage($trimmed, 0);
    }

    public static function label(TextRepository $texts, ?string $raw): string
    {
        $code = self::codeFromRaw($raw);

        return $texts->get('delivery.reason_' . $code);
    }

    /**
     * @param list<array{name: string, reason_label: string}> $failures
     */
    public static function formatSummary(TextRepository $texts, int $sent, int $total, array $failures): string
    {
        $lines = [
            $texts->get('delivery.summary_header', [
                'sent' => (string) $sent,
                'total' => (string) $total,
            ]),
        ];

        foreach ($failures as $row) {
            $lines[] = $texts->get('delivery.failure_line', [
                'name' => (string) ($row['name'] ?? ''),
                'reason' => (string) ($row['reason_label'] ?? ''),
            ]);
        }

        return implode("\n", $lines);
    }

    private static function codeFromMessage(string $message, int $code): string
    {
        $lower = mb_strtolower($message);

        if (str_contains($lower, 'voice_messages_forbidden')) {
            return self::VOICE_FORBIDDEN;
        }

        if (str_contains($lower, 'bot was blocked') || str_contains($lower, 'bot blocked')) {
            return self::BLOCKED;
        }

        if (str_contains($lower, 'user is deactivated') || str_contains($lower, 'deactivated')) {
            return self::DEACTIVATED;
        }

        if (str_contains($lower, "can't initiate conversation")
            || str_contains($lower, 'chat not found')
            || str_contains($lower, 'peer_id_invalid')
            || str_contains($lower, 'user not found')
        ) {
            return self::NEVER_STARTED;
        }

        if ($code === 403 && !str_contains($lower, 'voice')) {
            return self::BLOCKED;
        }

        return self::OTHER;
    }
}