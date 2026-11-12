<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\BotSettingsRepository;
use App\Repositories\CheckInPromptRepository;
use App\Repositories\CheckInSessionRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\ScheduleTemplateRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceDiscipline;
use App\Services\AttendanceMessageFactory;
use App\Services\AttendanceService;
use App\Services\ConsecutiveUnexcusedRecalculator;
use App\Services\MemberBlockService;
use App\Services\NotificationRecipientResolver;
use App\Services\PaymentTexts;
use App\Repositories\BlockEventRepository;
use App\Services\ScheduleGenerator;
use App\Telegram\NutgramOutbound;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class CronJobRunner
{
    public function run(Config $config, \PDO $pdo, ?Nutgram $bot = null, ?TextRepository $texts = null): array
    {
        $now = Clock::now($config);
        $texts ??= TextRepository::fromFile(dirname(__DIR__, 2) . '/texts/ru.json');

        $rehearsals = new RehearsalRepository($pdo);
        $users = new UserRepository($pdo);
        $deliveries = new JobDeliveryRepository($pdo);

        $generator = new ScheduleGenerator(
            new ScheduleTemplateRepository($pdo),
            $rehearsals,
            new BotSettingsRepository($pdo),
        );

        $scheduleJob = new ScheduleGenerateJob($generator, $deliveries);
        $schedule = $scheduleJob->runIfDue($config);

        $result = [
            'status' => 'ok',
            'now' => $now->format(DATE_ATOM),
            'timezone' => $config->timezone,
            'fake_now' => $config->fakeNow !== null,
            'schedule_generate' => $schedule,
            'day_before_reminder' => ['sent' => 0, 'skipped_hour' => true],
            'checkin_open' => ['sent' => 0],
            'checkin_close' => ['closed' => 0, 'marks' => 0],
            'payment_reminder_1' => ['sent' => 0, 'skipped_confirmed' => 0, 'not_due' => true],
            'payment_reminder_2' => ['sent' => 0, 'skipped_confirmed' => 0, 'not_due' => true],
        ];

        if ($bot === null || trim($config->botToken) === '') {
            return $result;
        }

        $outbound = new NutgramOutbound($bot);
        $messages = new AttendanceMessageFactory($texts);
        $marks = new AttendanceMarkRepository($pdo);
        $sessions = new CheckInSessionRepository($pdo);
        $prompts = new CheckInPromptRepository($pdo);
        $recipients = new NotificationRecipientResolver($config, $users);
        $blockEvents = new BlockEventRepository($pdo);
        $blocks = new MemberBlockService($users, $blockEvents);
        $recalculator = new ConsecutiveUnexcusedRecalculator($marks, $users);
        $discipline = new AttendanceDiscipline($users, $texts, $recipients, $outbound, $blocks);
        $attendance = new AttendanceService($marks, $users, $discipline, $recalculator);

        $result['day_before_reminder'] = (new DayBeforeReminderJob(
            $rehearsals,
            $users,
            $deliveries,
            $messages,
            $outbound,
        ))->runIfDue($config);

        $result['checkin_open'] = (new CheckinOpenJob(
            $rehearsals,
            $users,
            $marks,
            $sessions,
            $prompts,
            $deliveries,
            $messages,
            $texts,
            $outbound,
        ))->runIfDue($config);

        $result['checkin_close'] = (new CheckinCloseJob(
            $rehearsals,
            $users,
            $marks,
            $sessions,
            $prompts,
            $deliveries,
            $attendance,
            $texts,
            $outbound,
        ))->runIfDue($config);

        $settings = new BotSettingsRepository($pdo);
        $paymentRecords = new PaymentRecordRepository($pdo);
        $paymentTexts = new PaymentTexts($texts);

        $result['payment_reminder_1'] = (new PaymentReminderFirstJob(
            $users,
            $paymentRecords,
            $settings,
            $deliveries,
            $texts,
            $paymentTexts,
            $outbound,
        ))->runIfDue($config);

        $result['payment_reminder_2'] = (new PaymentReminderSecondJob(
            $users,
            $paymentRecords,
            $settings,
            $deliveries,
            $texts,
            $paymentTexts,
            $outbound,
        ))->runIfDue($config);

        return $result;
    }

    /**
     * @internal for tests with a recording outbound
     */
    public function runAttendanceJobs(
        Config $config,
        \PDO $pdo,
        TextRepository $texts,
        TelegramOutbound $outbound,
    ): array {
        $rehearsals = new RehearsalRepository($pdo);
        $users = new UserRepository($pdo);
        $deliveries = new JobDeliveryRepository($pdo);
        $marks = new AttendanceMarkRepository($pdo);
        $sessions = new CheckInSessionRepository($pdo);
        $prompts = new CheckInPromptRepository($pdo);
        $messages = new AttendanceMessageFactory($texts);
        $recipients = new NotificationRecipientResolver($config, $users);
        $blockEvents = new BlockEventRepository($pdo);
        $blocks = new MemberBlockService($users, $blockEvents);
        $recalculator = new ConsecutiveUnexcusedRecalculator($marks, $users);
        $discipline = new AttendanceDiscipline($users, $texts, $recipients, $outbound, $blocks);
        $attendance = new AttendanceService($marks, $users, $discipline, $recalculator);

        return [
            'day_before_reminder' => (new DayBeforeReminderJob(
                $rehearsals,
                $users,
                $deliveries,
                $messages,
                $outbound,
            ))->runIfDue($config),
            'checkin_open' => (new CheckinOpenJob(
                $rehearsals,
                $users,
                $marks,
                $sessions,
                $prompts,
                $deliveries,
                $messages,
                $texts,
                $outbound,
            ))->runIfDue($config),
            'checkin_close' => (new CheckinCloseJob(
                $rehearsals,
                $users,
                $marks,
                $sessions,
                $prompts,
                $deliveries,
                $attendance,
                $texts,
                $outbound,
            ))->runIfDue($config),
        ];
    }
}
