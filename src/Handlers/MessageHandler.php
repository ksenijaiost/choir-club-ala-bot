<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\UserStatus;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\UserIdentitySync;
use App\Telegram\MenuKeyboard;
use App\Telegram\PartKeyboard;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;

final class MessageHandler
{
    public static function register(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        ScheduleDeps $schedule,
        AttendanceDeps $attendance,
        PaymentDeps $payments,
        ?MaterialsDeps $materials = null,
        ?AdminHistoryContext $adminHistory = null,
    ): void {
        $bot->onMessage(function (Nutgram $bot) use ($texts, $users, $access, $schedule, $attendance, $payments, $materials, $adminHistory): void {
            if ($bot->message()?->text === null) {
                return;
            }

            $chat = $bot->chat();
            if ($chat === null || $chat->type !== ChatType::PRIVATE) {
                return;
            }

            $telegramId = $bot->userId();
            if ($telegramId === null) {
                return;
            }

            UserIdentitySync::syncPrivilegedTelegramUser(
                $telegramId,
                trim($bot->user()?->first_name ?? ''),
                $access,
                $users,
            );

            $text = trim($bot->message()->text ?? '');
            if ($text === '') {
                return;
            }

            if (str_starts_with($text, '/')) {
                return;
            }

            $user = $users->findByTelegramId($telegramId);
            if ($user === null) {
                return;
            }

            $status = (string) ($user['status'] ?? '');

            if ($status === UserStatus::BLOCKED) {
                $bot->sendMessage($texts->get('registration.blocked'));

                return;
            }

            if ($status === UserStatus::REJECTED) {
                $bot->sendMessage($texts->get('registration.rejected'));

                return;
            }

            if ($status === UserStatus::PENDING) {
                if (StartHandler::isRegistrationIncomplete($user)) {
                    self::handleRegistrationName($bot, $texts, $users, $user, $text);

                    return;
                }

                $bot->sendMessage($texts->get('registration.pending_user'));

                return;
            }

            if ($status === UserStatus::ACTIVE) {
                if ($telegramId !== null && AttendanceMemberHandler::handleFlowMessage(
                    $bot,
                    $attendance,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if ($materials !== null && $telegramId !== null && ProfileHandler::handleFlowMessage(
                    $bot,
                    $texts,
                    $users,
                    $materials->flows,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if ($materials !== null && $telegramId !== null && SongMemberHandler::handleFlowMessage(
                    $bot,
                    $materials,
                    $materials->delivery,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if ($telegramId !== null && ScheduleAdminHandler::handleFlowMessage(
                    $bot,
                    $texts,
                    $schedule->config,
                    $schedule->rehearsals,
                    $schedule->templates,
                    $schedule->flows,
                    $schedule->generator,
                    $schedule->broadcast,
                    $schedule->settings,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                self::handleActiveMenu(
                    $bot,
                    $texts,
                    $users,
                    $access,
                    $schedule,
                    $attendance,
                    $payments,
                    $materials,
                    $adminHistory,
                    $user,
                    $text,
                );

                return;
            }
        });
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function handleRegistrationName(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        array $user,
        string $text,
    ): void {
        if (trim((string) ($user['display_name'] ?? '')) !== '') {
            $bot->sendMessage(
                text: $texts->get('common.use_part_buttons'),
                reply_markup: PartKeyboard::registration($texts),
            );

            return;
        }

        if (mb_strlen($text) < 2 || mb_strlen($text) > 100) {
            $bot->sendMessage($texts->get('common.invalid_name'));

            return;
        }

        $users->updateDisplayName((int) $user['id'], $text);
        $bot->sendMessage(
            text: $texts->get('registration.ask_part'),
            reply_markup: PartKeyboard::registration($texts),
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function handleActiveMenu(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        ScheduleDeps $schedule,
        AttendanceDeps $attendance,
        PaymentDeps $payments,
        ?MaterialsDeps $materials,
        ?AdminHistoryContext $adminHistory,
        array $user,
        string $text,
    ): void {
        $labels = MenuKeyboard::allMenuLabels($texts);
        if (!in_array($text, $labels, true)) {
            return;
        }

        if ($text === $texts->get('menu.admin_helpers')) {
            HelpersAdminHandler::openHelpersUi($bot, $texts, $users, $access, $user);

            return;
        }

        if ($text === $texts->get('menu.assign_conductor')) {
            ConductorAdminHandler::openConductorPicker($bot, $texts, $users, $access, $user);

            return;
        }

        if ($text === $texts->get('menu.admin_members')) {
            MembersAdminHandler::openFilterUi($bot, $texts, $access, $user);

            return;
        }

        if ($text === $texts->get('menu.schedule')) {
            ScheduleMemberHandler::sendUpcoming(
                $bot,
                $texts,
                $schedule->config,
                $schedule->generator,
                $schedule->presenter,
            );

            return;
        }

        if ($text === $texts->get('menu.wont_come')) {
            AttendanceMemberHandler::openWontComePicker($bot, $attendance);

            return;
        }

        if ($text === $texts->get('menu.admin_schedule')) {
            ScheduleAdminHandler::openAdminHome($bot, $texts, $access, $user);

            return;
        }

        if ($text === $texts->get('menu.admin_attendance')) {
            AttendanceAdminHandler::openHome($bot, $texts, $access, $user);

            return;
        }

        if ($text === $texts->get('menu.admin_payment')) {
            PaymentAdminHandler::openHome($bot, $payments, $access, $user);

            return;
        }

        if ($text === $texts->get('menu.admin_materials') && $materials !== null) {
            MaterialsAdminHandler::openHome($bot, $texts, $access, $materials, $user);

            return;
        }

        if ($text === $texts->get('menu.find_song') && $materials !== null) {
            SongMemberHandler::beginSearch($bot, $materials, (int) $user['telegram_id']);

            return;
        }

        if ($text === $texts->get('menu.my_recordings') && $materials !== null) {
            SongMemberHandler::openMyRecordings($bot, $materials, $materials->delivery, $user);

            return;
        }

        if ($text === $texts->get('menu.my_profile')) {
            ProfileHandler::openProfile($bot, $texts, $users, $user);

            return;
        }

        if ($text === $texts->get('menu.admin_history') && $adminHistory !== null) {
            AdminHistoryHandler::openHome($bot, $access, $user, $adminHistory);

            return;
        }

        $bot->sendMessage($texts->get('common.soon'));
    }
}
