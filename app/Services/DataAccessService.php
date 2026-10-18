<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Akses data per badan usaha (tabel areas, kolom users.area_id).
 *
 * - ADMIN dengan data_access 'all'      => seluruh badan usaha (super admin).
 * - ADMIN dengan data_access 'selected' => hanya badan usaha di user_area_access,
 *                                          ditambah tim (approval line) dan dirinya sendiri.
 * - Non-admin                           => tidak punya akses badan usaha; modul memakai
 *                                          aturan tim (ApprovalScopeService) masing-masing.
 *
 * Pakai service ini, bukan cek nama role 'ADMIN' / role_id 1, setiap kali menentukan
 * data karyawan mana yang terlihat. Memo per request lewat scoped binding (aman untuk Octane).
 */
class DataAccessService
{
    /** Role atasan yang mengelola tim lewat approval line (menu Tim Saya). */
    public const SUPERVISOR_ROLES = ['TEAM LEADER', 'COORDINATOR', 'MANAGER', 'CHIEF', 'BOD'];

    /** @var array<int, array<int>> */
    private array $areaMemo = [];

    /** @var array<int, int|null> */
    private array $userAreaMemo = [];

    /** @var array<int, bool> */
    private array $userAdminMemo = [];

    /**
     * Clear the per-request memo (useful for testing or after changing data access).
     */
    public static function clearMemo(): void
    {
        $instance = resolve(self::class);
        $instance->areaMemo = [];
        $instance->userAreaMemo = [];
        $instance->userAdminMemo = [];
    }

    public static function isAdmin(?User $user): bool
    {
        return $user?->isAdmin() ?? false;
    }

    /**
     * Super admin: ADMIN dengan akses seluruh badan usaha.
     */
    public static function hasGlobalAccess(?User $user): bool
    {
        return self::isAdmin($user) && $user->data_access !== User::DATA_ACCESS_SELECTED;
    }

    /**
     * Ada super admin lain selain $user. Minimal satu super admin harus tersisa: hanya super admin yang bisa memberi
     * role ADMIN / akses seluruh badan usaha dan membuka pengaturan penilaian. Dipakai form Karyawan, API, dan import
     * Excel sebelum role ADMIN super admin dilepas.
     */
    public static function anotherGlobalAdminExists(User $user): bool
    {
        return User::query()
            ->whereKeyNot($user->getKey())
            ->whereHas('role', fn (Builder $roleQuery) => $roleQuery->where('name', User::ROLE_ADMIN))
            ->where(fn (Builder $access) => $access
                ->whereNull('data_access')
                ->orWhere('data_access', '!=', User::DATA_ACCESS_SELECTED))
            ->exists();
    }

    /**
     * ADMIN yang dibatasi ke badan usaha tertentu.
     */
    public static function isAreaScopedAdmin(?User $user): bool
    {
        return self::isAdmin($user) && $user->data_access === User::DATA_ACCESS_SELECTED;
    }

    /**
     * @return array<int>|null null = seluruh badan usaha; [] = tidak punya akses badan usaha.
     */
    public static function allowedAreaIds(?User $user): ?array
    {
        if (self::hasGlobalAccess($user)) {
            return null;
        }

        if (! self::isAreaScopedAdmin($user)) {
            return [];
        }

        return resolve(self::class)->selectedAreaIds((int) $user->getKey());
    }

