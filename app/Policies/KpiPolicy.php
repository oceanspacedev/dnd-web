<?php

namespace App\Policies;

use App\Models\Kpi;
use App\Models\User;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;

class KpiPolicy
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
        return in_array($user->role?->name, ['MANAGER', 'COORDINATOR'], true);
    }

    /**
     * @return array<int>
     */
    private function getManagedUserIds(User $user): array
    {
        return ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id);
    }

    private function canAccessRecord(User $user, Kpi $kpi, bool $write = false): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        if ((int) $kpi->user_id === (int) $user->id) {
            return true;
        }

        // Admin badan usaha tertentu: pemilik KPI harus berada di badan usaha yang diizinkan,
        // tim (approval line), atau dirinya sendiri. Berlaku juga untuk new Kpi(['user_id' => X]).
        // Mengubah data akun ADMIN lain hanya untuk super admin.
        if (DataAccessService::isAreaScopedAdmin($user)) {
            return $write
                ? DataAccessService::canWriteForUser($user, $kpi->ownerForAccessCheck())
                : DataAccessService::canSeeUser($user, $kpi->ownerForAccessCheck());
        }

        if (! $this->canManageScoped($user)) {
            return false;
        }

        return in_array((int) $kpi->user_id, $this->getManagedUserIds($user), true);
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
    public function view(User $user, Kpi $kpi): bool
    {
        return $this->canAccessRecord($user, $kpi);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        // Target KPI tetap divalidasi per karyawan (view pada new Kpi(['user_id' => X])).
        if (DataAccessService::isAreaScopedAdmin($user)) {
            return DataAccessService::canAccessEmployees($user) || $this->getManagedUserIds($user) !== [];
        }

        if (! $this->canManageScoped($user)) {
            return false;
        }

        return $this->getManagedUserIds($user) !== [];
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Kpi $kpi): bool
    {
        return $this->canAccessRecord($user, $kpi, write: true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Kpi $kpi): bool
    {
        return $this->canAccessRecord($user, $kpi, write: true);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Kpi $kpi): bool
    {
        return $this->canAccessRecord($user, $kpi, write: true);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Kpi $kpi): bool
    {
        return $this->canAccessRecord($user, $kpi, write: true);
    }
}
