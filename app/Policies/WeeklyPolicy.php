<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Weekly;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;

class WeeklyPolicy
{
    /**
     * Super admin: ADMIN dengan akses seluruh badan usaha.
     */
    private function canManageAll(User $user): bool
    {
        return DataAccessService::hasGlobalAccess($user);
    }

    /**
     * Atasan approval line (semua role atasan) mengelola data anggota timnya.
     */
    private function canManageScoped(User $user): bool
    {
        return DataAccessService::isTeamSupervisor($user);
    }

    private function canAccessRecord(User $user, Weekly $weekly, bool $write = false): bool
    {
        if ($this->canManageAll($user) || (int) $weekly->user_id === (int) $user->id) {
            return true;
        }

        // Admin per badan usaha: pemilik data di badan usaha yang diizinkan atau di tim approval-nya.
        // Model bisa belum tersimpan (cek target saat create), jadi badan usaha dibaca dari user_id.
        if (DataAccessService::isAdmin($user)) {
            // Mengubah data akun ADMIN lain hanya untuk super admin.
            return $write
                ? DataAccessService::canWriteForUser($user, $weekly->user_id)
                : DataAccessService::canSeeUser($user, $weekly->user_id);
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
        return $this->canAccessRecord($user, $weekly, write: true);
    }

    public function delete(User $user, Weekly $weekly): bool
    {
        return $this->canAccessRecord($user, $weekly, write: true);
    }
}
