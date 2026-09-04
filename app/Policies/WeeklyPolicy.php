<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Weekly;
use App\Services\ApprovalScopeService;

class WeeklyPolicy
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

    private function canAccessRecord(User $user, Weekly $weekly): bool
    {
        if ($this->canManageAll($user) || (int) $weekly->user_id === (int) $user->id) {
            return true;
        }

        if (! $this->canManageScoped($user)) {
            return false;
        }

        return in_array(
            (int) $weekly->user_id,
            ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id),
            true,
        );
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Weekly $weekly): bool
    {
        return $this->canAccessRecord($user, $weekly);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Weekly $weekly): bool
    {
        return $this->canAccessRecord($user, $weekly);
    }

    public function delete(User $user, Weekly $weekly): bool
    {
        return $this->canAccessRecord($user, $weekly);
    }
}
