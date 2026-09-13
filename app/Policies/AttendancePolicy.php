<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;
use App\Services\ApprovalScopeService;

class AttendancePolicy
{
    private const GLOBAL_ATTENDANCE_USERNAMES = ['darkini'];

    private function canManageAll(User $user): bool
    {
        if ($user->role?->name === 'ADMIN') {
            return true;
        }

        return in_array(strtolower((string) $user->username), self::GLOBAL_ATTENDANCE_USERNAMES, true);
    }

    private function canManageScoped(User $user): bool
    {
        return in_array($user->role?->name, ['MANAGER', 'COORDINATOR'], true);
    }

    /**
     * @return array<int>
     */
    private function getManagedUserIds(User $user): array
    {
        return ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id);
    }

    private function canAccessRecord(User $user, Attendance $attendance): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        if (! $this->canManageScoped($user)) {
            return false;
        }

        return in_array((int) $attendance->user_id, $this->getManagedUserIds($user), true);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canManageAll($user) || $this->canManageScoped($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Attendance $attendance): bool
    {
        return $this->canAccessRecord($user, $attendance);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        if (! $this->canManageScoped($user)) {
            return false;
        }

        return $this->getManagedUserIds($user) !== [];
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Attendance $attendance): bool
    {
        return $this->canAccessRecord($user, $attendance);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Attendance $attendance): bool
    {
        return $this->canAccessRecord($user, $attendance);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Attendance $attendance): bool
    {
        return $this->canAccessRecord($user, $attendance);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Attendance $attendance): bool
    {
        return $this->canAccessRecord($user, $attendance);
    }
}
