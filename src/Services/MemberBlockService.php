<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\BlockEventAction;
use App\Domain\BlockEventReason;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Repositories\BlockEventRepository;
use App\Repositories\UserRepository;

final class MemberBlockService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly BlockEventRepository $blockEvents,
    ) {
    }

    /**
     * @param array<string, mixed> $target
     */
    public function canToggleBlock(array $target): bool
    {
        $role = (string) ($target['role'] ?? '');
        if (in_array($role, [UserRole::OWNER, UserRole::CONDUCTOR], true)) {
            return false;
        }

        $status = (string) ($target['status'] ?? '');

        return $status === UserStatus::ACTIVE || $status === UserStatus::BLOCKED;
    }

    public function blockManual(int $actorUserId, int $targetUserId): void
    {
        $target = $this->users->findById($targetUserId);
        if ($target === null || !$this->canToggleBlock($target)) {
            throw new \InvalidArgumentException('Cannot block user');
        }

        if (($target['status'] ?? '') !== UserStatus::ACTIVE) {
            throw new \InvalidArgumentException('User is not active');
        }

        $this->users->updateStatus($targetUserId, UserStatus::BLOCKED);
        $this->blockEvents->record($targetUserId, $actorUserId, BlockEventAction::BLOCK, BlockEventReason::MANUAL);
    }

    public function unblockManual(int $actorUserId, int $targetUserId): void
    {
        $target = $this->users->findById($targetUserId);
        if ($target === null || !$this->canToggleBlock($target)) {
            throw new \InvalidArgumentException('Cannot unblock user');
        }

        if (($target['status'] ?? '') !== UserStatus::BLOCKED) {
            throw new \InvalidArgumentException('User is not blocked');
        }

        $this->users->updateStatus($targetUserId, UserStatus::ACTIVE);
        $this->users->resetConsecutiveUnexcused($targetUserId);
        $this->blockEvents->record($targetUserId, $actorUserId, BlockEventAction::UNBLOCK, BlockEventReason::MANUAL);
    }

    /**
     * Автоблокировка (4-й пропуск подряд и т.п.); actor_user_id = null.
     */
    public function blockAuto(int $targetUserId): void
    {
        $target = $this->users->findById($targetUserId);
        if ($target === null || !$this->canToggleBlock($target)) {
            throw new \InvalidArgumentException('Cannot auto-block user');
        }

        if (($target['status'] ?? '') === UserStatus::BLOCKED) {
            return;
        }

        $this->users->updateStatus($targetUserId, UserStatus::BLOCKED);
        $this->blockEvents->record($targetUserId, null, BlockEventAction::BLOCK, BlockEventReason::AUTO);
    }
}
