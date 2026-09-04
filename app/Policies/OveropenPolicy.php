<?php

namespace App\Policies;

use App\Models\Overopen;
use App\Models\User;
use App\Services\ApprovalScopeService;

class OveropenPolicy
{
    private function canManageAll(User $user): bool
    {
        return $user->role?->name === 'ADMIN';
    }

    private function canManageScoped(User $user): bool
    {
        return in_array($user->role?->name, [
            'TEAM LEADER',
            'COORDINATOR',
            'MANAGER',
            'CHIEF',
            'BOD',
        ], true);
    }

    private function canAccessRecord(User $user, Overopen $overopen): bool
    {
        if ($this->canManageAll($user) || (int) $overopen->user_id === (int) $user->id) {
            return true;
        }

        if (! $this->canManageScoped($user)) {
            return false;
        }

        return in_array(
            (int) $overopen->user_id,
            ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id),
            true,
        );
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Overopen $overopen): bool
    {
        return $this->canAccessRecord($user, $overopen);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Overopen $overopen): bool
    {
        return $this->canAccessRecord($user, $overopen);
    }

    public function delete(User $user, Overopen $overopen): bool
    {
        return $this->canAccessRecord($user, $overopen);
    }
}
