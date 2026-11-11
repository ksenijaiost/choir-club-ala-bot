<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Domain\VoicePart;
use App\Repositories\RegistrationRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\MemberBlockService;
use App\Services\UserIdentitySync;
use App\Telegram\MenuKeyboard;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class CallbackQueryHandler
{
    public static function register(
        Nutgram $bot,
        TextRepository $texts,
        Config $config,
        UserRepository $users,
        RegistrationRepository $registrations,
        AccessControl $access,
        MemberBlockService $blocks,
        ScheduleDeps $schedule,
        AttendanceDeps $attendance,
        PaymentDeps $payments,
        MaterialsDeps $materials,
    ): void {
        $bot->onCallbackQuery(function (Nutgram $bot) use ($texts, $config, $users, $registrations, $access, $blocks, $schedule, $attendance, $payments, $materials): void {
            $callback = $bot->callbackQuery();
            if ($callback === null) {
                return;
            }

            $data = (string) ($callback->data ?? '');
            if ($data === '') {
                return;
            }

            $actorTelegramId = $callback->from?->id;
            if ($actorTelegramId === null) {
                return;
            }

            if (str_starts_with($data, 'snd:')) {
                if (SongsCallbackHandler::handle($bot, $materials, $materials->delivery, $data)) {
                    return;
                }

                return;
            }

            if (str_starts_with($data, 'mat:')) {
                $actorRow = $users->findByTelegramId($actorTelegramId);
                if ($actorRow !== null && MaterialsCallbackHandler::handle($bot, $materials, $access, $actorRow, $data)) {
                    return;
                }

                return;
            }

            if (str_starts_with($data, 'pay:')) {
                $actorRow = $users->findByTelegramId($actorTelegramId);
                if ($actorRow === null) {
                    return;
                }

                if (PaymentAdminHandler::handleCallback($bot, $payments, $access, $actorRow, $data)) {
                    return;
                }

                PaymentCallbackHandler::handle($bot, $payments, $access, $data, $actorTelegramId);

                return;
            }

            if (str_starts_with($data, 'atd:')) {
                $actorRow = $users->findByTelegramId($actorTelegramId);
                if ($actorRow !== null) {
                    AttendanceAdminHandler::handleCallback(
                        $bot,
                        $texts,
                        $access,
                        $attendance,
                        $actorRow,
                        $data,
                    );
                }

                return;
            }

            if (str_starts_with($data, 'att:')) {
                AttendanceCallbackHandler::handle($bot, $attendance, $data, $actorTelegramId);

                return;
            }

            if (str_starts_with($data, 'sch:')) {
                ScheduleAdminHandler::handleCallback(
                    $bot,
                    $texts,
                    $config,
                    $access,
                    $schedule->templates,
                    $schedule->rehearsals,
                    $schedule->flows,
                    $schedule->generator,
                    $schedule->broadcast,
                    $schedule->presenter,
                    $schedule->settings,
                    $users,
                    $data,
                    $actorTelegramId,
                );

                return;
            }

            if (str_starts_with($data, 'bl:')) {
                MembersAdminHandler::handleCallback($bot, $texts, $users, $access, $blocks, $data, $actorTelegramId);

                return;
            }

            $actorRow = $users->findByTelegramId($actorTelegramId);
            if (
                $actorRow !== null
                && ($actorRow['status'] ?? '') === UserStatus::BLOCKED
                && !str_starts_with($data, 'rr:')
            ) {
                $bot->answerCallbackQuery(text: $texts->get('registration.blocked'), show_alert: true);

                return;
            }

            if (str_starts_with($data, 'oc:')) {
                ConductorAdminHandler::handleCallback($bot, $texts, $users, $access, $data, $actorTelegramId);

                return;
            }

            if (str_starts_with($data, 'hp:')) {
                HelpersAdminHandler::handleCallback($bot, $texts, $users, $access, $data, $actorTelegramId);

                return;
            }

            if (str_starts_with($data, 'rp:')) {
                self::handleRegistrationPart($bot, $texts, $config, $users, $registrations, $data, $actorTelegramId);

                return;
            }

            if (str_starts_with($data, 'rr:')) {
                self::handleRegistrationReview($bot, $texts, $config, $users, $registrations, $access, $data, $actorTelegramId);

                return;
            }
        });
    }

    private static function handleRegistrationPart(
        Nutgram $bot,
        TextRepository $texts,
        Config $config,
        UserRepository $users,
        RegistrationRepository $registrations,
        string $data,
        int $actorTelegramId,
    ): void {
        $part = substr($data, 3);
        if (!VoicePart::isValid($part)) {
            $bot->answerCallbackQuery();

            return;
        }

        $user = $users->findByTelegramId($actorTelegramId);
        if ($user === null || ($user['status'] ?? '') !== UserStatus::PENDING) {
            $bot->answerCallbackQuery();

            return;
        }

        if (trim((string) ($user['display_name'] ?? '')) === '') {
            $bot->answerCallbackQuery(text: $texts->get('registration.welcome'), show_alert: true);

            return;
        }

        $users->updateVoicePart((int) $user['id'], $part);

        $existing = $registrations->findOpenByUserId((int) $user['id']);
        if ($existing === null) {
            $requestId = $registrations->create((int) $user['id']);
        } else {
            $requestId = (int) $existing['id'];
        }

        $bot->answerCallbackQuery();
        $bot->sendMessage($texts->get('registration.pending_user'));

        $adminChatId = trim($config->adminChatId);
        if ($adminChatId === '') {
            return;
        }

        $partLabel = $texts->get('parts.' . $part);
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                text: $texts->get('buttons.accept'),
                callback_data: 'rr:a:' . $requestId,
            ),
            InlineKeyboardButton::make(
                text: $texts->get('buttons.reject'),
                callback_data: 'rr:r:' . $requestId,
            ),
        );

        $message = $bot->sendMessage(
            text: $texts->get('registration.admin_new_request', [
                'name' => (string) $user['display_name'],
                'part' => $partLabel,
            ]),
            chat_id: (int) $adminChatId,
            reply_markup: $keyboard,
        );

        if ($message !== null) {
            $registrations->setAdminMessageId($requestId, $message->message_id);
        }
    }

    private static function handleRegistrationReview(
        Nutgram $bot,
        TextRepository $texts,
        Config $config,
        UserRepository $users,
        RegistrationRepository $registrations,
        AccessControl $access,
        string $data,
        int $actorTelegramId,
    ): void {
        if (!$access->isAdminChatId($bot->chatId() ?? '')) {
            $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

            return;
        }

        $from = $bot->callbackQuery()?->from;
        UserIdentitySync::syncPrivilegedTelegramUser(
            $actorTelegramId,
            trim($from?->first_name ?? ''),
            $access,
            $users,
        );

        $moderator = $users->findByTelegramId($actorTelegramId);
        if ($moderator === null || !$access->canModerateRegistration($moderator)) {
            $bot->answerCallbackQuery(text: $texts->get('registration.moderation_forbidden'), show_alert: true);

            return;
        }

        if (preg_match('/^rr:([ar]):(\d+)$/', $data, $matches) !== 1) {
            $bot->answerCallbackQuery();

            return;
        }

        $action = $matches[1];
        $requestId = (int) $matches[2];

        $request = $registrations->findById($requestId);
        if ($request === null || ($request['status'] ?? '') !== 'pending') {
            $bot->answerCallbackQuery(text: $texts->get('registration.request_already_handled'), show_alert: true);

            return;
        }

        $applicant = $users->findById((int) $request['user_id']);
        if ($applicant === null) {
            $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

            return;
        }

        $name = (string) $applicant['display_name'];
        $telegramId = (int) $applicant['telegram_id'];

        if ($action === 'a') {
            $registrations->resolve($requestId, 'accepted');
            $users->updateStatus((int) $applicant['id'], UserStatus::ACTIVE);
            $applicant = $users->findById((int) $applicant['id']) ?? $applicant;

            $bot->answerCallbackQuery();
            $bot->sendMessage(
                text: $texts->get('registration.accepted', ['name' => $name]),
                chat_id: $telegramId,
                reply_markup: MenuKeyboard::forUser($texts, $applicant),
            );

            $resultText = $texts->get('registration.admin_chat_result_accepted', ['name' => $name]);
        } else {
            $registrations->resolve($requestId, 'rejected');
            $users->updateStatus((int) $applicant['id'], UserStatus::REJECTED);

            $bot->answerCallbackQuery();
            $bot->sendMessage(
                text: $texts->get('registration.rejected'),
                chat_id: $telegramId,
            );

            $resultText = $texts->get('registration.admin_chat_result_rejected', ['name' => $name]);
        }

        $callbackMessage = $bot->callbackQuery()?->message;
        if ($callbackMessage !== null) {
            $bot->editMessageText(
                text: $resultText . "\n\n" . ($callbackMessage->text ?? ''),
                chat_id: $callbackMessage->chat->id,
                message_id: $callbackMessage->message_id,
            );
        }
    }
}
