<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\UserStatus;
use App\Domain\VoicePart;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\MemberBlockService;
use App\Services\UserRolePresenter;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\MenuKeyboard;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class MembersAdminHandler
{
    public static function register(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        MemberBlockService $blocks,
    ): void {
        $bot->onCommand('members', function (Nutgram $bot) use ($texts, $users, $access): void {
            $telegramId = $bot->userId();
            if ($telegramId === null) {
                return;
            }

            $user = $users->findByTelegramId($telegramId);
            if ($user === null) {
                return;
            }

            self::openFilterUi($bot, $texts, $access, $user);
        });
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function openFilterUi(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        array $user,
    ): void {
        if (!$access->hasConductorPrivileges($user)) {
            $bot->sendMessage($texts->get('admin.only_conductor_or_owner'));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make(
                text: $texts->get('admin.members_filter_all'),
                callback_data: 'bl:f:all',
            ),
        );

        $row = [];
        foreach (VoicePart::CODES as $code) {
            $row[] = InlineKeyboardButton::make(
                text: $texts->get('parts.' . $code),
                callback_data: 'bl:f:' . $code,
            );
            if (count($row) === 2) {
                $keyboard = $keyboard->addRow(...$row);
                $row = [];
            }
        }
        if ($row !== []) {
            $keyboard = $keyboard->addRow(...$row);
        }

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $texts);

        $bot->sendMessage(
            text: $texts->get('admin.members_pick_filter'),
            reply_markup: $keyboard,
        );
    }

    public static function sendMemberList(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        string $filter,
        int $page = 0,
    ): void {
        $all = $users->listSingingMembersForAdmin($filter === 'all' ? null : $filter);
        $members = InlineMenuHelper::pageSlice($all, $page);
        $total = count($all);
        $filterLabel = $filter === 'all'
            ? $texts->get('admin.members_filter_all')
            : $texts->get('parts.' . $filter);

        if ($total === 0) {
            $bot->sendMessage($texts->get('admin.members_list_empty', ['filter' => $filterLabel]));

            return;
        }

        $lines = [
            $texts->get('admin.members_list_header', ['filter' => $filterLabel]),
        ];

        $roles = new UserRolePresenter($texts);

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($members as $member) {
            $statusKey = ($member['status'] ?? '') === UserStatus::BLOCKED
                ? 'admin.member_status_blocked'
                : 'admin.member_status_active';
            $partCode = (string) ($member['voice_part'] ?? '');
            $partLabel = $partCode !== '' ? $texts->get('parts.' . $partCode) : '—';

            $badge = $roles->singingRoleBadge($member);
            $name = (string) $member['display_name'];
            $lines[] = $texts->get('admin.member_row', [
                'name' => $badge !== '' ? $name . ' · ' . $badge : $name,
                'part' => $partLabel,
                'status' => $texts->get($statusKey),
            ]);

            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: $roles->displayNameWithBadge($member),
                    callback_data: 'bl:p:' . (int) $member['id'],
                ),
            );
        }

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $texts,
            $page,
            $total,
            'bl:pg:' . $filter . ':',
            'bl:menu',
        );

        $bot->sendMessage(text: implode("\n", $lines), reply_markup: $keyboard);
    }

    public static function sendMemberActions(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        int $targetId,
    ): void {
        $target = $users->findById($targetId);
        if ($target === null) {
            $bot->sendMessage($texts->get('errors.generic'));

            return;
        }

        $partCode = (string) ($target['voice_part'] ?? '');
        $partLabel = $partCode !== '' ? $texts->get('parts.' . $partCode) : '—';
        $status = (string) ($target['status'] ?? '');
        $statusText = $status === UserStatus::BLOCKED
            ? $texts->get('admin.member_status_blocked')
            : $texts->get('admin.member_status_active');

        $text = $texts->get('admin.member_card', [
            'name' => (string) $target['display_name'],
            'part' => $partLabel,
            'status' => $statusText,
        ]);

        $keyboard = InlineKeyboardMarkup::make();
        if ($status === UserStatus::ACTIVE) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: $texts->get('buttons.block'),
                    callback_data: 'bl:b:' . $targetId,
                ),
            );
        } elseif ($status === UserStatus::BLOCKED) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: $texts->get('buttons.unblock'),
                    callback_data: 'bl:u:' . $targetId,
                ),
            );
        }

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $texts, 'bl:menu');

        $bot->sendMessage(text: $text, reply_markup: $keyboard);
    }

    public static function handleCallback(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        MemberBlockService $blocks,
        string $data,
        int $actorTelegramId,
    ): bool {
        if (!str_starts_with($data, 'bl:')) {
            return false;
        }

        $actor = $users->findByTelegramId($actorTelegramId);
        if ($actor === null || !$access->hasConductorPrivileges($actor)) {
            $bot->answerCallbackQuery(text: $texts->get('admin.only_conductor_or_owner'), show_alert: true);

            return true;
        }

        if ($data === 'bl:menu') {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::openFilterUi($bot, $texts, $access, $actor);

            return true;
        }

        if (preg_match('/^bl:pg:(.+):(\d+)$/', $data, $m) === 1) {
            $filter = $m[1];
            if ($filter !== 'all' && !VoicePart::isValid($filter)) {
                $bot->answerCallbackQuery();

                return true;
            }

            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendMemberList($bot, $texts, $users, $filter, (int) $m[2]);

            return true;
        }

        if (preg_match('/^bl:f:(.+)$/', $data, $m) === 1) {
            $filter = $m[1];
            if ($filter !== 'all' && !VoicePart::isValid($filter)) {
                $bot->answerCallbackQuery();

                return true;
            }

            $bot->answerCallbackQuery();
            self::sendMemberList($bot, $texts, $users, $filter);

            return true;
        }

        if (preg_match('/^bl:p:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            self::sendMemberActions($bot, $texts, $users, (int) $m[1]);

            return true;
        }

        if (preg_match('/^bl:b:(\d+)$/', $data, $m) === 1) {
            $targetId = (int) $m[1];

            try {
                $blocks->blockManual((int) $actor['id'], $targetId);
            } catch (\InvalidArgumentException) {
                $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

                return true;
            }

            $target = $users->findById($targetId);
            $bot->answerCallbackQuery();
            $bot->sendMessage($texts->get('admin.member_blocked_ok', [
                'name' => (string) ($target['display_name'] ?? ''),
            ]));

            if ($target !== null) {
                $bot->sendMessage(
                    text: $texts->get('registration.blocked'),
                    chat_id: (int) $target['telegram_id'],
                );
            }

            self::sendMemberActions($bot, $texts, $users, $targetId);

            return true;
        }

        if (preg_match('/^bl:u:(\d+)$/', $data, $m) === 1) {
            $targetId = (int) $m[1];

            try {
                $blocks->unblockManual((int) $actor['id'], $targetId);
            } catch (\InvalidArgumentException) {
                $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

                return true;
            }

            $target = $users->findById($targetId);
            $bot->answerCallbackQuery();
            $bot->sendMessage($texts->get('admin.member_unblocked_ok', [
                'name' => (string) ($target['display_name'] ?? ''),
            ]));

            if ($target !== null) {
                $target = $users->findById($targetId) ?? $target;
                $bot->sendMessage(
                    text: $texts->get('admin.member_unblocked_user', ['name' => (string) $target['display_name']]),
                    chat_id: (int) $target['telegram_id'],
                    reply_markup: MenuKeyboard::forUser($texts, $target),
                );
            }

            self::sendMemberActions($bot, $texts, $users, $targetId);

            return true;
        }

        return false;
    }
}
