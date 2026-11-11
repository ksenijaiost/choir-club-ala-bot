<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceConductorNotifier;
use App\Services\AttendanceHistoryPresenter;
use App\Services\AttendanceMessageFactory;
use App\Services\AttendanceService;
use App\Services\ConsecutiveUnexcusedRecalculator;
use App\Text\TextRepository;

final class AttendanceDeps
{
    public function __construct(
        public readonly Config $config,
        public readonly UserRepository $users,
        public readonly RehearsalRepository $rehearsals,
        public readonly AttendanceMarkRepository $marks,
        public readonly UserFlowRepository $flows,
        public readonly AttendanceService $attendance,
        public readonly AttendanceMessageFactory $messages,
        public readonly AttendanceConductorNotifier $conductor,
        public readonly ConsecutiveUnexcusedRecalculator $recalculator,
        public readonly AttendanceHistoryPresenter $history,
        public readonly TextRepository $texts,
    ) {
    }
}
