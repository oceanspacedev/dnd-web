<?php

namespace App\Policies;

use App\Models\User;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;

class UserPolicy
{
    private function canManageAll(User $user): bool
    {
        return DataAccessService::hasGlobalAccess($user);
    }

    /**
     * Menu Karyawan: admin dengan akses badan usaha (seluruh atau sebagian).
     */
    private function canManageEmployees(User $user): bool
    {
        return DataAccessService::canAccessEmployees($user);
    }

    /**
     * Menu Tim Saya: atasan berdasarkan approval line.
     */
    public function canManageTeam(User $user): bool
    {
        return DataAccessService::isTeamSupervisor($user);
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

        // Akun ADMIN hanya dikelola admin dengan akses seluruh badan usaha.
        if ($model->role?->name === User::ROLE_ADMIN) {
            return false;
        }

        if (DataAccessService::isEmployeeInScope($user, $model)) {
            return true;
        }

        if (! $this->canManageTeam($user)) {
            return false;
        }

        return in_array((int) $model->id, $this->getManagedUserIds($user), true);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canManageEmployees($user) || $this->canManageTeam($user);
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
        return $this->canManageEmployees($user) || $this->canManageTeam($user);
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

    /**
     * Only admins with access to every badan usaha may grant the ADMIN role or change data access.
     */
    public function manageDataAccess(User $user): bool
    {
        return $this->canManageAll($user);
    }

    /**
     * Mengubah data login (username, password, No. HP WhatsApp) sama dengan bisa masuk sebagai karyawan itu,
     * jadi selain super admin hanya boleh bila akses akun tersebut tidak melampaui cakupan actor:
     * - user khusus kehadiran seluruh badan usaha (AttendancePolicy) hanya dikelola super admin;
     * - admin badan usaha tertentu tidak boleh mengubah login atasan yang timnya berisi karyawan di luar cakupannya.
     */
    public function changeCredentials(User $user, User $model): bool
    {
        if (! $this->update($user, $model)) {
            return false;
        }

        if ($this->canManageAll($user)) {
            return true;
        }

        if (AttendancePolicy::isGlobalAttendanceUsername($model->getOriginal('username'))) {
            return false;
        }

        return ! DataAccessService::isAreaScopedAdmin($user) || DataAccessService::coversTeamOf($user, $model);
    }

    /**
     * Username user khusus kehadiran memberi akses seluruh badan usaha, jadi hanya super admin yang boleh memakainya.
     */
    public function useGlobalAttendanceUsername(User $user): bool
    {
        return $this->canManageAll($user);
    }
}
