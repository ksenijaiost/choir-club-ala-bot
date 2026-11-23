<?php

declare(strict_types=1);

namespace App\Services;

use App\Handlers\PaymentDeps;
use App\Repositories\PaymentRecordRepository;
use App\Telegram\TelegramMessageChunker;
use App\Text\TextRepository;

final class PaymentAdminMonthPresenter
{
    public const CHUNK_LIMIT = 4000;

    private const PAGE_SIZE = 10;

    /**
     * @return array{
     *   awaiting: list<array{user_id: int, name: string}>,
     *   paid: list<array{user_id: int, name: string}>,
     *   unpaid: list<array{user_id: int, name: string, line: string}>
     * }
     */
    public static function classify(PaymentDeps $deps, int $year, int $month): array
    {
        $awaiting = [];
        $paid = [];
        $unpaid = [];

        foreach ($deps->users->listActivePayers() as $user) {
            $userId = (int) $user['id'];
            $name = (string) ($user['display_name'] ?? '');
            $row = $deps->payments->find($userId, $year, $month);

            if ($deps->payments->isConductorConfirmed($row)) {
                $paid[] = ['user_id' => $userId, 'name' => $name];

                continue;
            }

            if ($deps->payments->hasMemberClaim($row)) {
                $awaiting[] = ['user_id' => $userId, 'name' => $name];

                continue;
            }

            $line = self::unpaidLine($deps->texts, $deps->payments, $name, $row);
            $unpaid[] = ['user_id' => $userId, 'name' => $name, 'line' => $line];
        }

        return [
            'awaiting' => $awaiting,
            'paid' => $paid,
            'unpaid' => $unpaid,
        ];
    }

    /**
     * @param array{
     *   awaiting: list<array{user_id: int, name: string}>,
     *   paid: list<array{user_id: int, name: string}>,
     *   unpaid: list<array{user_id: int, name: string, line: string}>
     * } $buckets
     */
    public static function buildMonthScreenText(
        TextRepository $texts,
        PaymentTexts $paymentTexts,
        int $year,
        int $month,
        array $buckets,
    ): string {
        $ph = $paymentTexts->monthYearPlaceholders($year, $month);
        $lines = [$texts->get('payment.admin_month_screen_title', $ph), ''];

        $lines[] = $texts->get('payment.admin_month_awaiting_header', ['count' => count($buckets['awaiting'])]);
        $lines[] = self::nameBlock($texts, array_column($buckets['awaiting'], 'name'));
        $lines[] = '';

        $lines[] = $texts->get('payment.admin_month_paid_header', ['count' => count($buckets['paid'])]);
        $lines[] = self::nameBlock($texts, array_column($buckets['paid'], 'name'));
        $lines[] = '';

        $lines[] = $texts->get('payment.admin_month_unpaid_header', ['count' => count($buckets['unpaid'])]);
        $unpaidLines = array_map(static fn (array $row): string => $row['line'], $buckets['unpaid']);
        $lines[] = self::lineBlock($texts, $unpaidLines);

        $full = implode("\n", $lines);
        $chunks = TelegramMessageChunker::splitByLines($full, self::CHUNK_LIMIT);

        return $chunks[0] ?? '';
    }

    /**
     * @param list<string> $names
     */
    private static function nameBlock(TextRepository $texts, array $names): string
    {
        if ($names === []) {
            return $texts->get('payment.admin_month_empty_line');
        }

        return implode("\n", $names);
    }

    /**
     * @param list<string> $lines
     */
    private static function lineBlock(TextRepository $texts, array $lines): string
    {
        if ($lines === []) {
            return $texts->get('payment.admin_month_empty_line');
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function unpaidLine(
        TextRepository $texts,
        PaymentRecordRepository $payments,
        string $name,
        ?array $row,
    ): string {
        if ($payments->isMemberDeferred($row)) {
            $comment = trim((string) ($row['member_comment'] ?? ''));
            if ($comment !== '') {
                return $texts->get('payment.admin_month_unpaid_deferred', [
                    'name' => $name,
                    'comment' => $comment,
                ]);
            }

            return $texts->get('payment.admin_month_unpaid_deferred_no_comment', ['name' => $name]);
        }

        return $name;
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public static function pageSlice(array $items, int $page): array
    {
        $offset = $page * self::PAGE_SIZE;

        return array_slice($items, $offset, self::PAGE_SIZE);
    }

    public static function pageSize(): int
    {
        return self::PAGE_SIZE;
    }
}