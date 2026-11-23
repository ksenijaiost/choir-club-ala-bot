<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PaymentRecordRepository;
use App\Text\TextRepository;

final class PaymentStatusFormatter
{
    /**
     * @param array<string, mixed>|null $row
     */
    public static function forRecord(
        TextRepository $texts,
        PaymentRecordRepository $payments,
        ?array $row,
    ): string {
        if ($payments->isConductorConfirmed($row)) {
            return $texts->get('payment.status_confirmed');
        }

        if ($payments->hasMemberClaim($row)) {
            return $texts->get('payment.status_awaiting');
        }

        if ($payments->isMemberDeferred($row)) {
            $comment = trim((string) ($row['member_comment'] ?? ''));
            if ($comment !== '') {
                return $texts->get('payment.status_deferred_comment', ['comment' => $comment]);
            }

            return $texts->get('payment.status_deferred');
        }

        return $texts->get('payment.status_unpaid');
    }

    /**
     * @param array<string, mixed>|null $row
     */
    public static function emojiForRecord(PaymentRecordRepository $payments, ?array $row): string
    {
        if ($payments->isConductorConfirmed($row)) {
            return '✅';
        }

        if ($payments->hasMemberClaim($row)) {
            return '⏳';
        }

        if ($payments->isMemberDeferred($row)) {
            return '🕓';
        }

        return '❌';
    }
}