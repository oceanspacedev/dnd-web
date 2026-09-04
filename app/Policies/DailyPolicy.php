<?php

namespace App\Policies;

use App\Models\Daily;
use App\Models\User;
use App\Services\ApprovalScopeService;

class DailyPolicy
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

    private function canAccessRecord(User $user, Daily $daily): bool
    {
        if ($this->canManageAll($user) || (int) $daily->user_id === (int) $user->id) {
            return true;
        }

        if (! $this->canManageScoped($user)) {
            return false;
        }

        return in_array(
            (int) $daily->user_id,
            ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id),
            true,
        );
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Daily $daily): bool
    {
        return $this->canAccessRecord($user, $daily);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Daily $daily): bool
    {
        return $this->canAccessRecord($user, $daily);
    }

    public function delete(User $user, Daily $daily): bool
    {
        return $this->canAccessRecord($user, $daily);
    }
}
