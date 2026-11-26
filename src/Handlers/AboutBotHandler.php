<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\StreakMilestones;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\InteractiveMenuService;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class AboutBotHandler
{
    private const HOME = 'ab:home';

    /**
     * @param array<string, mixed> $user
     */
    public static function openHome(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        array $user,
        InteractiveMenuService $interactiveMenus,
    ): void {
        self::sendSectionPicker($bot, $texts, $access, $user, $interactiveMenus);
    }

    public static function handleCallback(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        UserRepository $users,
        InteractiveMenuService $interactiveMenus,
        string $data,
        int $actorTelegramId,
    ): void {
        $actor = $users->findByTelegramId($actorTelegramId);
        if ($actor === null) {
            $bot->answerCallbackQuery();

            return;
        }

        $bot->answerCallbackQuery();

        if ($data === self::HOME) {
            self::editSectionPicker($bot, $texts, $access, $actor, $interactiveMenus, $actorTelegramId);

            return;
        }

        if (!str_starts_with($data, 'ab:sec:')) {
            return;
        }

        $sectionId = substr($data, strlen('ab:sec:'));
        if (!in_array($sectionId, self::visibleSectionIds($access, $actor), true)) {
            return;
        }

        $keyboard = InlineMenuHelper::appendFooter(
            InlineKeyboardMarkup::make(),
            $texts,
            self::HOME,
        );

        CallbackUi::editCurrentMessage(
            $bot,
            self::sectionText($texts, $sectionId),
            $keyboard,
            $interactiveMenus,
            $actorTelegramId,
        );
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return list<string>
     */
    public static function visibleSectionIds(AccessControl $access, array $user): array
    {
        $ids = ['member'];

        if ($access->hasAdminStaffMenu($user)) {
            $ids[] = 'staff';
        }

        if ($access->hasConductorPrivileges($user)) {
            $ids[] = 'conductor';
        }

        if ($access->isOwner($user)) {
            $ids[] = 'owner';
        }

        return $ids;
    }

    public static function sectionText(TextRepository $texts, string $sectionId): string
    {
        $ctx = self::menuPlaceholders($texts);

        return match ($sectionId) {
            'member' => $texts->get('about.member_body', $ctx),
            'staff' => $texts->get('about.staff_body', $ctx),
            'conductor' => $texts->get('about.conductor_body', $ctx),
            'owner' => $texts->get('about.owner_body', $ctx),
            default => '',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function menuPlaceholders(TextRepository $texts): array
    {
        return [
            'cmd_start' => $texts->get('commands.start'),
            'cmd_song' => $texts->get('commands.song'),
            'cmd_recordings' => $texts->get('commands.recordings'),
            'cmd_profile' => $texts->get('commands.profile'),
            'cmd_about' => $texts->get('commands.about'),
            'cmd_programs' => $texts->get('commands.programs'),
            'cmd_programs_edit' => $texts->get('commands.programs_edit'),
            'cmd_stats' => $texts->get('commands.stats'),
            'cmd_rsvp' => $texts->get('commands.rsvp'),
            'btn_rsvp' => $texts->get('rsvp.open_button'),
            'btn_schedule' => $texts->get('menu.schedule'),
            'btn_wont_come' => $texts->get('menu.wont_come'),
            'btn_find_song' => $texts->get('menu.find_song'),
            'btn_my_recordings' => $texts->get('menu.my_recordings'),
            'btn_member_programs' => $texts->get('menu.member_programs'),
            'btn_my_profile' => $texts->get('menu.my_profile'),
            'btn_payment' => $texts->get('menu.payment'),
            'btn_pay_later' => $texts->get('buttons.pay_later'),
            'btn_admin_schedule' => $texts->get('menu.admin_schedule'),
            'btn_admin_materials' => $texts->get('menu.admin_materials'),
            'btn_admin_attendance' => $texts->get('menu.admin_attendance'),
            'btn_admin_payment' => $texts->get('menu.admin_payment'),
            'btn_payment_reminder_texts' => $texts->get('payment.reminder_texts_button'),
            'btn_admin_member_payments' => $texts->get('payment.admin_member_payments_button'),
            'btn_admin_month_edit' => $texts->get('payment.admin_month_edit_button'),
            'btn_admin_paid_through' => $texts->get('payment.admin_paid_through_button'),
            'btn_confirm_payment' => $texts->get('buttons.confirm_payment'),
            'btn_admin_history' => $texts->get('menu.admin_history'),
            'btn_admin_helpers' => $texts->get('menu.admin_helpers'),
            'btn_admin_members' => $texts->get('menu.admin_members'),
            'btn_assign_conductor' => $texts->get('menu.assign_conductor'),
            'btn_admin_chat' => $texts->get('menu.admin_chat'),
            'btn_broadcast' => $texts->get('menu.broadcast'),
            'btn_broadcasts_list' => $texts->get('menu.broadcasts_list'),
            'btn_programs' => $texts->get('menu.programs'),
            'btn_programs_edit' => $texts->get('menu.programs'),
            'btn_broadcast_read' => $texts->get('broadcast.button_read'),
            'btn_broadcast_custom' => $texts->get('broadcast.button_custom'),
            'btn_my_recordings_pick' => $texts->get('programs.hub_pick_button'),
            'programs_card_button_notes' => $texts->get('programs.member_card_button_notes'),
            'programs_card_button_recordings' => $texts->get('programs.member_card_button_recordings'),
            'programs_card_button_about' => $texts->get('programs.member_card_button_about'),
            'btn_my_recordings_all' => $texts->get('programs.hub_all_button'),
            'btn_my_recordings_program_send_all' => $texts->get('programs.member_send_all_button'),
            'programs_notify_button' => $texts->get('programs.button_open_recordings'),
            'recording_link_suffix' => $texts->get('admin_chat.recording_link_suffix'),
            'phrase_materials_notifications' => $texts->get('materials.notifications_sent', ['count' => 'N']),
            'streak_freeze' => $texts->get('streak.about_freeze'),
            'streak_milestones_list' => self::formatStreakMilestonesList($texts),
            'achievements_secret_about' => $texts->get('achievements.secret.about_teaser'),
        ];
    }

    public static function formatStreakMilestonesList(TextRepository $texts): string
    {
        $lines = [];
        foreach (StreakMilestones::all() as $milestone) {
            $lines[] = $milestone . ' — '
                . $texts->get(StreakMilestones::badgeKey($milestone)) . ' '
                . $texts->get(StreakMilestones::textKey($milestone));
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function sendSectionPicker(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        array $user,
        InteractiveMenuService $interactiveMenus,
    ): void {
        $sections = self::visibleSectionIds($access, $user);
        $ownerTelegramId = (int) ($user['telegram_id'] ?? $bot->userId() ?? 0);

        if (count($sections) === 1) {
            $keyboard = InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $texts);
            InlineMenuHelper::sendInteractiveMenu(
                $bot,
                $interactiveMenus,
                $ownerTelegramId,
                self::sectionText($texts, $sections[0]),
                $keyboard,
            );

            return;
        }

        $keyboard = self::buildPickerKeyboard($texts, $sections);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $interactiveMenus,
            $ownerTelegramId,
            $texts->get('about.pick_section'),
            $keyboard,
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function editSectionPicker(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        array $user,
        InteractiveMenuService $interactiveMenus,
        int $ownerTelegramId,
    ): void {
        $sections = self::visibleSectionIds($access, $user);
        CallbackUi::editCurrentMessage(
            $bot,
            $texts->get('about.pick_section'),
            self::buildPickerKeyboard($texts, $sections),
            $interactiveMenus,
            $ownerTelegramId,
        );
    }

    /**
     * @param list<string> $sections
     */
    private static function buildPickerKeyboard(TextRepository $texts, array $sections): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($sections as $sectionId) {
            $label = match ($sectionId) {
                'member' => $texts->get('about.section_member'),
                'staff' => $texts->get('about.section_staff'),
                'conductor' => $texts->get('about.section_conductor'),
                'owner' => $texts->get('about.section_owner'),
                default => $sectionId,
            };
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'ab:sec:' . $sectionId),
            );
        }

        return InlineMenuHelper::appendFooter($keyboard, $texts);
    }
}