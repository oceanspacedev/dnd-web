<?php

namespace Tests\Feature\DataAccess;

use App\Models\Area;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

class UsersApiScopeTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private Area $coo;

    private Area $uls;

    private Area $gmd;

    private User $superAdmin;

    private User $scopedAdmin;

    private User $ulsLead;

    private User $ulsStaff;

    private User $ulsOtherAdmin;

    private User $gmdUnderUlsLead;

    private User $cooStaff;

    private User $cooTeamMember;

    private User $gmdStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();

        $this->coo = $this->fixtureArea('COO');
        $this->uls = $this->fixtureArea('ULS');
        $this->gmd = $this->fixtureArea('GMD');

        $this->superAdmin = $this->fixtureGlobalAdmin('super_admin', $this->coo);
        $this->scopedAdmin = $this->fixtureScopedAdmin('ojan', $this->coo, [$this->uls]);

        $this->ulsLead = $this->fixtureUser('uls_lead', 'TEAM LEADER', $this->uls);
        $this->ulsStaff = $this->fixtureUser('uls_staff', 'STAFF', $this->uls, $this->ulsLead);
        $this->gmdUnderUlsLead = $this->fixtureUser('gmd_under_uls_lead', 'STAFF', $this->gmd, $this->ulsLead);
        $this->ulsOtherAdmin = $this->fixtureUser('uls_other_admin', 'ADMIN', $this->uls);
        $this->cooStaff = $this->fixtureUser('coo_staff', 'STAFF', $this->coo);
        $this->cooTeamMember = $this->fixtureUser('coo_team', 'STAFF', $this->coo, $this->scopedAdmin);
        $this->gmdStaff = $this->fixtureUser('gmd_staff', 'STAFF', $this->gmd);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_super_admin_index_lists_every_badan_usaha(): void
    {
        $this->actingAsApi($this->superAdmin);

        $response = $this->getJson('/api/v1/users?per_page=100')->assertOk();

        $this->assertEqualsCanonicalizing(User::query()->pluck('id')->all(), $this->ids($response));

        $scopedAdminRow = collect($response->json('data'))->firstWhere('id', $this->scopedAdmin->id);
        $this->assertSame(User::DATA_ACCESS_SELECTED, $scopedAdminRow['data_access']);
        $this->assertSame([$this->uls->id], $scopedAdminRow['data_access_area_ids']);
    }

    public function test_scoped_admin_index_lists_allowed_areas_and_team_without_admin_accounts(): void
    {
        $this->actingAsApi($this->scopedAdmin);

        $response = $this->getJson('/api/v1/users?per_page=100')->assertOk();
        $ids = $this->ids($response);

        // Akun sendiri (ADMIN) tidak ikut: UserPolicy menolak membukanya, jadi tidak boleh muncul sebagai baris.
        $this->assertEqualsCanonicalizing([
            $this->ulsLead->id,
            $this->ulsStaff->id,
            $this->cooTeamMember->id,
        ], $ids);

        $staffRow = collect($response->json('data'))->firstWhere('id', $this->ulsStaff->id);
        $this->assertNull($staffRow['data_access']);
        $this->assertArrayNotHasKey('data_access_area_ids', $staffRow);

        // Setiap baris di daftar bisa dibuka.
        foreach ($ids as $id) {
            $this->getJson('/api/v1/users/'.$id)->assertOk();
        }

        $this->getJson('/api/v1/users/'.$this->scopedAdmin->id)->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $this->scopedAdmin->id);
    }

    public function test_scoped_admin_does_not_list_itself_even_when_its_home_area_is_allowed(): void
    {
        $homeAdmin = $this->fixtureScopedAdmin('home_admin', $this->coo, [$this->coo]);
        $this->actingAsApi($homeAdmin);

        foreach (['', '&scope=employees'] as $scope) {
            $ids = $this->ids($this->getJson('/api/v1/users?per_page=100'.$scope)->assertOk());

            $this->assertEqualsCanonicalizing([$this->cooStaff->id, $this->cooTeamMember->id], $ids);
        }

        $this->assertContains(
            $homeAdmin->id,
            $this->ids($this->getJson('/api/v1/users/supervisors')->assertOk()),
        );
    }

    public function test_area_filter_only_narrows_the_scoped_admin_list(): void
    {
        $this->actingAsApi($this->scopedAdmin);

        $this->assertEqualsCanonicalizing(
            [$this->cooTeamMember->id],
            $this->ids($this->getJson('/api/v1/users?per_page=100&area_id='.$this->coo->id)->assertOk()),
        );

        $this->assertSame(
            [],
            $this->ids($this->getJson('/api/v1/users?per_page=100&area_id='.$this->gmd->id)->assertOk()),
        );
    }

    public function test_scope_parameter_splits_karyawan_and_tim_saya(): void
    {
        $this->actingAsApi($this->scopedAdmin);

        $this->assertEqualsCanonicalizing(
            [$this->ulsLead->id, $this->ulsStaff->id],
            $this->ids($this->getJson('/api/v1/users?scope=employees&per_page=100')->assertOk()),
        );
        $this->assertEqualsCanonicalizing(
            [$this->cooTeamMember->id],
            $this->ids($this->getJson('/api/v1/users?scope=team&per_page=100')->assertOk()),
        );
        $this->getJson('/api/v1/users?scope=everything')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('scope');

        $this->actingAsApi($this->superAdmin);
        $this->assertCount(
            User::query()->count(),
            $this->ids($this->getJson('/api/v1/users?scope=employees&per_page=100')->assertOk()),
        );
        $this->assertSame([], $this->ids($this->getJson('/api/v1/users?scope=team&per_page=100')->assertOk()));

        $this->actingAsApi($this->ulsLead);
        $this->getJson('/api/v1/users?scope=employees')->assertForbidden();
        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->gmdUnderUlsLead->id],
            $this->ids($this->getJson('/api/v1/users?scope=team&per_page=100')->assertOk()),
        );
    }

    public function test_scoped_admin_without_areas_cannot_open_the_karyawan_scope(): void
    {
        $emptyAdmin = $this->fixtureScopedAdmin('empty_admin', $this->coo, []);
        $this->fixtureUser('empty_admin_team', 'STAFF', $this->gmd, $emptyAdmin);
        $this->resetAccessMemos();
        $this->actingAsApi($emptyAdmin);

        $this->getJson('/api/v1/users?scope=employees')->assertForbidden();
    }

    public function test_scoped_admin_show_respects_scope_and_filters_subordinates(): void
    {
        $this->actingAsApi($this->scopedAdmin);

        $this->getJson('/api/v1/users/'.$this->cooStaff->id)->assertForbidden();
        $this->getJson('/api/v1/users/'.$this->gmdStaff->id)->assertForbidden();
        $this->getJson('/api/v1/users/'.$this->ulsOtherAdmin->id)->assertForbidden();
        $this->getJson('/api/v1/users/'.$this->cooTeamMember->id)->assertOk();

        $response = $this->getJson('/api/v1/users/'.$this->ulsLead->id)->assertOk();
        $this->assertSame(
            [$this->ulsStaff->id],
            collect($response->json('data.subordinates'))->pluck('id')->all(),
        );
        $this->assertNull($response->json('data.data_access'));

        $this->actingAsApi($this->superAdmin);
        $superView = $this->getJson('/api/v1/users/'.$this->ulsLead->id)->assertOk();
        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->gmdUnderUlsLead->id],
            collect($superView->json('data.subordinates'))->pluck('id')->all(),
        );

        $adminView = $this->getJson('/api/v1/users/'.$this->scopedAdmin->id)->assertOk();
        $adminView->assertJsonPath('data.data_access', User::DATA_ACCESS_SELECTED);
        $adminView->assertJsonPath('data.data_access_area_ids', [$this->uls->id]);
    }

    public function test_supervisor_options_are_scoped_for_area_admins(): void
    {
        $this->actingAsApi($this->scopedAdmin);

        $this->assertEqualsCanonicalizing([
            $this->scopedAdmin->id,
            $this->ulsLead->id,
            $this->ulsStaff->id,
            $this->cooTeamMember->id,
        ], $this->ids($this->getJson('/api/v1/users/supervisors')->assertOk()));

        $this->actingAsApi($this->superAdmin);
        $this->assertCount(
            User::query()->count(),
            $this->ids($this->getJson('/api/v1/users/supervisors')->assertOk()),
        );
    }

    public function test_scoped_admin_can_only_create_employees_inside_allowed_areas(): void
    {
        $this->actingAsApi($this->scopedAdmin);

        $this->postJson('/api/v1/users', $this->newEmployeePayload('outside_area', $this->coo))
            ->assertStatus(422)
            ->assertJsonValidationErrors('area_id');

        $withoutArea = $this->newEmployeePayload('without_area', $this->uls);
        unset($withoutArea['area_id']);
        $this->postJson('/api/v1/users', $withoutArea)
            ->assertStatus(422)
            ->assertJsonValidationErrors('area_id');

        $this->postJson('/api/v1/users', $this->newEmployeePayload('foreign_approver', $this->uls, [
            'approval_id' => $this->cooStaff->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('approval_id');

        $this->postJson('/api/v1/users', $this->newEmployeePayload('wants_admin', $this->uls, [
            'role_id' => $this->fixtureRole('ADMIN')->id,
        ]))->assertForbidden();

        $this->postJson('/api/v1/users', $this->newEmployeePayload('wants_access', $this->uls, [
            'data_access' => User::DATA_ACCESS_ALL,
        ]))->assertForbidden();

        $created = $this->postJson('/api/v1/users', $this->newEmployeePayload('inside_area', $this->uls, [
            'approval_id' => $this->ulsLead->id,
            'no_hp' => '081234567890',
        ]))->assertCreated()->json('data');

        $this->assertSame($this->uls->id, $created['area']['id']);
        $this->assertSame('081234567890', $created['no_hp']);
        $this->assertDatabaseMissing('users', ['username' => 'outside_area']);
        $this->assertDatabaseMissing('users', ['username' => 'wants_admin']);
    }

    public function test_scoped_admin_cannot_move_employees_out_of_scope(): void
    {
        $this->actingAsApi($this->scopedAdmin);

        $this->putJson('/api/v1/users/'.$this->ulsStaff->id, ['area_id' => $this->coo->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('area_id');
        $this->putJson('/api/v1/users/'.$this->ulsStaff->id, ['area_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('area_id');
        $this->putJson('/api/v1/users/'.$this->ulsStaff->id, ['approval_id' => $this->gmdStaff->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('approval_id');
        $this->assertSame($this->uls->id, (int) $this->ulsStaff->fresh()->area_id);
        $this->assertSame($this->ulsLead->id, (int) $this->ulsStaff->fresh()->approval_id);

        // Anggota tim di badan usaha lain: nilai area dan approval yang tidak berubah tetap boleh dikirim.
        $this->putJson('/api/v1/users/'.$this->cooTeamMember->id, [
            'nama_lengkap' => 'COO TEAM EDITED',
            'area_id' => $this->coo->id,
            'approval_id' => $this->scopedAdmin->id,
        ])->assertOk()->assertJsonPath('data.nama_lengkap', 'COO TEAM EDITED');

        $this->putJson('/api/v1/users/'.$this->ulsStaff->id, [
            'approval_id' => $this->cooTeamMember->id,
            'no_hp' => '081298765432',
        ])->assertOk()->assertJsonPath('data.supervisor.id', $this->cooTeamMember->id);
        $this->assertSame('081298765432', $this->ulsStaff->fresh()->no_hp);

        $this->putJson('/api/v1/users/'.$this->cooStaff->id, ['nama_lengkap' => 'X'])->assertForbidden();
        $this->putJson('/api/v1/users/'.$this->ulsOtherAdmin->id, ['nama_lengkap' => 'X'])->assertForbidden();
        $this->putJson('/api/v1/users/'.$this->ulsStaff->id, [
            'data_access_area_ids' => [$this->coo->id],
        ])->assertForbidden();
        $this->deleteJson('/api/v1/users/'.$this->gmdStaff->id)->assertForbidden();
        $this->assertNotSoftDeleted($this->gmdStaff);
    }

    public function test_super_admin_manages_admin_data_access(): void
    {
        $this->actingAsApi($this->superAdmin);
        $adminRoleId = $this->fixtureRole('ADMIN')->id;

        $this->postJson('/api/v1/users', $this->newEmployeePayload('new_admin_missing_areas', $this->coo, [
            'role_id' => $adminRoleId,
            'data_access' => User::DATA_ACCESS_SELECTED,
        ]))->assertStatus(422)->assertJsonValidationErrors('data_access_area_ids');

        $created = $this->postJson('/api/v1/users', $this->newEmployeePayload('new_area_admin', $this->coo, [
            'role_id' => $adminRoleId,
            'data_access' => User::DATA_ACCESS_SELECTED,
            'data_access_area_ids' => [$this->gmd->id, $this->uls->id],
        ]))->assertCreated()->json('data');

        $this->assertSame(User::DATA_ACCESS_SELECTED, $created['data_access']);
        $this->assertSame([$this->uls->id, $this->gmd->id], $created['data_access_area_ids']);
        $newAdmin = User::query()->findOrFail($created['id']);
        $this->assertTrue(DB::table('user_area_access')->where('user_id', $newAdmin->id)->where('area_id', $this->gmd->id)->exists());

        $this->putJson('/api/v1/users/'.$newAdmin->id, ['data_access_area_ids' => [$this->coo->id]])
            ->assertOk()
            ->assertJsonPath('data.data_access_area_ids', [$this->coo->id]);

        $this->putJson('/api/v1/users/'.$newAdmin->id, ['data_access' => User::DATA_ACCESS_ALL])
            ->assertOk()
            ->assertJsonPath('data.data_access', User::DATA_ACCESS_ALL)
            ->assertJsonPath('data.data_access_area_ids', null);
        $this->assertSame(0, DB::table('user_area_access')->where('user_id', $newAdmin->id)->count());

        $this->putJson('/api/v1/users/'.$this->scopedAdmin->id, ['role_id' => $this->fixtureRole('STAFF')->id])
            ->assertOk()
            ->assertJsonPath('data.data_access', null);
        $this->assertSame(User::DATA_ACCESS_ALL, $this->scopedAdmin->fresh()->data_access);
        $this->assertSame(0, DB::table('user_area_access')->where('user_id', $this->scopedAdmin->id)->count());

        // Akses data untuk role non-ADMIN diabaikan.
        $staff = $this->postJson('/api/v1/users', $this->newEmployeePayload('staff_with_access', $this->coo, [
            'data_access' => User::DATA_ACCESS_SELECTED,
            'data_access_area_ids' => [$this->uls->id],
        ]))->assertCreated()->json('data');
        $this->assertNull($staff['data_access']);
        $this->assertSame(User::DATA_ACCESS_ALL, User::query()->findOrFail($staff['id'])->data_access);
        $this->assertSame(0, DB::table('user_area_access')->where('user_id', $staff['id'])->count());
    }

    public function test_only_super_admin_may_import_json(): void
    {
        $this->actingAsApi($this->scopedAdmin);

        $file = UploadedFile::fake()->createWithContent('users.json', '[]');

        $this->post('/api/v1/users/import-json', ['file' => $file], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_me_and_login_expose_the_data_access_block(): void
    {
        $this->actingAsApi($this->superAdmin);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.username', 'super_admin')
            ->assertJsonPath('data.data_access', User::DATA_ACCESS_ALL)
            ->assertJsonPath('data_access', [
                'scope' => 'all',
                'area_ids' => null,
                'can_access_employees' => true,
                'can_access_team' => false,
            ]);

        $this->actingAsApi($this->scopedAdmin);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.data_access', User::DATA_ACCESS_SELECTED)
            ->assertJsonPath('data_access', [
                'scope' => 'selected',
                'area_ids' => [$this->uls->id],
                'can_access_employees' => true,
                'can_access_team' => true,
            ]);

        $this->actingAsApi($this->ulsLead);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.data_access', null)
            ->assertJsonPath('data_access', [
                'scope' => 'none',
                'area_ids' => [],
                'can_access_employees' => false,
                'can_access_team' => true,
            ]);

        // Login memakai path yang sama dengan /auth/me: ringkasan di 'data_access' tingkat atas, nilai mentah
        // users.data_access di objek user. data.data_access tidak dipakai ulang dengan bentuk lain.
        $this->resetAccessMemos();
        $this->postJson('/api/v1/auth/login', [
            'login' => 'ojan',
            'password' => 'password',
            'device_name' => 'scope-test',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.data_access', User::DATA_ACCESS_SELECTED)
            ->assertJsonMissingPath('data.data_access')
            ->assertJsonPath('data_access', [
                'scope' => 'selected',
                'area_ids' => [$this->uls->id],
                'can_access_employees' => true,
                'can_access_team' => true,
            ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'uls_staff',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonMissingPath('data.data_access')
            ->assertJsonPath('data_access.scope', 'none')
            ->assertJsonPath('data_access.can_access_employees', false)
            ->assertJsonPath('data_access.can_access_team', false);
    }

    public function test_non_admin_supervisor_keeps_team_rules_and_any_existing_approver(): void
    {
        $this->fixtureUser('uls_lead_admin_report', 'ADMIN', $this->uls, $this->ulsLead);
        $this->resetAccessMemos();
        $this->actingAsApi($this->ulsLead);

        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->gmdUnderUlsLead->id],
            $this->ids($this->getJson('/api/v1/users?per_page=100')->assertOk()),
        );
        $this->assertEqualsCanonicalizing(
            [$this->ulsLead->id, $this->ulsStaff->id, $this->gmdUnderUlsLead->id],
            $this->ids($this->getJson('/api/v1/users/supervisors')->assertOk()),
        );
        $this->getJson('/api/v1/users/'.$this->cooStaff->id)->assertForbidden();

        // Atasan non-admin tetap boleh memilih approval line mana pun yang ada, seperti sebelum fitur ini.
        $this->putJson('/api/v1/users/'.$this->ulsStaff->id, ['approval_id' => $this->gmdUnderUlsLead->id])
            ->assertOk();
        $this->putJson('/api/v1/users/'.$this->ulsStaff->id, ['approval_id' => $this->cooStaff->id])
            ->assertOk()
            ->assertJsonPath('data.supervisor.id', $this->cooStaff->id);

        // Non-admin tidak dibatasi badan usaha saat membuat anggota tim.
        $this->postJson('/api/v1/users', $this->newEmployeePayload('lead_new_member', $this->coo, [
            'approval_id' => $this->ulsLead->id,
        ]))->assertCreated();

        $this->putJson('/api/v1/users/'.$this->ulsStaff->id, ['no_hp' => '081211112222'])->assertForbidden();
    }

    public function test_scoped_admin_cannot_pull_out_of_area_reports_into_their_team(): void
    {
        $gmdDeeper = $this->fixtureUser('gmd_deeper', 'STAFF', $this->gmd, $this->gmdUnderUlsLead);
        $this->actingAsApi($this->scopedAdmin);

        $this->getJson('/api/v1/users/'.$this->gmdUnderUlsLead->id)->assertForbidden();

        // uls_lead membawahi karyawan GMD; menjadikan diri sendiri atasannya akan menarik GMD ke tim.
        $this->putJson('/api/v1/users/'.$this->ulsLead->id, ['approval_id' => $this->scopedAdmin->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('approval_id');

        $this->assertNull($this->ulsLead->fresh()->approval_id);
        $this->resetAccessMemos();
        $this->getJson('/api/v1/users/'.$this->gmdUnderUlsLead->id)->assertForbidden();
        $this->getJson('/api/v1/users/'.$gmdDeeper->id)->assertForbidden();
        $this->deleteJson('/api/v1/users/'.$gmdDeeper->id)->assertForbidden();
        $this->assertNotSoftDeleted($gmdDeeper);

        // Karyawan yang seluruh bawahannya terlihat tetap boleh diarahkan ke diri sendiri.
        $this->putJson('/api/v1/users/'.$this->ulsStaff->id, ['approval_id' => $this->scopedAdmin->id])
            ->assertOk()
            ->assertJsonPath('data.supervisor.id', $this->scopedAdmin->id);

        // Mengarahkan ke anggota tim lain tidak menambah jangkauan actor, jadi tetap boleh.
        $this->putJson('/api/v1/users/'.$this->ulsLead->id, ['approval_id' => $this->cooTeamMember->id])
            ->assertOk()
            ->assertJsonPath('data.supervisor.id', $this->cooTeamMember->id);

        $this->resetAccessMemos();
        $this->getJson('/api/v1/users/'.$this->gmdUnderUlsLead->id)->assertForbidden();

        // Super admin tidak dibatasi.
        $this->actingAsApi($this->superAdmin);
        $this->putJson('/api/v1/users/'.$this->ulsLead->id, ['approval_id' => $this->superAdmin->id])->assertOk();
    }

    public function test_non_admin_supervisor_approval_rules_are_unchanged(): void
    {
        $ulsDeep = $this->fixtureUser('uls_deep', 'STAFF', $this->uls, $this->ulsStaff);
        $ulsDeeper = $this->fixtureUser('uls_deeper', 'STAFF', $this->uls, $ulsDeep);
        $ulsLeaf = $this->fixtureUser('uls_leaf', 'STAFF', $this->uls, $this->ulsStaff);
        $this->actingAsApi($this->ulsLead);

        $this->getJson('/api/v1/users/'.$ulsDeeper->id)->assertForbidden();

        // Pengecekan bawahan di luar akses hanya berlaku untuk admin badan usaha; atasan non-admin
        // tetap boleh menarik anggota level dua langsung ke dirinya seperti sebelum fitur ini.
        $this->putJson('/api/v1/users/'.$ulsDeep->id, ['approval_id' => $this->ulsLead->id])
            ->assertOk()
            ->assertJsonPath('data.supervisor.id', $this->ulsLead->id);

        $this->putJson('/api/v1/users/'.$ulsLeaf->id, ['approval_id' => $this->ulsLead->id])
            ->assertOk()
            ->assertJsonPath('data.supervisor.id', $this->ulsLead->id);

        // Approval line di luar tim tetap boleh untuk non-admin (aturan lama), tetapi id yang tidak ada ditolak.
        $this->putJson('/api/v1/users/'.$ulsLeaf->id, ['approval_id' => $this->gmdStaff->id])->assertOk();
        $this->putJson('/api/v1/users/'.$ulsLeaf->id, ['approval_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('approval_id');
    }

    public function test_approval_errors_do_not_reveal_which_user_ids_exist(): void
    {
        $this->actingAsApi($this->scopedAdmin);
        $missingId = (int) User::query()->withTrashed()->max('id') + 1000;

        $outOfScope = $this->putJson('/api/v1/users/'.$this->ulsStaff->id, ['approval_id' => $this->gmdStaff->id])
            ->assertStatus(422)
            ->json('errors.approval_id');
        $missing = $this->putJson('/api/v1/users/'.$this->ulsStaff->id, ['approval_id' => $missingId])
            ->assertStatus(422)
            ->json('errors.approval_id');

        $this->assertSame($outOfScope, $missing);

        $storeOutOfScope = $this->postJson('/api/v1/users', $this->newEmployeePayload('oracle_a', $this->uls, [
            'approval_id' => $this->gmdStaff->id,
        ]))->assertStatus(422)->json('errors.approval_id');
        $storeMissing = $this->postJson('/api/v1/users', $this->newEmployeePayload('oracle_b', $this->uls, [
            'approval_id' => $missingId,
        ]))->assertStatus(422)->json('errors.approval_id');

        $this->assertSame($storeOutOfScope, $storeMissing);
        $this->assertSame($outOfScope, $storeMissing);
    }

    public function test_data_access_area_ids_are_rejected_for_admins_with_all_access(): void
    {
        $this->actingAsApi($this->superAdmin);

        $this->putJson('/api/v1/users/'.$this->ulsOtherAdmin->id, ['data_access_area_ids' => [$this->uls->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('data_access_area_ids');
        $this->putJson('/api/v1/users/'.$this->ulsOtherAdmin->id, [
            'data_access' => User::DATA_ACCESS_ALL,
            'data_access_area_ids' => [$this->uls->id],
        ])->assertStatus(422)->assertJsonValidationErrors('data_access_area_ids');

        $this->assertSame(User::DATA_ACCESS_ALL, $this->ulsOtherAdmin->fresh()->data_access);
        $this->assertSame(0, DB::table('user_area_access')->where('user_id', $this->ulsOtherAdmin->id)->count());

        $adminRoleId = $this->fixtureRole('ADMIN')->id;
        $this->postJson('/api/v1/users', $this->newEmployeePayload('admin_ids_without_mode', $this->coo, [
            'role_id' => $adminRoleId,
            'data_access_area_ids' => [$this->uls->id],
        ]))->assertStatus(422)->assertJsonValidationErrors('data_access_area_ids');
        $this->assertDatabaseMissing('users', ['username' => 'admin_ids_without_mode']);

        // Mengosongkan daftar dan mengirim mode selected tetap berjalan seperti biasa.
        $this->putJson('/api/v1/users/'.$this->ulsOtherAdmin->id, ['data_access_area_ids' => []])->assertOk();
        $this->putJson('/api/v1/users/'.$this->ulsOtherAdmin->id, [
            'data_access' => User::DATA_ACCESS_SELECTED,
            'data_access_area_ids' => [$this->uls->id],
        ])->assertOk()->assertJsonPath('data.data_access_area_ids', [$this->uls->id]);
    }

    public function test_super_admin_cannot_remove_their_own_global_access(): void
    {
        $this->actingAsApi($this->superAdmin);

        $this->putJson('/api/v1/users/'.$this->superAdmin->id, [
            'data_access' => User::DATA_ACCESS_SELECTED,
            'data_access_area_ids' => [$this->uls->id],
        ])->assertStatus(422)->assertJsonValidationErrors('data_access');
        $this->assertSame(User::DATA_ACCESS_ALL, $this->superAdmin->fresh()->data_access);

        // Satu-satunya super admin tidak boleh melepas role ADMIN dari akunnya sendiri.
        $this->ulsOtherAdmin->delete();
        $this->putJson('/api/v1/users/'.$this->superAdmin->id, ['role_id' => $this->fixtureRole('STAFF')->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');
        $this->assertTrue($this->superAdmin->fresh()->isAdmin());

        // Masih bisa mengubah data lain, dan melepas role bila ada super admin lain.
        $this->putJson('/api/v1/users/'.$this->superAdmin->id, [
            'nama_lengkap' => 'SUPER ADMIN EDITED',
            'role_id' => $this->fixtureRole('ADMIN')->id,
            'data_access' => User::DATA_ACCESS_ALL,
        ])->assertOk();

        $this->ulsOtherAdmin->restore();
        $this->putJson('/api/v1/users/'.$this->superAdmin->id, ['role_id' => $this->fixtureRole('STAFF')->id])
            ->assertOk();
    }

    public function test_login_of_accounts_reaching_beyond_actor_scope_is_super_admin_only(): void
    {
        // darkini: user khusus kehadiran seluruh badan usaha; uls_lead membawahi karyawan GMD (di luar cakupan ojan).
        $darkini = $this->fixtureUser('darkini', 'COORDINATOR', $this->uls, $this->ulsLead, ['no_hp' => '081300000001']);
        $this->actingAsApi($this->scopedAdmin);

        foreach ([$darkini, $this->ulsLead] as $locked) {
            $this->putJson('/api/v1/users/'.$locked->id, ['password' => 'takeover123'])->assertForbidden();
            $this->putJson('/api/v1/users/'.$locked->id, ['no_hp' => '081311112222'])->assertForbidden();
            $this->putJson('/api/v1/users/'.$locked->id, ['username' => 'ambil_alih_'.$locked->id])->assertForbidden();
            $this->assertFalse(Hash::check('takeover123', (string) $locked->fresh()->password));

            // Data lain, dan data login yang tidak berubah, tetap boleh dikirim.
            $this->putJson('/api/v1/users/'.$locked->id, [
                'nama_lengkap' => 'NAMA BARU '.$locked->id,
                'username' => $locked->username,
                'no_hp' => $locked->no_hp,
                'password' => '',
            ])->assertOk();
            $this->assertSame('NAMA BARU '.$locked->id, $locked->fresh()->nama_lengkap);
        }

        $this->assertSame('081300000001', $darkini->fresh()->no_hp);

        // Karyawan tanpa tim di luar cakupan tetap bisa diubah login-nya.
        $this->putJson('/api/v1/users/'.$this->ulsStaff->id, ['password' => 'complete123', 'no_hp' => '081322223333'])->assertOk();
        $this->assertTrue(Hash::check('complete123', (string) $this->ulsStaff->fresh()->password));

        // Username user khusus kehadiran hanya boleh dipakai super admin.
        $darkini->forceDelete();
        $this->postJson('/api/v1/users', $this->newEmployeePayload('darkini', $this->uls))
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
        $this->putJson('/api/v1/users/'.$this->ulsStaff->id, ['username' => 'Darkini'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
        $this->assertSame('uls_staff', $this->ulsStaff->fresh()->username);

        $this->actingAsApi($this->superAdmin);
        $this->putJson('/api/v1/users/'.$this->ulsLead->id, ['password' => 'reset12345'])->assertOk();
        $this->assertTrue(Hash::check('reset12345', (string) $this->ulsLead->fresh()->password));
        $this->postJson('/api/v1/users', $this->newEmployeePayload('darkini', $this->uls))->assertCreated();
    }

    private function actingAsApi(User $user): void
    {
        $this->resetAccessMemos();
        Sanctum::actingAs($user->fresh());
    }

    /**
     * @return array<int>
     */
    private function ids(TestResponse $response): array
    {
        return collect($response->json('data'))->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newEmployeePayload(string $username, Area $area, array $overrides = []): array
    {
        return array_merge([
            'username' => $username,
            'nama_lengkap' => strtoupper($username),
            'password' => 'secret123',
            'role_id' => $this->fixtureRole('STAFF')->id,
            'area_id' => $area->id,
            'divisi_id' => $this->fixtureDivisi($area)->id,
        ], $overrides);
    }
}
