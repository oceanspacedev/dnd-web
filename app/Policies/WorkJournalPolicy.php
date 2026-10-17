<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkJournal;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class WorkJournalPolicy
{
    /**
     * Super admin: ADMIN dengan akses seluruh badan usaha.
     */
    private function canManageAll(User $user): bool
    {
        return DataAccessService::hasGlobalAccess($user);
    }

    private function isWithinManagedScope(User $user, int $targetUserId): bool
    {
        $managedIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id);

        return in_array($targetUserId, $managedIds, true);
    }

    /**
     * Batasi query ke jurnal yang terlihat oleh actor (sama dengan daftar Jurnal Harian):
     * - super admin           => tanpa filter;
     * - admin per badan usaha => badan usaha yang diizinkan, tim approval, dan dirinya sendiri;
     * - lainnya               => diri sendiri + bawahan approval (langsung + satu level).
     *
     * $column default ke user_id milik query; untuk query tabel users pakai 'users.id'.
     *
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function scopeVisible(Builder|QueryBuilder $query, ?User $actor, ?string $column = null): Builder|QueryBuilder
    {
        if (DataAccessService::hasGlobalAccess($actor)) {
            return $query;
        }

        if (! $actor) {
            return $query->whereRaw('1 = 0');
        }

        $column ??= $query instanceof Builder ? $query->qualifyColumn('user_id') : 'user_id';

        if (DataAccessService::isAreaScopedAdmin($actor)) {
            return DataAccessService::scopeOwnedRecords($query, $actor, $column);
        }

        $visibleUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $actor->id);
        $visibleUserIds[] = (int) $actor->id;

        return $query->whereIn($column, array_values(array_unique($visibleUserIds)));
    }

    /**
     * Actor boleh menulis (membuat/mengubah/menghapus) jurnal milik $owner:
     * - super admin           => jurnal siapa pun;
     * - semua user            => jurnal miliknya sendiri;
     * - admin per badan usaha => jurnal karyawan dalam cakupannya (badan usaha, tim approval), kecuali
     *                            akun ADMIN lain: akun ADMIN hanya dikelola super admin (sama dengan UserPolicy).
     */
    public static function canWriteFor(?User $actor, User|int|string|null $owner): bool
    {
        if (! $actor instanceof User || $owner === null || $owner === '') {
            return false;
        }

        if (DataAccessService::hasGlobalAccess($actor)) {
            return true;
        }

        $ownerId = $owner instanceof User ? (int) $owner->getKey() : filter_var($owner, FILTER_VALIDATE_INT);

        if ($ownerId === false) {
            return false;
        }

        if ($ownerId === (int) $actor->id) {
            return true;
        }

        // Non-admin hanya menulis jurnalnya sendiri.
        if (! DataAccessService::isAreaScopedAdmin($actor)) {
            return false;
        }

        if ($owner instanceof User) {
            return DataAccessService::canSeeUser($actor, $owner) && ! $owner->isAdmin();
        }

        if (! DataAccessService::canSeeUser($actor, $ownerId)) {
            return false;
        }

        return ! User::withTrashed()
            ->whereKey($ownerId)
            ->whereHas('role', fn (Builder $role) => $role->where('name', User::ROLE_ADMIN))
            ->exists();
    }

    /**
     * Pemilik jurnal untuk cek cakupan. Pakai relasi user yang sudah dimuat (withTrashed, dimuat tabel)
     * agar tidak ada query per baris; selain itu user_id.
     */
    private static function ownerOf(WorkJournal $workJournal): User|int|null
    {
        if (
            $workJournal->relationLoaded('user')
            && $workJournal->user instanceof User
            && (int) $workJournal->user->getKey() === (int) $workJournal->user_id
        ) {
            return $workJournal->user;
        }

        return $workJournal->user_id === null ? null : (int) $workJournal->user_id;
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

        // Admin per badan usaha: pemilik jurnal di badan usaha yang diizinkan atau tim approval-nya.
        if (DataAccessService::isAreaScopedAdmin($user)) {
            return DataAccessService::canSeeUser($user, self::ownerOf($workJournal));
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
        return self::canWriteFor($user, self::ownerOf($workJournal));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkJournal $workJournal): bool
    {
        return self::canWriteFor($user, self::ownerOf($workJournal));
    }
}
