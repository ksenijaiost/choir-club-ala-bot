<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\UserRole;
use App\Repositories\UserRepository;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;

final class AttendanceDiscipline
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TextRepository $texts,
        private readonly NotificationRecipientResolver $recipients,
        private readonly TelegramOutbound $outbound,
        private readonly MemberBlockService $blocks,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     */
    public function onUnexcusedRecorded(array $user, ?string $streakAppend = null): bool
    {
        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            return false;
        }

        $userId = (int) $user['id'];
        $count = $this->users->incrementConsecutiveUnexcused($userId);
        $telegramId = (int) $user['telegram_id'];
        $sent = false;

        if ($count === 3) {
            $this->sendWithOptionalAppend($telegramId, $this->texts->get('discipline.third_strike'), $streakAppend);
            $sent = true;
        }

        if ($count >= 4 && !$this->isExemptFromAutoBlock($user)) {
            $name = (string) ($user['display_name'] ?? '');
            $partCode = (string) ($user['voice_part'] ?? '');
            $part = $partCode !== '' ? $this->texts->get('parts.' . $partCode) : '—';

            $expelled = $this->texts->get('discipline.expelled');
            if ($streakAppend !== null && $streakAppend !== '') {
                $expelled .= "\n" . $streakAppend;
            }
            $this->outbound->sendMessage($telegramId, $expelled);

            $conductorTg = $this->recipients->resolveTelegramId();
            if ($conductorTg !== null) {
                $this->outbound->sendMessage(
                    $conductorTg,
                    $this->texts->get('discipline.expelled_notify_conductor', [
                        'name' => $name,
                        'part' => $part,
                    ]),
                );
            }

            try {
                $this->blocks->blockAuto($userId);
            } catch (\InvalidArgumentException) {
            }

            $sent = true;
        }

        return $sent;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isExemptFromAutoBlock(array $user): bool
    {
        $role = (string) ($user['role'] ?? '');

        return $role === UserRole::OWNER;
    }

    private function sendWithOptionalAppend(int $telegramId, string $text, ?string $append): void
    {
        if ($append !== null && $append !== '') {
            $text .= "\n" . $append;
        }
        $this->outbound->sendMessage($telegramId, $text);
    }
}
