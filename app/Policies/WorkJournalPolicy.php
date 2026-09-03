<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkJournal;
use App\Services\ApprovalScopeService;

class WorkJournalPolicy
{
    private function canManageAll(User $user): bool
    {
        return in_array($user->role?->name, ['ADMIN'], true);
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
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, WorkJournal $workJournal): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        if ((int) $workJournal->user_id === (int) $user->id) {
            return true;
        }

        return $this->isWithinManagedScope($user, (int) $workJournal->user_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkJournal $workJournal): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        return (int) $workJournal->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkJournal $workJournal): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        return (int) $workJournal->user_id === (int) $user->id;
    }
}
