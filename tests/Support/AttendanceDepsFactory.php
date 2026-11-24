<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config;
use App\Handlers\AttendanceDeps;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\BlockEventRepository;
use App\Repositories\CheckInSessionRepository;
use App\Repositories\DayBeforeReminderDeliveryRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\RehearsalRsvpNudgeRepository;
use App\Repositories\RehearsalRsvpRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceConductorNotifier;
use App\Services\AttendanceDiscipline;
use App\Services\AttendanceHistoryPresenter;
use App\Services\AttendanceMessageFactory;
use App\Services\AttendanceService;
use App\Services\ConsecutiveUnexcusedRecalculator;
use App\Services\MemberBlockService;
use App\Services\NotificationRecipientResolver;
use App\Services\RehearsalRsvpNudgeService;
use App\Services\RehearsalRsvpStatusPresenter;
use App\Services\RehearsalRsvpStatusService;
use App\Telegram\InteractiveMenuService;
use App\Text\TextRepository;

final class AttendanceDepsFactory
{
    public static function make(
        Config $config,
        \PDO $pdo,
        TextRepository $texts,
        UserRepository $users,
        ?RecordingTelegramOutbound $outbound = null,
    ): AttendanceDeps {
        $rehearsals = new RehearsalRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $marks = new AttendanceMarkRepository($pdo);
        $outbound ??= new RecordingTelegramOutbound();
        $recipients = new NotificationRecipientResolver($config, $users);
        $blocks = new MemberBlockService($users, new BlockEventRepository($pdo));
        $recalculator = new ConsecutiveUnexcusedRecalculator($marks, $users);
        $discipline = new AttendanceDiscipline($users, $texts, $recipients, $outbound, $blocks);
        $service = new AttendanceService($marks, $users, $discipline, $recalculator);
        $messages = new AttendanceMessageFactory($texts);
        $rsvp = new RehearsalRsvpRepository($pdo);
        $dayBefore = new DayBeforeReminderDeliveryRepository($pdo);
        $nudgeRepo = new RehearsalRsvpNudgeRepository($pdo);

        return new AttendanceDeps(
            $config,
            $users,
            $rehearsals,
            $marks,
            $flows,
            $service,
            $messages,
            new AttendanceConductorNotifier($recipients, $texts, $outbound),
            $recalculator,
            new AttendanceHistoryPresenter($texts),
            $texts,
            new CheckInSessionRepository($pdo),
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
            $rsvp,
            $dayBefore,
            new RehearsalRsvpStatusService($rsvp, $dayBefore, $marks),
            new RehearsalRsvpStatusPresenter($texts),
            new RehearsalRsvpNudgeService($nudgeRepo, $rsvp, $dayBefore, $rehearsals, $users, $messages, $outbound),
        );
    }
}