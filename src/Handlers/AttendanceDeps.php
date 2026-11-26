<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\CheckInSessionRepository;
use App\Repositories\DayBeforeReminderDeliveryRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\RehearsalRsvpRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceConductorNotifier;
use App\Services\AttendanceHistoryPresenter;
use App\Services\AttendanceMessageFactory;
use App\Services\AttendanceService;
use App\Services\AchievementService;
use App\Services\AttendanceStreakService;
use App\Services\ConsecutiveUnexcusedRecalculator;
use App\Services\PartBattleService;
use App\Services\RehearsalRsvpNudgeService;
use App\Services\RehearsalRsvpStatusPresenter;
use App\Services\RehearsalRsvpStatusService;
use App\Telegram\InteractiveMenuService;
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
        public readonly CheckInSessionRepository $checkInSessions,
        public readonly InteractiveMenuService $interactiveMenus,
        public readonly RehearsalRsvpRepository $rsvp,
        public readonly DayBeforeReminderDeliveryRepository $dayBeforeDeliveries,
        public readonly RehearsalRsvpStatusService $rsvpStatus,
        public readonly RehearsalRsvpStatusPresenter $rsvpPresenter,
        public readonly RehearsalRsvpNudgeService $rsvpNudge,
        public readonly AttendanceStreakService $streak,
        public readonly AchievementService $achievements,
        public readonly PartBattleService $partBattle,
    ) {
    }
}
