<?php

namespace App\Policies;

use App\Models\ApprovalRule;
use App\Models\User;
use App\Services\DataAccessService;

/**
 * Aturan approval adalah master data lintas badan usaha: hanya super admin
 * (ADMIN dengan akses seluruh badan usaha) yang boleh melihat dan mengelolanya.
 * Aturan tanpa area berlaku untuk seluruh badan usaha.
 */
class ApprovalRulePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ApprovalRule $approvalRule): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ApprovalRule $approvalRule): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ApprovalRule $approvalRule): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can bulk delete models.
     */
    public function deleteAny(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ApprovalRule $approvalRule): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can bulk restore models.
     */
    public function restoreAny(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ApprovalRule $approvalRule): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can bulk permanently delete models.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return DataAccessService::hasGlobalAccess($user);
    }
}
