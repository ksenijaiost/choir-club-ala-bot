<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
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
    ): void {
        self::sendSectionPicker($bot, $texts, $access, $user);
    }

    public static function handleCallback(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        UserRepository $users,
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
            self::editSectionPicker($bot, $texts, $access, $actor);

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
            'btn_schedule' => $texts->get('menu.schedule'),
            'btn_wont_come' => $texts->get('menu.wont_come'),
            'btn_find_song' => $texts->get('menu.find_song'),
            'btn_my_recordings' => $texts->get('menu.my_recordings'),
            'btn_my_profile' => $texts->get('menu.my_profile'),
            'btn_admin_schedule' => $texts->get('menu.admin_schedule'),
            'btn_admin_materials' => $texts->get('menu.admin_materials'),
            'btn_admin_attendance' => $texts->get('menu.admin_attendance'),
            'btn_admin_payment' => $texts->get('menu.admin_payment'),
            'btn_admin_history' => $texts->get('menu.admin_history'),
            'btn_admin_helpers' => $texts->get('menu.admin_helpers'),
            'btn_admin_members' => $texts->get('menu.admin_members'),
            'btn_assign_conductor' => $texts->get('menu.assign_conductor'),
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function sendSectionPicker(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        array $user,
    ): void {
        $sections = self::visibleSectionIds($access, $user);
        if (count($sections) === 1) {
            $keyboard = InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $texts);
            $bot->sendMessage(
                self::sectionText($texts, $sections[0]),
                reply_markup: $keyboard,
            );

            return;
        }

        $bot->sendMessage(
            $texts->get('about.pick_section'),
            reply_markup: self::buildPickerKeyboard($texts, $sections),
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
    ): void {
        $sections = self::visibleSectionIds($access, $user);
        CallbackUi::editCurrentMessage(
            $bot,
            $texts->get('about.pick_section'),
            self::buildPickerKeyboard($texts, $sections),
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
