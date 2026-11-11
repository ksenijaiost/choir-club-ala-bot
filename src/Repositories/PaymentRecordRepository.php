<?php

declare(strict_types=1);

namespace App\Repositories;

final class PaymentRecordRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $userId, int $year, int $month): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM payment_records WHERE user_id = ? AND period_year = ? AND period_month = ? LIMIT 1'
        );
        $stmt->execute([$userId, $year, $month]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function claimByMember(int $userId, int $year, int $month): void
    {
        $existing = $this->find($userId, $year, $month);
        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE payment_records SET member_claimed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND period_year = ? AND period_month = ?'
            );
            $stmt->execute([$userId, $year, $month]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO payment_records (user_id, period_year, period_month, member_claimed_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([$userId, $year, $month]);
    }

    public function confirmByConductor(int $userId, int $year, int $month, int $conductorUserId): void
    {
        $existing = $this->find($userId, $year, $month);
        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE payment_records SET conductor_confirmed_at = CURRENT_TIMESTAMP, confirmed_by_user_id = ?
                 WHERE user_id = ? AND period_year = ? AND period_month = ?'
            );
            $stmt->execute([$conductorUserId, $userId, $year, $month]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO payment_records (user_id, period_year, period_month, conductor_confirmed_at, confirmed_by_user_id)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)'
        );
        $stmt->execute([$userId, $year, $month, $conductorUserId]);
    }

    public function isConductorConfirmed(?array $row): bool
    {
        if ($row === null) {
            return false;
        }

        $at = $row['conductor_confirmed_at'] ?? null;

        return $at !== null && $at !== '';
    }

    public function hasMemberClaim(?array $row): bool
    {
        if ($row === null) {
            return false;
        }

        $at = $row['member_claimed_at'] ?? null;

        return $at !== null && $at !== '';
    }
}
