<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Telegram\TelegramHtml;
use App\Text\TextRepository;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class RegistrationAdminMessageBuilder
{
    public function __construct(
        private readonly TextRepository $texts,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $applicant
     */
    public function buildNewRequest(array $applicant, string $partLabel, ?User $telegramUser): string
    {
        $telegramId = (int) ($applicant['telegram_id'] ?? 0);
        $profile = TelegramHtml::profileBlock(
            $this->texts->get('registration.profile_label'),
            $this->texts->get('registration.telegram_id_label'),
            $telegramId,
            TelegramHtml::telegramDisplayName($telegramUser),
            isset($applicant['telegram_username']) ? (string) $applicant['telegram_username'] : null,
        );

        return $this->texts->get('registration.admin_new_request', [
            'name' => TelegramHtml::escape((string) ($applicant['display_name'] ?? '')),
            'part' => TelegramHtml::escape($partLabel),
            'profile_line' => $profile['profile_line'],
            'telegram_id_line' => $profile['telegram_id_line'],
        ]);
    }

    /**
     * @param array<string, mixed> $applicant
     * @param array<string, mixed>|null $moderator
     */
    public function buildResolvedAccepted(array $applicant, string $partLabel, ?array $moderator): string
    {
        return $this->buildResolved(true, $applicant, $partLabel, $moderator);
    }

    /**
     * @param array<string, mixed> $applicant
     * @param array<string, mixed>|null $moderator
     */
    public function buildResolvedRejected(array $applicant, string $partLabel, ?array $moderator): string
    {
        return $this->buildResolved(false, $applicant, $partLabel, $moderator);
    }

    /**
     * @param array<string, mixed> $applicant
     * @param array<string, mixed>|null $moderator
     */
    private function buildResolved(bool $accepted, array $applicant, string $partLabel, ?array $moderator): string
    {
        $telegramId = (int) ($applicant['telegram_id'] ?? 0);
        $linkLabel = (string) ($applicant['display_name'] ?? '');
        $nameBlock = TelegramHtml::userLink($telegramId, $linkLabel);
        $username = trim((string) ($applicant['telegram_username'] ?? ''));
        if ($username !== '') {
            $nameBlock .= ' (' . TelegramHtml::usernameLink($username) . ')';
        }

        $key = $accepted
            ? 'registration.admin_request_accepted'
            : 'registration.admin_request_rejected';

        return $this->texts->get($key, [
            'name_block' => $nameBlock,
            'part' => TelegramHtml::escape($partLabel),
            'moderator_line' => $this->formatModeratorLine($accepted, $moderator),
        ]);
    }

    /**
     * @param array<string, mixed>|null $moderator
     */
    private function formatModeratorLine(bool $accepted, ?array $moderator): string
    {
        if ($moderator === null) {
            return '';
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->config->timezone));
        $textKey = $accepted ? 'registration.resolved_by_accepted' : 'registration.resolved_by_rejected';

        return $this->texts->get($textKey, [
            'name' => TelegramHtml::escape((string) ($moderator['display_name'] ?? '')),
            'datetime' => $now->format('d.m.Y H:i'),
        ]);
    }
}