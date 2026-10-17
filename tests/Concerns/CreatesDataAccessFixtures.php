<?php

namespace Tests\Concerns;

use App\Models\Area;
use App\Models\Divisi;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;

/**
 * Fixture bersama untuk tes akses data per badan usaha (areas).
 *
 * Pakai bersama RefreshDatabase. Panggil resetAccessMemos() di setUp/tearDown.
 */
trait CreatesDataAccessFixtures
{
    /** @var array<string, Role> */
    protected array $fixtureRoles = [];

    /** @var array<string, Divisi> */
    protected array $fixtureDivisis = [];

    protected function resetAccessMemos(): void
    {
        ApprovalScopeService::clearMemo();
        DataAccessService::clearMemo();
    }

    protected function fixtureRole(string $name): Role
    {
        $levels = [
            'ADMIN' => 100,
            'BOD' => 60,
            'CHIEF' => 50,
            'MANAGER' => 40,
            'COORDINATOR' => 30,
            'TEAM LEADER' => 20,
            'STAFF' => 10,
        ];

        return $this->fixtureRoles[$name] ??= Role::query()->firstOrCreate(
            ['name' => $name],
            ['level' => $levels[$name] ?? 10],
        );
    }

    protected function fixtureArea(string $name): Area
    {
        return Area::query()->firstOrCreate(['name' => $name]);
    }

    protected function fixtureDivisi(Area $area, string $name = 'UMUM'): Divisi
    {
        return $this->fixtureDivisis["{$area->id}:{$name}"] ??= Divisi::query()->firstOrCreate([
            'area_id' => $area->id,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function fixtureUser(string $username, string $roleName, Area $area, ?User $approval = null, array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'nama_lengkap' => strtoupper(str_replace('_', ' ', $username)),
            'username' => $username,
            'password' => 'password',
            'role_id' => $this->fixtureRole($roleName)->id,
            'area_id' => $area->id,
            'divisi_id' => $this->fixtureDivisi($area)->id,
            'approval_id' => $approval?->id,
            'd' => true,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ], $attributes))->refresh();
    }

    protected function fixtureGlobalAdmin(string $username, Area $area): User
    {
        return $this->fixtureUser($username, 'ADMIN', $area, null, ['data_access' => User::DATA_ACCESS_ALL]);
    }

    /**
     * @param  array<Area>  $allowedAreas
     */
    protected function fixtureScopedAdmin(string $username, Area $homeArea, array $allowedAreas): User
    {
        $admin = $this->fixtureUser($username, 'ADMIN', $homeArea, null, ['data_access' => User::DATA_ACCESS_SELECTED]);
        $admin->dataAccessAreas()->sync(array_map(static fn (Area $area): int => (int) $area->id, $allowedAreas));
        $this->resetAccessMemos();

        return $admin->refresh();
    }
}
