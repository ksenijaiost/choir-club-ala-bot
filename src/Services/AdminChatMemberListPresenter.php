<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Domain\VoicePart;
use App\Repositories\UserRepository;
use App\Text\TextRepository;

final class AdminChatMemberListPresenter
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TextRepository $texts,
        private readonly UserRolePresenter $roles,
    ) {
    }

    /**
     * @return list<string>
     */
    public function buildMessageChunks(): array
    {
        $active = $this->users->listActiveChoirRoster();
        $counts = $this->users->countChoirUsersByStatus();

        $lines = [$this->texts->get('admin_chat.members_header')];

        $conductor = null;
        $byPart = [];
        foreach (VoicePart::CODES as $code) {
            $byPart[$code] = [];
        }
        $noPart = [];

        foreach ($active as $user) {
            if (($user['role'] ?? '') === UserRole::CONDUCTOR) {
                $conductor = $user;

                continue;
            }

            $part = (string) ($user['voice_part'] ?? '');
            if ($part === '' || !VoicePart::isValid($part)) {
                $noPart[] = $user;

                continue;
            }

            $byPart[$part][] = $user;
        }

        if ($conductor !== null) {
            $lines[] = $this->texts->get('admin_chat.members_conductor', [
                'line' => $this->formatPersonLine($conductor),
            ]);
            $lines[] = '';
        }

        foreach (VoicePart::CODES as $code) {
            $members = $byPart[$code];
            if ($members === []) {
                continue;
            }

            $lines[] = $this->texts->get('admin_chat.members_part_header', [
                'part' => $this->texts->get('parts.' . $code),
            ]);
            foreach ($members as $member) {
                $lines[] = $this->formatPersonLine($member);
            }
            $lines[] = '';
        }

        if ($noPart !== []) {
            $lines[] = $this->texts->get('admin_chat.members_no_part_header');
            foreach ($noPart as $member) {
                $lines[] = $this->formatPersonLine($member);
            }
            $lines[] = '';
        }

        $partTotals = [];
        foreach (VoicePart::CODES as $code) {
            $partTotals[$code] = count($byPart[$code]);
        }

        $lines[] = $this->texts->get('admin_chat.members_total', [
            'total' => (string) count($active),
        ]);

        $countLines = [];
        foreach (VoicePart::CODES as $code) {
            if ($partTotals[$code] === 0) {
                continue;
            }
            $countLines[] = $this->texts->get('admin_chat.members_part_count', [
                'part' => $this->texts->get('parts.' . $code),
                'count' => (string) $partTotals[$code],
            ]);
        }
        if ($countLines !== []) {
            $lines[] = implode("\n", $countLines);
        }

        $pending = (int) ($counts[UserStatus::PENDING] ?? 0);
        $blocked = (int) ($counts[UserStatus::BLOCKED] ?? 0);
        if ($pending > 0 || $blocked > 0) {
            $lines[] = $this->texts->get('admin_chat.members_not_listed', [
                'pending' => (string) $pending,
                'blocked' => (string) $blocked,
            ]);
        }

        return \App\Telegram\TelegramMessageChunker::splitByLines(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function formatPersonLine(array $user): string
    {
        $name = (string) ($user['display_name'] ?? '');
        $username = trim((string) ($user['telegram_username'] ?? ''));
        $usernameSuffix = $username !== '' ? ' @' . $username : '';
        $role = (string) ($user['role'] ?? UserRole::MEMBER);
        $roleSuffix = '';
        if ($role !== UserRole::MEMBER) {
            $roleSuffix = $this->texts->get('admin_chat.members_role_suffix', [
                'role' => $this->roles->singingRoleBadge($user),
            ]);
        }

        return $this->texts->get('admin_chat.members_line', [
            'name' => $name,
            'username' => $usernameSuffix,
            'role' => $roleSuffix,
        ]);
    }
}