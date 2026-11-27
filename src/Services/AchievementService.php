<?php

declare(strict_types=1);

namespace App\Services;

use App\Clock;
use App\Config;
use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Domain\SecretAchievements;
use App\Domain\UserRole;
use App\Handlers\PaymentMemberHandler;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\BotSettingsRepository;
use App\Repositories\DayBeforeReminderDeliveryRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\ProgramRepository;
use App\Repositories\UserAchievementRepository;
use App\Repositories\UserRepository;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;

final class AchievementService
{
    public function __construct(
        private readonly UserAchievementRepository $achievements,
        private readonly TextRepository $texts,
        private readonly TelegramOutbound $outbound,
        private readonly AttendanceMarkRepository $marks,
        private readonly DayBeforeReminderDeliveryRepository $dayBeforeDeliveries,
        private readonly ProgramRepository $programs,
        private readonly PaymentRecordRepository $payments,
        private readonly BotSettingsRepository $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     */
    public function awardIfNew(array $user, string $code): bool
    {
        if (!$this->isEligible($user, $code)) {
            return false;
        }

        $userId = (int) $user['id'];
        if (!$this->achievements->tryAward($userId, $code)) {
            return false;
        }

        $telegramId = (int) $user['telegram_id'];
        $badge = $this->texts->get(SecretAchievements::badgeKey($code));
        $title = $this->texts->get(SecretAchievements::titleKey($code));
        $reveal = $this->texts->get(SecretAchievements::revealKey($code));
        $this->outbound->sendMessage($telegramId, $this->texts->get('achievements.secret.unlocked', [
            'badge' => $badge,
            'title' => $title,
            'reveal' => $reveal,
        ]));

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function onAttendedCheckIn(array $user, int $rehearsalId): void
    {
        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            return;
        }

        $attendedUserIds = [];
        foreach ($this->marks->listForRehearsal($rehearsalId) as $row) {
            if (($row['mark'] ?? '') === AttendanceMark::ATTENDED) {
                $attendedUserIds[] = (int) $row['user_id'];
            }
        }

        if ($attendedUserIds !== [(int) $user['id']]) {
            return;
        }

        $this->awardIfNew($user, SecretAchievements::EARLY_BIRD);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function onMaterialsOpened(array $user, Config $config): void
    {
        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            return;
        }

        $local = Clock::now($config)->setTimezone(new \DateTimeZone($config->timezone));
        $hour = (int) $local->format('G');
        if ($hour < 0 || $hour >= 5) {
            return;
        }

        $this->awardIfNew($user, SecretAchievements::NIGHT_OWL);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function onProgramRecordingsFetched(array $user, int $programId, Config $config): void
    {
        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            return;
        }

        $program = $this->programs->find($programId);
        if ($program === null) {
            return;
        }

        $anchor = (string) ($program['notified_at'] ?? '');
        if ($anchor === '') {
            $anchor = (string) ($program['created_at'] ?? '');
        }
        if ($anchor === '') {
            return;
        }

        $published = new \DateTimeImmutable($anchor, new \DateTimeZone($config->timezone));
        $now = Clock::now($config)->setTimezone(new \DateTimeZone($config->timezone));
        if ($now > $published->modify('+24 hours')) {
            return;
        }

        $this->awardIfNew($user, SecretAchievements::FAST_LEARNER);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function onPaymentConfirmed(array $user, int $year, int $month, Config $config): void
    {
        if (!PaymentMemberHandler::isActivePayer($user)) {
            return;
        }

        $tz = new \DateTimeZone($config->timezone);
        $day = $this->settings->getInt('payment_reminder_day_1', 15);
        $hour = $this->settings->getInt('payment_reminder_hour', 12);
        $deadline = (new \DateTimeImmutable(
            sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day),
            $tz,
        ))->setTime($hour, 0);

        $now = Clock::now($config)->setTimezone($tz);
        if ($now >= $deadline) {
            return;
        }

        $row = $this->payments->find((int) $user['id'], $year, $month);
        if ($row === null || !$this->payments->isConductorConfirmed($row)) {
            return;
        }

        $this->awardIfNew($user, SecretAchievements::PUNCTUAL_PAYER);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function onWontComeWarned(array $user, int $rehearsalId, string $source): void
    {
        if ($source !== AttendanceSource::ABSENCE_BUTTON) {
            return;
        }

        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            return;
        }

        if ($this->dayBeforeDeliveries->find($rehearsalId, (int) $user['id']) !== null) {
            return;
        }

        $streak = $this->wordKeeperStreak((int) $user['id']);
        if ($streak >= 5) {
            $this->awardIfNew($user, SecretAchievements::WORD_KEEPER);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function formatProfileSection(array $user): string
    {
        $codes = $this->achievements->listCodesForUser((int) $user['id']);
        if ($codes === []) {
            return '';
        }

        $lines = [$this->texts->get('achievements.secret.profile_header')];
        foreach ($codes as $code) {
            $lines[] = $this->texts->get('achievements.secret.profile_line', [
                'badge' => $this->texts->get(SecretAchievements::badgeKey($code)),
                'title' => $this->texts->get(SecretAchievements::titleKey($code)),
            ]);
        }

        return implode("\n", $lines);
    }

    private function wordKeeperStreak(int $userId): int
    {
        $streak = 0;
        foreach ($this->marks->listChronologicalForUser($userId) as $row) {
            $mark = (string) ($row['mark'] ?? '');
            if ($mark === AttendanceMark::UNEXCUSED) {
                $streak = 0;
                continue;
            }

            if ($mark !== AttendanceMark::WARNED) {
                continue;
            }

            if ((string) ($row['source'] ?? '') !== AttendanceSource::ABSENCE_BUTTON) {
                continue;
            }

            $rehearsalId = (int) ($row['rehearsal_id'] ?? 0);
            if ($this->dayBeforeDeliveries->find($rehearsalId, $userId) !== null) {
                continue;
            }

            $streak++;
        }

        return $streak;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isEligible(array $user, string $code): bool
    {
        if ($code === SecretAchievements::PUNCTUAL_PAYER) {
            return PaymentMemberHandler::isActivePayer($user);
        }

        return UserRole::participatesInAttendance((string) ($user['role'] ?? ''));
    }
}