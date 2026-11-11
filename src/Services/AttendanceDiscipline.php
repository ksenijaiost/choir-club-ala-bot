<?php

declare(strict_types=1);

namespace App\Services;

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
    ) {
    }

    /**
     * @param array<string, mixed> $user
     */
    public function onUnexcusedRecorded(array $user): void
    {
        $userId = (int) $user['id'];
        $count = $this->users->incrementConsecutiveUnexcused($userId);
        $telegramId = (int) $user['telegram_id'];

        if ($count === 3) {
            $this->outbound->sendMessage($telegramId, $this->texts->get('discipline.third_strike'));
        }

        if ($count >= 4) {
            $name = (string) ($user['display_name'] ?? '');
            $partCode = (string) ($user['voice_part'] ?? '');
            $part = $partCode !== '' ? $this->texts->get('parts.' . $partCode) : '—';

            $this->outbound->sendMessage($telegramId, $this->texts->get('discipline.expelled'));

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
        }
    }
}
