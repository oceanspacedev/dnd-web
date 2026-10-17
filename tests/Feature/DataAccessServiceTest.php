<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use App\Services\DataAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

class DataAccessServiceTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_migration_defaults_existing_users_to_all_access_and_creates_pivot(): void
    {
        $coo = $this->fixtureArea('COO');
        $admin = $this->fixtureUser('legacy_admin', 'ADMIN', $coo);

        $this->assertSame(User::DATA_ACCESS_ALL, $admin->data_access);
        $this->assertTrue(DataAccessService::hasGlobalAccess($admin));
        $this->assertNull(DataAccessService::allowedAreaIds($admin));
        $this->assertSame('all', DataAccessService::scopeKey($admin));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('user_area_access'));
    }

    public function test_scoped_admin_only_reaches_selected_areas_plus_team_and_self(): void
    {
        $coo = $this->fixtureArea('COO');
        $uls = $this->fixtureArea('ULS');
        $gmd = $this->fixtureArea('GMD');

        $admin = $this->fixtureScopedAdmin('ojan', $coo, [$uls, $gmd]);
        $ulsStaff = $this->fixtureUser('uls_staff', 'STAFF', $uls);
        $gmdStaff = $this->fixtureUser('gmd_staff', 'STAFF', $gmd);
        $cooStaff = $this->fixtureUser('coo_staff', 'STAFF', $coo);
        $cooTeamMember = $this->fixtureUser('coo_team', 'STAFF', $coo, $admin);
        $this->resetAccessMemos();

        $this->assertFalse(DataAccessService::hasGlobalAccess($admin));
        $this->assertTrue(DataAccessService::isAreaScopedAdmin($admin));
        $this->assertTrue(DataAccessService::canAccessEmployees($admin));
        $this->assertEqualsCanonicalizing([$uls->id, $gmd->id], DataAccessService::allowedAreaIds($admin));
        $this->assertSame('areas:'.min($uls->id, $gmd->id).','.max($uls->id, $gmd->id), DataAccessService::scopeKey($admin));

        $this->assertTrue(DataAccessService::canAccessArea($admin, $uls->id));
        $this->assertFalse(DataAccessService::canAccessArea($admin, $coo->id));

        $this->assertTrue(DataAccessService::isEmployeeInScope($admin, $ulsStaff));
        $this->assertTrue(DataAccessService::isEmployeeInScope($admin, $gmdStaff->id));
        $this->assertFalse(DataAccessService::isEmployeeInScope($admin, $cooStaff));

        $this->assertTrue(DataAccessService::canSeeUser($admin, $cooTeamMember));
        $this->assertTrue(DataAccessService::canSeeUser($admin, $admin));
        $this->assertFalse(DataAccessService::canSeeUser($admin, $cooStaff));
        $this->assertFalse(DataAccessService::canSeeUser($admin, $cooTeamMember, includeTeam: false));

        $visibleUsers = DataAccessService::scopeEmployees(User::query(), $admin)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$ulsStaff->id, $gmdStaff->id], $visibleUsers);

        $this->assertEqualsCanonicalizing(
            [$ulsStaff->id, $gmdStaff->id, $cooTeamMember->id, $admin->id],
            DataAccessService::visibleUserIds($admin),
        );

        foreach ([$ulsStaff, $gmdStaff, $cooStaff, $cooTeamMember, $admin] as $owner) {
            Attendance::query()->create([
                'user_id' => $owner->id,
                'periode' => '2026-10',
                'work_days' => 20,
                'late_less_30' => 0,
                'late_more_30' => 0,
                'sick_days' => 0,
            ]);
        }

        $visibleOwners = DataAccessService::scopeOwnedRecords(Attendance::query(), $admin)->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing([$ulsStaff->id, $gmdStaff->id, $cooTeamMember->id, $admin->id], $visibleOwners);
    }

    public function test_scoped_admin_without_areas_and_non_admins_have_no_employee_access(): void
    {
        $coo = $this->fixtureArea('COO');
        $emptyAdmin = $this->fixtureScopedAdmin('empty_admin', $coo, []);
        $manager = $this->fixtureUser('manager', 'MANAGER', $coo);
        $staff = $this->fixtureUser('staff', 'STAFF', $coo, $manager);
        $this->resetAccessMemos();

        $this->assertFalse(DataAccessService::canAccessEmployees($emptyAdmin));
        $this->assertSame([], DataAccessService::allowedAreaIds($emptyAdmin));
        $this->assertSame([], DataAccessService::scopeEmployees(User::query(), $emptyAdmin)->pluck('id')->all());

        $this->assertFalse(DataAccessService::isAdmin($manager));
        $this->assertFalse(DataAccessService::canAccessEmployees($manager));
        $this->assertSame('none', DataAccessService::scopeKey($manager));
        $this->assertTrue(DataAccessService::canSeeUser($manager, $staff));
        $this->assertFalse(DataAccessService::isEmployeeInScope($manager, $staff));
        $this->assertEqualsCanonicalizing([$staff->id, $manager->id], DataAccessService::visibleUserIds($manager));
    }

    public function test_area_memo_is_reset_per_container_scope(): void
    {
        $coo = $this->fixtureArea('COO');
        $uls = $this->fixtureArea('ULS');
        $admin = $this->fixtureScopedAdmin('ojan', $coo, [$uls]);

        $this->assertSame([$uls->id], DataAccessService::allowedAreaIds($admin));

        $admin->dataAccessAreas()->sync([$coo->id]);
        $this->assertSame([$uls->id], DataAccessService::allowedAreaIds($admin));

        $this->app->forgetScopedInstances();
        $this->assertSame([$coo->id], DataAccessService::allowedAreaIds($admin));
    }
}
