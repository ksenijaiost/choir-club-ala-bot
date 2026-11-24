<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Telegram\MenuKeyboard;
use App\Telegram\SafeTelegram;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class MenuActionDispatcher
{
    /**
     * @param array<string, mixed> $user
     */
    public static function dispatchMenuByLabel(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        ScheduleDeps $schedule,
        AttendanceDeps $attendance,
        PaymentDeps $payments,
        ?MaterialsDeps $materials,
        ?AdminHistoryContext $adminHistory,
        ?AdminChatDeps $adminChat,
        ?BroadcastDeps $broadcast,
        ?ProgramDeps $programDeps,
        array $user,
        string $text,
    ): bool {
        $labels = MenuKeyboard::allMenuLabels($texts);
        if (!in_array($text, $labels, true)) {
            return false;
        }

        if ($text === $texts->get('menu.admin_helpers')) {
            HelpersAdminHandler::openHelpersUi($bot, $texts, $users, $access, $user, $schedule->interactiveMenus);

            return true;
        }

        if ($text === $texts->get('menu.assign_conductor')) {
            ConductorAdminHandler::openConductorPicker($bot, $texts, $users, $access, $user, $schedule->interactiveMenus);

            return true;
        }

        if ($text === $texts->get('menu.admin_members')) {
            MembersAdminHandler::openFilterUi($bot, $texts, $access, $user, $schedule->interactiveMenus);

            return true;
        }

        if ($text === $texts->get('menu.schedule')) {
            ScheduleMemberHandler::sendUpcoming(
                $bot,
                $texts,
                $schedule->config,
                $schedule->generator,
                $schedule->presenter,
            );

            return true;
        }

        if ($text === $texts->get('menu.wont_come')) {
            AttendanceMemberHandler::openWontComePicker($bot, $attendance);

            return true;
        }

        if ($text === $texts->get('menu.admin_schedule')) {
            ScheduleAdminHandler::openAdminHome($bot, $schedule, $access, $user);

            return true;
        }

        if ($text === $texts->get('menu.admin_attendance')) {
            AttendanceAdminHandler::openHome($bot, $attendance, $access, $user);

            return true;
        }

        if ($text === $texts->get('menu.admin_payment')) {
            PaymentAdminHandler::openHome($bot, $payments, $access, $user);

            return true;
        }

        if ($text === $texts->get('menu.admin_materials') && $materials !== null && $adminChat !== null) {
            MaterialsAdminHandler::openHome($bot, $texts, $access, $materials, $adminChat->resolver, $user);

            return true;
        }

        if ($text === $texts->get('menu.admin_chat') && $adminChat !== null) {
            AdminChatOwnerHandler::openHome($bot, $texts, $access, $adminChat, $user);

            return true;
        }

        if ($text === $texts->get('menu.find_song') && $materials !== null) {
            SongMemberHandler::beginSearch($bot, $materials, (int) $user['telegram_id']);

            return true;
        }

        if ($text === $texts->get('menu.my_recordings') && $materials !== null) {
            SongMemberHandler::openMyRecordings($bot, $materials, $materials->delivery, $user, $programDeps);

            return true;
        }

        if ($text === $texts->get('menu.member_programs') && $programDeps !== null) {
            ProgramMemberHandler::openCatalog($bot, $programDeps, $user);

            return true;
        }

        if ($text === $texts->get('menu.programs') && $programDeps !== null) {
            if (!$access->canManageBroadcasts($user)) {
                SafeTelegram::sendMessage($bot, $programDeps->texts->get('programs.forbidden'));

                return true;
            }
            ProgramAdminHandler::openHome($bot, $programDeps, $access, $user);

            return true;
        }

        if ($text === $texts->get('menu.my_profile')) {
            ProfileHandler::openProfile($bot, $texts, $users, $user, $schedule->interactiveMenus);

            return true;
        }

        if ($text === $texts->get('menu.about_bot')) {
            AboutBotHandler::openHome($bot, $texts, $access, $user, $schedule->interactiveMenus);

            return true;
        }

        if ($text === $texts->get('menu.payment')) {
            PaymentMemberHandler::openHome($bot, $payments, $user);

            return true;
        }

        if ($text === $texts->get('menu.admin_history') && $adminHistory !== null) {
            AdminHistoryHandler::openHome($bot, $access, $user, $adminHistory);

            return true;
        }

        if ($text === $texts->get('menu.broadcast') && $broadcast !== null) {
            BroadcastWizardHandler::start($bot, $broadcast, $access, $user);

            return true;
        }

        if ($text === $texts->get('menu.broadcasts_list') && $broadcast !== null) {
            BroadcastAdminHandler::openList($bot, $broadcast, $access, $user, false);

            return true;
        }

        $bot->sendMessage($texts->get('common.soon'));

        return true;
    }
}