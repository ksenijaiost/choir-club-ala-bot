<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceHistoryPresenter;
use App\Services\PaymentTexts;
use App\Services\UserRolePresenter;
use App\Text\TextRepository;

final class AdminHistoryContext
{
    public function __construct(
        public readonly Config $config,
        public readonly UserRepository $users,
        public readonly AttendanceMarkRepository $marks,
        public readonly PaymentRecordRepository $payments,
        public readonly TextRepository $texts,
        public readonly AttendanceHistoryPresenter $history,
        public readonly PaymentTexts $paymentTexts,
        public readonly UserRolePresenter $roles,
    ) {
    }
}
