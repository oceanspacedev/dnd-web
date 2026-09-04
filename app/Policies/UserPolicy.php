<?php

namespace App\Policies;

use App\Models\User;
use App\Services\ApprovalScopeService;

class UserPolicy
{
    private function canManageAll(User $user): bool
    {
        return $user->role?->name === 'ADMIN';
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

    private function canAccessRecord(User $user, User $model): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        if ($model->role?->name === 'ADMIN') {
            return false;
        }

        if (! $this->canManageScoped($user)) {
            return false;
        }

        return in_array((int) $model->id, $this->getManagedUserIds($user), true);
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
    public function view(User $user, User $model): bool
    {
        return $this->canAccessRecord($user, $model);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canManageAll($user) || $this->canManageScoped($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $this->canAccessRecord($user, $model);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return $this->canAccessRecord($user, $model);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return $this->canAccessRecord($user, $model);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return $this->canAccessRecord($user, $model);
    }
}
