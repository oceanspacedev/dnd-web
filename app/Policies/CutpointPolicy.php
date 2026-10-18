<?php

namespace App\Policies;

use App\Models\Cutpoint;
use App\Models\User;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use Illuminate\Database\Eloquent\Builder;

class CutpointPolicy
{
    /**
     * Super admin: ADMIN dengan akses seluruh badan usaha.
     */
    private function canManageAll(User $user): bool
    {
        return DataAccessService::hasGlobalAccess($user);
    }

    private function canManageScopedCutpoint(User $user): bool
    {
        return $user->id === 3;
    }

    private function isWithinManagedScope(User $user, int $targetUserId): bool
    {
        $managedIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id);

        return in_array($targetUserId, $managedIds, true);
    }

    /**
     * Admin per badan usaha melihat cutpoint milik karyawan di badan usaha yang diizinkan, tim approval,
     * atau dirinya sendiri.
     */
    private function isVisibleToAreaScopedAdmin(User $user, Cutpoint $cutpoint): bool
    {
        return DataAccessService::canSeeUser($user, self::ownerOf($cutpoint));
    }

    /**
     * Admin per badan usaha mengelola cutpoint karyawan dalam cakupannya, tanpa akun ADMIN (termasuk dirinya).
     * Berlaku juga untuk new Cutpoint(['user_id' => X]) saat memvalidasi target create.
     */
    private function isWritableByAreaScopedAdmin(User $user, Cutpoint $cutpoint): bool
    {
        return self::canAreaScopedAdminWriteFor($user, self::ownerOf($cutpoint));
    }

    /**
     * Admin per badan usaha boleh menulis cutpoint karyawan dalam cakupannya (badan usaha, tim approval),
     * tetapi tidak untuk akun ADMIN, termasuk dirinya sendiri: akun ADMIN hanya dikelola super admin
     * (sama dengan UserPolicy), dan potongan poin sendiri tidak boleh diubah/dihapus sendiri.
     */
    public static function canAreaScopedAdminWriteFor(User $user, User|int|null $owner): bool
    {
        if (! DataAccessService::canSeeUser($user, $owner)) {
            return false;
        }

        if ($owner instanceof User) {
            return ! $owner->isAdmin();
        }

        return ! User::withTrashed()
            ->whereKey($owner)
            ->whereHas('role', fn (Builder $role) => $role->where('name', User::ROLE_ADMIN))
            ->exists();
    }

    /**
     * Pemilik cutpoint untuk cek cakupan. Pakai relasi user yang sudah dimuat (withTrashed, dimuat tabel)
     * agar tidak ada query per baris; selain itu user_id.
     */
    private static function ownerOf(Cutpoint $cutpoint): User|int|null
    {
        if (
            $cutpoint->relationLoaded('user')
            && $cutpoint->user instanceof User
            && (int) $cutpoint->user->getKey() === (int) $cutpoint->user_id
        ) {
            return $cutpoint->user;
        }

        return $cutpoint->user_id === null ? null : (int) $cutpoint->user_id;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Super admin melihat semua, admin per badan usaha melihat cakupannya,
        // non-admin hanya melihat data sendiri (filter di query/resource)
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Cutpoint $cutpoint): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        if (DataAccessService::isAreaScopedAdmin($user)) {
            return $this->isVisibleToAreaScopedAdmin($user, $cutpoint);
        }

        if ($this->canManageScopedCutpoint($user)) {
            return $this->isWithinManagedScope($user, (int) $cutpoint->user_id);
        }

        return (int) $cutpoint->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        // Target tetap divalidasi per karyawan (update pada new Cutpoint(['user_id' => X]) / rule form).
        if (DataAccessService::isAreaScopedAdmin($user)) {
            return DataAccessService::canAccessEmployees($user)
                || ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id) !== [];
        }

        if (! $this->canManageScopedCutpoint($user)) {
            return false;
        }

        return ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id) !== [];
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Cutpoint $cutpoint): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        if (DataAccessService::isAreaScopedAdmin($user)) {
            return $this->isWritableByAreaScopedAdmin($user, $cutpoint);
        }

        if (! $this->canManageScopedCutpoint($user)) {
            return false;
        }

        return $this->isWithinManagedScope($user, (int) $cutpoint->user_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Cutpoint $cutpoint): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        if (DataAccessService::isAreaScopedAdmin($user)) {
            return $this->isWritableByAreaScopedAdmin($user, $cutpoint);
        }

        if (! $this->canManageScopedCutpoint($user)) {
            return false;
        }

        return $this->isWithinManagedScope($user, (int) $cutpoint->user_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Cutpoint $cutpoint): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        return DataAccessService::isAreaScopedAdmin($user) && $this->isWritableByAreaScopedAdmin($user, $cutpoint);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Cutpoint $cutpoint): bool
    {
        if ($this->canManageAll($user)) {
            return true;
        }

        return DataAccessService::isAreaScopedAdmin($user) && $this->isWritableByAreaScopedAdmin($user, $cutpoint);
    }
}