    /**
     * Atasan approval line: role atasan, atau siapa pun yang punya bawahan. Atasan mengelola data
     * anggota timnya (ApprovalScopeService: bawahan langsung + satu level di bawahnya) di semua modul.
     */
    public static function isTeamSupervisor(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return in_array($user->role?->name, self::SUPERVISOR_ROLES, true)
            || ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->getKey()) !== [];
    }

    /**
     * Boleh membuka menu Karyawan (data karyawan per badan usaha).
     */
    public static function canAccessEmployees(?User $user): bool
    {
        return self::hasGlobalAccess($user) || self::allowedAreaIds($user) !== [];
    }

    public static function canAccessArea(?User $user, int|string|null $areaId): bool
    {
        $allowed = self::allowedAreaIds($user);

        if ($allowed === null) {
            return true;
        }

        if ($areaId === null || $areaId === '') {
            return false;
        }

        return in_array((int) $areaId, $allowed, true);
    }

    /**
     * Karyawan berada di badan usaha yang boleh diakses actor (tanpa memperhitungkan tim).
     */
    public static function isEmployeeInScope(?User $actor, User|int|string|null $target): bool
    {
        if ($target === null || $target === '') {
            return false;
        }

        $allowed = self::allowedAreaIds($actor);

        if ($allowed === null) {
            return true;
        }

        if ($allowed === []) {
            return false;
        }

        $areaId = $target instanceof User
            ? $target->area_id
            : resolve(self::class)->areaIdOfUser((int) $target);

        return $areaId !== null && in_array((int) $areaId, $allowed, true);
    }

    /**
     * Pemilik data terlihat oleh actor: badan usaha yang diizinkan, tim (approval line), atau diri sendiri.
     */
    public static function canSeeUser(?User $actor, User|int|string|null $target, bool $includeTeam = true, bool $includeSelf = true): bool
    {
        if (! $actor instanceof User || $target === null || $target === '') {
            return false;
        }

        $targetId = $target instanceof User ? (int) $target->getKey() : (int) $target;

        if ($includeSelf && $targetId === (int) $actor->getKey()) {
            return true;
        }

        if (self::isEmployeeInScope($actor, $target)) {
            return true;
        }

        return $includeTeam
            && in_array($targetId, ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $actor->getKey()), true);
    }

    /**
     * Boleh mengubah data milik karyawan: sama dengan canSeeUser, tetapi admin badan usaha tertentu
     * tidak boleh mengubah data milik akun ADMIN lain (akun ADMIN hanya dikelola super admin).
     * Data miliknya sendiri tetap mengikuti aturan modul masing-masing (includeSelf).
     */
    public static function canWriteForUser(?User $actor, User|int|string|null $target, bool $includeTeam = true, bool $includeSelf = true): bool
    {
        if (! self::canSeeUser($actor, $target, $includeTeam, $includeSelf)) {
            return false;
        }

        if (! self::isAreaScopedAdmin($actor)) {
            return true;
        }

        $targetId = $target instanceof User ? (int) $target->getKey() : (int) $target;

        if ($targetId === (int) $actor->getKey()) {
            return true;
        }

        return ! resolve(self::class)->userIsAdmin($target);
    }

    /**
     * Seluruh anggota tim approval target (bawahan langsung + satu level) terlihat oleh actor.
     * Bila tidak, akun target menjangkau karyawan di luar cakupan actor, jadi actor tidak boleh
     * mengambil alih akun tersebut (mis. mengubah data login-nya).
     */
    public static function coversTeamOf(?User $actor, User|int $target): bool
    {
        if (self::hasGlobalAccess($actor)) {
            return true;
        }

        $teamIds = ApprovalScopeService::getManagedUserIdsOneLevelDown($target instanceof User ? (int) $target->getKey() : $target);

        if ($teamIds === []) {
            return true;
        }

        if (! $actor instanceof User) {
            return false;
        }

        // Anggota tim target yang juga tim actor atau actor sendiri tetap terlihat; sisanya harus di badan usaha actor.
        $outsideActorTeam = array_diff(
            $teamIds,
            ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $actor->getKey()),
            [(int) $actor->getKey()],
        );

        if ($outsideActorTeam === []) {
            return true;
        }

        $allowedAreaIds = self::allowedAreaIds($actor);

        if ($allowedAreaIds === []) {
            return false;
        }

        return ! DB::table('users')
            ->whereIn('id', $outsideActorTeam)
            ->where(fn (QueryBuilder $area) => $area->whereNull('area_id')->orWhereNotIn('area_id', $allowedAreaIds))
            ->exists();
    }

    /**
     * Batasi query tabel users ke karyawan di badan usaha actor (menu Karyawan).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scopeEmployees(Builder $query, ?User $actor, ?string $areaColumn = null): Builder
    {
        $allowed = self::allowedAreaIds($actor);

        if ($allowed === null) {
            return $query;
        }

        if ($allowed === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($areaColumn ?? $query->qualifyColumn('area_id'), $allowed);
    }

    /**
     * Batasi query data milik karyawan (kolom user_id) ke pemilik yang terlihat oleh actor:
     * badan usaha yang diizinkan, tim (approval line), dan/atau diri sendiri.
     * Super admin tidak dibatasi.
     *
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function scopeOwnedRecords(Builder|QueryBuilder $query, ?User $actor, string $column = 'user_id', bool $includeTeam = true, bool $includeSelf = true): Builder|QueryBuilder
    {
        if (self::hasGlobalAccess($actor)) {
            return $query;
        }

        if (! $actor instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $allowedAreaIds = self::allowedAreaIds($actor);
        $teamIds = $includeTeam ? ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $actor->getKey()) : [];
        $actorId = $includeSelf ? (int) $actor->getKey() : null;

        if ($allowedAreaIds === [] && $teamIds === [] && $actorId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($scoped) use ($column, $allowedAreaIds, $teamIds, $actorId): void {
            if ($allowedAreaIds !== []) {
                $scoped->orWhereIn($column, self::employeeIdsSubquery($allowedAreaIds));
            }

            if ($teamIds !== []) {
                $scoped->orWhereIn($column, $teamIds);
            }

            if ($actorId !== null) {
                $scoped->orWhere($column, $actorId);
            }
        });
    }

    /**
     * Batasi query tabel users ke karyawan yang datanya boleh diubah actor (sama dengan canWriteForUser):
     * scopeOwnedRecords, tanpa akun ADMIN lain untuk admin badan usaha tertentu. Pakai untuk target
     * pembuatan data (mis. KPI per posisi, copy KPI), bukan untuk daftar yang hanya dibaca.
     *
     * @param  Builder<User>  $usersQuery
     * @return Builder<User>
     */
    public static function scopeWritableUsers(Builder $usersQuery, ?User $actor, bool $includeTeam = true, bool $includeSelf = true): Builder
    {
        $column = $usersQuery->qualifyColumn('id');
        self::scopeOwnedRecords($usersQuery, $actor, $column, $includeTeam, $includeSelf);

        if (! self::isAreaScopedAdmin($actor)) {
            return $usersQuery;
        }

        // Akun dirinya sendiri tetap boleh bila sudah termasuk cakupan di atas (sama dengan canWriteForUser).
        return $usersQuery->where(fn (Builder $writable) => $writable
            ->whereDoesntHave('role', fn (Builder $role) => $role->where('name', User::ROLE_ADMIN))
            ->orWhere($column, (int) $actor->getKey()));
    }

    /**
     * Id user yang datanya boleh diubah actor (canWriteForUser), untuk validasi target. null = semua user.
     *
     * @return array<int>|null
     */
    public static function writableUserIds(?User $actor, bool $includeTeam = true, bool $includeSelf = true): ?array
    {
        $ids = self::visibleUserIds($actor, $includeTeam, $includeSelf);

        if ($ids === null || $ids === [] || ! self::isAreaScopedAdmin($actor)) {
            return $ids;
        }

        $actorId = (int) $actor->getKey();
        $adminIds = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereIn('users.id', $ids)
            ->where('roles.name', User::ROLE_ADMIN)
            ->pluck('users.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_values(array_filter(
            $ids,
            static fn (int $id): bool => $id === $actorId || ! in_array($id, $adminIds, true),
        ));
    }

    /**
     * Id karyawan (termasuk yang soft-deleted) di badan usaha tertentu, sebagai subquery.
     *
     * @param  array<int>  $areaIds
     */
    public static function employeeIdsSubquery(array $areaIds): QueryBuilder
    {
        return DB::table('users')->select('id')->whereIn('area_id', $areaIds);
    }

    /**
     * Id user yang terlihat oleh actor, untuk opsi select / validasi. null = semua user.
     *
     * @return array<int>|null
     */
    public static function visibleUserIds(?User $actor, bool $includeTeam = true, bool $includeSelf = true): ?array
    {
        if (self::hasGlobalAccess($actor)) {
            return null;
        }

        if (! $actor instanceof User) {
            return [];
        }

        $allowedAreaIds = self::allowedAreaIds($actor);
        $ids = $allowedAreaIds === []
            ? []
            : self::employeeIdsSubquery($allowedAreaIds)->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        if ($includeTeam) {
            $ids = array_merge($ids, ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $actor->getKey()));
        }

        if ($includeSelf) {
            $ids[] = (int) $actor->getKey();
        }

        return array_values(array_unique($ids));
    }

    /**
     * Sidik jari cakupan untuk kunci cache, mis. 'all', 'areas:3,27', atau 'none'.
     */
    public static function scopeKey(?User $user): string
    {
        $allowed = self::allowedAreaIds($user);

        if ($allowed === null) {
            return 'all';
        }

        if ($allowed === []) {
            return 'none';
        }

        sort($allowed);

        return 'areas:'.implode(',', $allowed);
    }

    /**
     * @return array<int>
     */
    private function selectedAreaIds(int $userId): array
    {
        if (array_key_exists($userId, $this->areaMemo)) {
            return $this->areaMemo[$userId];
        }

        return $this->areaMemo[$userId] = DB::table('user_area_access')
            ->where('user_id', $userId)
            ->pluck('area_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function userIsAdmin(User|int|string $target): bool
    {
        if ($target instanceof User) {
            return $target->isAdmin();
        }

        $userId = (int) $target;

        if (array_key_exists($userId, $this->userAdminMemo)) {
            return $this->userAdminMemo[$userId];
        }

        return $this->userAdminMemo[$userId] = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.id', $userId)
            ->value('roles.name') === User::ROLE_ADMIN;
    }

    private function areaIdOfUser(int $userId): ?int
    {
        if (array_key_exists($userId, $this->userAreaMemo)) {
            return $this->userAreaMemo[$userId];
        }

        $areaId = DB::table('users')->where('id', $userId)->value('area_id');

        return $this->userAreaMemo[$userId] = $areaId === null ? null : (int) $areaId;
    }
}
