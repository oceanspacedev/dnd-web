<?php

namespace App\Policies;

use App\Models\Cutpoint;
use App\Models\User;
use App\Services\ApprovalScopeService;

class CutpointPolicy
{
    private function canManageAll(User $user): bool
    {
        return in_array($user->role?->name, ['ADMIN'], true);
    }

    private function canManageScopedCutpoint(User $user): bool
    {
        return $user->id === 3;
    }

    private function isWithinManagedScope(User $user, int $targetUserId): bool
    {
        $managedIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id);

        return in_array($targetUserId, $managedIds, true);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // ADMIN bisa lihat semua, non-ADMIN hanya bisa lihat data sendiri (filter di query/resource)
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Cutpoint $cutpoint): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        if ($this->canManageScopedCutpoint($user)) {
            return $this->isWithinManagedScope($user, (int) $cutpoint->user_id);
        }

        return (int) $cutpoint->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        if (! $this->canManageScopedCutpoint($user)) {
            return false;
        }

        return ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id) !== [];
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Cutpoint $cutpoint): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        if (! $this->canManageScopedCutpoint($user)) {
            return false;
        }

        return $this->isWithinManagedScope($user, (int) $cutpoint->user_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Cutpoint $cutpoint): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        if (! $this->canManageScopedCutpoint($user)) {
            return false;
        }

        return $this->isWithinManagedScope($user, (int) $cutpoint->user_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Cutpoint $cutpoint): bool
    {
        return in_array($user->role?->name, ['ADMIN']);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Cutpoint $cutpoint): bool
    {
        return in_array($user->role?->name, ['ADMIN']);
    }
}
