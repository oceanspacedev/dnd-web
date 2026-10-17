<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class AttendancePolicy
{
    private const GLOBAL_ATTENDANCE_USERNAMES = ['darkini'];

    /**
     * Seluruh data kehadiran: super admin (akses seluruh badan usaha) atau user khusus kehadiran.
     */
    public static function canManageAllAttendance(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (DataAccessService::hasGlobalAccess($user)) {
            return true;
        }

        return in_array(strtolower((string) $user->username), self::GLOBAL_ATTENDANCE_USERNAMES, true);
    }

    /**
     * Batasi query ke data kehadiran yang terlihat oleh actor (sama dengan daftar Kehadiran):
     * - seluruh data   => tanpa filter;
     * - admin per badan usaha => badan usaha yang diizinkan, tim approval, dan dirinya sendiri;
     * - atasan         => bawahan approval (langsung + satu level).
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
        if (self::canManageAllAttendance($actor)) {
            return $query;
        }

        if (! $actor) {
            return $query->whereRaw('1 = 0');
        }

        $column ??= $query instanceof Builder ? $query->qualifyColumn('user_id') : 'user_id';

        if (DataAccessService::isAreaScopedAdmin($actor)) {
            return DataAccessService::scopeOwnedRecords($query, $actor, $column);
        }

        return $query->whereIn($column, ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $actor->id));
    }

    /**
     * Id karyawan dalam cakupan actor untuk import/export. null = seluruh karyawan.
     *
     * @return array<int>|null
     */
    public static function visibleUserIds(?User $actor): ?array
    {
        if (self::canManageAllAttendance($actor)) {
            return null;
        }

        if (! $actor) {
            return [];
        }

        if (DataAccessService::isAreaScopedAdmin($actor)) {
            return DataAccessService::visibleUserIds($actor) ?? [];
        }

        return ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $actor->id);
    }

    /**
     * Id karyawan yang boleh dicatat kehadirannya oleh actor (template & import). null = seluruh karyawan.
     * Sama dengan visibleUserIds, kecuali admin per badan usaha tidak mendapat akun ADMIN (termasuk dirinya).
     *
     * @return array<int>|null
     */
    public static function writableUserIds(?User $actor): ?array
    {
        $userIds = self::visibleUserIds($actor);

        if ($userIds === null || $userIds === [] || ! DataAccessService::isAreaScopedAdmin($actor)) {
            return $userIds;
        }

        $adminIds = User::withTrashed()
            ->whereIn('id', $userIds)
            ->whereHas('role', fn (Builder $role) => $role->where('name', User::ROLE_ADMIN))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_values(array_diff($userIds, $adminIds));
    }

    /**
     * Batasi query users (opsi karyawan di form) ke karyawan yang boleh dicatat kehadirannya oleh actor.
     *
     * @param  Builder<User>  $usersQuery
     * @return Builder<User>
     */
    public static function scopeWritableUsers(Builder $usersQuery, ?User $actor): Builder
    {
        self::scopeVisible($usersQuery, $actor, $usersQuery->qualifyColumn('id'));

        if (! self::canManageAllAttendance($actor) && DataAccessService::isAreaScopedAdmin($actor)) {
            // Akun ADMIN (termasuk dirinya sendiri) hanya dikelola super admin.
            $usersQuery->whereDoesntHave('role', fn (Builder $role) => $role->where('name', User::ROLE_ADMIN));
        }

        return $usersQuery;
    }

    /**
     * Validasi server: karyawan target boleh dicatat kehadirannya oleh actor.
     */
    public static function canManageUser(?User $actor, int|string|null $targetUserId): bool
    {
        if (! $actor || $targetUserId === null || $targetUserId === '') {
            return false;
        }

        if (self::canManageAllAttendance($actor)) {
            return true;
        }

        if (DataAccessService::isAreaScopedAdmin($actor)) {
            $targetUserId = filter_var($targetUserId, FILTER_VALIDATE_INT);

            return $targetUserId !== false && self::canWriteAsAreaScopedAdmin($actor, $targetUserId);
        }

        return in_array((int) $targetUserId, ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $actor->id), true);
    }

    /**
     * Admin per badan usaha boleh mencatat/mengubah kehadiran karyawan dalam cakupannya (badan usaha,
     * tim approval), tetapi tidak untuk akun ADMIN, termasuk dirinya sendiri: akun ADMIN hanya dikelola
     * super admin (sama dengan UserPolicy), dan data sendiri tidak boleh diubah sendiri.
     */
    private static function canWriteAsAreaScopedAdmin(User $actor, User|int|null $owner): bool
    {
        if (! DataAccessService::canSeeUser($actor, $owner)) {
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
     * Pemilik record untuk cek cakupan. Pakai relasi user yang sudah dimuat (tabel memuatnya) agar tidak
     * ada query per baris; selain itu user_id (relasi user tanpa withTrashed: pemilik yang dihapus = null).
     */
    private static function ownerOf(Attendance $attendance): User|int|null
    {
        if (
            $attendance->relationLoaded('user')
            && $attendance->user instanceof User
            && (int) $attendance->user->getKey() === (int) $attendance->user_id
        ) {
            return $attendance->user;
        }

        return $attendance->user_id === null ? null : (int) $attendance->user_id;
    }

    /**
     * Atasan approval line (semua role atasan) mengelola data anggota timnya.
     */
    private function canManageScoped(User $user): bool
    {
        return DataAccessService::isTeamSupervisor($user);
    }

    /**
     * Admin per badan usaha yang punya karyawan untuk dikelola (badan usaha atau tim approval).
     */
    private function isAreaScopedAdminWithReach(User $user): bool
    {
        return DataAccessService::isAreaScopedAdmin($user)
            && (DataAccessService::canAccessEmployees($user) || $this->getManagedUserIds($user) !== []);
    }

    /**
     * @return array<int>
     */
    private function getManagedUserIds(User $user): array
    {
        return ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id);
    }

    private function canAccessRecord(User $user, Attendance $attendance, bool $write = false): bool
    {
        if (self::canManageAllAttendance($user)) {
            return true;
        }

        // Admin per badan usaha: lihat pemilik di badan usaha yang diizinkan, tim approval, atau diri sendiri;
        // tulis tanpa akun ADMIN (termasuk dirinya). update pada new Attendance(['user_id' => X]) dipakai
        // untuk memvalidasi target create.
        if (DataAccessService::isAreaScopedAdmin($user)) {
            return $write
                ? self::canWriteAsAreaScopedAdmin($user, self::ownerOf($attendance))
                : DataAccessService::canSeeUser($user, self::ownerOf($attendance));
        }

        if (! $this->canManageScoped($user)) {
            return false;
        }

        return in_array((int) $attendance->user_id, $this->getManagedUserIds($user), true);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return self::canManageAllAttendance($user)
            || $this->isAreaScopedAdminWithReach($user)
            || $this->canManageScoped($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Attendance $attendance): bool
    {
        return $this->canAccessRecord($user, $attendance);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        if (self::canManageAllAttendance($user)) {
            return true;
        }

        // Target tetap divalidasi per karyawan (update pada new Attendance(['user_id' => X]) / canManageUser).
        if (DataAccessService::isAreaScopedAdmin($user)) {
            return $this->isAreaScopedAdminWithReach($user);
        }

        if (! $this->canManageScoped($user)) {
            return false;
        }

        return $this->getManagedUserIds($user) !== [];
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Attendance $attendance): bool
    {
        return $this->canAccessRecord($user, $attendance, write: true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Attendance $attendance): bool
    {
        return $this->canAccessRecord($user, $attendance, write: true);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Attendance $attendance): bool
    {
        return $this->canAccessRecord($user, $attendance, write: true);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Attendance $attendance): bool
    {
        return $this->canAccessRecord($user, $attendance, write: true);
    }
}
