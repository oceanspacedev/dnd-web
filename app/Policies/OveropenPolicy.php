<?php

namespace App\Policies;

use App\Models\Overopen;
use App\Models\User;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;

class OveropenPolicy
{
    /**
     * Super admin: ADMIN dengan akses seluruh badan usaha.
     */
    private function canManageAll(User $user): bool
    {
        return DataAccessService::hasGlobalAccess($user);
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

    private function canAccessRecord(User $user, Overopen $overopen, bool $write = false): bool
    {
        if ($this->canManageAll($user) || (int) $overopen->user_id === (int) $user->id) {
            return true;
        }

        // Admin per badan usaha: pemilik data di badan usaha yang diizinkan atau di tim approval-nya.
        // Model bisa belum tersimpan (cek target saat create), jadi badan usaha dibaca dari user_id.
        if (DataAccessService::isAdmin($user)) {
            // Mengubah data akun ADMIN lain hanya untuk super admin.
            return $write
                ? DataAccessService::canWriteForUser($user, $overopen->user_id)
                : DataAccessService::canSeeUser($user, $overopen->user_id);
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
        return $this->canAccessRecord($user, $overopen, write: true);
    }

    public function delete(User $user, Overopen $overopen): bool
    {
        return $this->canAccessRecord($user, $overopen, write: true);
    }
}
