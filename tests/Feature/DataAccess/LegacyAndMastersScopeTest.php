<?php

namespace Tests\Feature\DataAccess;

use App\Filament\Resources\ApprovalRules\ApprovalRuleResource;
use App\Filament\Resources\Areas\AreaResource;
use App\Filament\Resources\Divisis\DivisiResource;
use App\Filament\Resources\KpiCategories\KpiCategoryResource;
use App\Filament\Resources\KpiDescriptions\KpiDescriptionResource;
use App\Filament\Resources\KpiReminderSettings\KpiReminderSettingResource;
use App\Filament\Resources\KpiReminderSettings\Pages\ListKpiReminderSettings;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\ApprovalRule;
use App\Models\Area;
use App\Models\Daily;
use App\Models\DailyLog;
use App\Models\Divisi;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\KpiReminderSetting;
use App\Models\Monthly;
use App\Models\Overopen;
use App\Models\Position;
use App\Models\Request as TodoRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Weekly;
use App\Support\ObservabilityAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

class LegacyAndMastersScopeTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private Area $coo;

    private Area $uls;

    private Area $gmd;

    private User $superAdmin;

    private User $scopedAdmin;

    /** Badan usaha ULS: dalam cakupan admin per badan usaha. */
    private User $ulsStaff;

    /** Badan usaha GMD: di luar cakupan admin per badan usaha. */
    private User $gmdStaff;

    /** Badan usaha GMD, tetapi anggota tim approval admin per badan usaha. */
    private User $teamMember;

    /** Badan usaha asal admin per badan usaha (COO) yang tidak dipilih sebagai akses data. */
    private User $cooStaff;

    private User $manager;

    private User $managerStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();

        $this->coo = $this->fixtureArea('COO');
        $this->uls = $this->fixtureArea('ULS');
        $this->gmd = $this->fixtureArea('GMD');

        $this->superAdmin = $this->fixtureGlobalAdmin('super_admin', $this->coo);
        $this->scopedAdmin = $this->fixtureScopedAdmin('ojan', $this->coo, [$this->uls]);

        $this->ulsStaff = $this->fixtureUser('uls_staff', 'STAFF', $this->uls);
        $this->gmdStaff = $this->fixtureUser('gmd_staff', 'STAFF', $this->gmd);
        $this->teamMember = $this->fixtureUser('gmd_team', 'STAFF', $this->gmd, $this->scopedAdmin);
        $this->cooStaff = $this->fixtureUser('coo_staff', 'STAFF', $this->coo);

        $this->manager = $this->fixtureUser('gmd_manager', 'MANAGER', $this->gmd);
        $this->managerStaff = $this->fixtureUser('gmd_manager_staff', 'STAFF', $this->gmd, $this->manager);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    // ==========================================
    // AKTIVITAS (DAILY / WEEKLY / MONTHLY)
    // ==========================================

    public function test_activity_lists_follow_data_access_scope(): void
    {
        $owners = [$this->ulsStaff, $this->gmdStaff, $this->teamMember, $this->cooStaff, $this->scopedAdmin, $this->managerStaff];

        foreach ($owners as $owner) {
            $this->makeDaily($owner);
            $this->makeWeekly($owner);
            $this->makeMonthly($owner);
        }

        $allIds = $this->ids($owners);
        $scopedIds = $this->ids([$this->ulsStaff, $this->teamMember, $this->scopedAdmin]);

        foreach (['dailies', 'weeklies', 'monthlies'] as $endpoint) {
            $this->assertEqualsCanonicalizing($allIds, $this->listedOwners($this->superAdmin, '/api/v1/activities/'.$endpoint), $endpoint);
            $this->assertEqualsCanonicalizing($scopedIds, $this->listedOwners($this->scopedAdmin, '/api/v1/activities/'.$endpoint), $endpoint);

            // Non-admin: tetap diri sendiri + tim approval (tidak berubah).
            $this->assertEqualsCanonicalizing(
                $this->ids([$this->managerStaff]),
                $this->listedOwners($this->manager, '/api/v1/activities/'.$endpoint),
                $endpoint,
            );
        }

        // Filter user_id tetap berada di dalam cakupan.
        $this->assertSame([], $this->listedOwners($this->scopedAdmin, '/api/v1/activities/dailies?user_id='.$this->gmdStaff->id));
    }

    public function test_area_scoped_admin_record_access_is_limited_to_visible_owners(): void
    {
        $inScope = $this->makeDaily($this->ulsStaff);
        $team = $this->makeDaily($this->teamMember);
        $outOfScope = $this->makeDaily($this->gmdStaff);
        $homeArea = $this->makeDaily($this->cooStaff);
        $outLog = DailyLog::query()->create(['user_id' => $this->gmdStaff->id, 'task_id' => $outOfScope->id, 'activity' => 'log']);

        Sanctum::actingAs($this->scopedAdmin);

        $this->getJson('/api/v1/activities/dailies/'.$inScope->id)->assertOk();
        $this->getJson('/api/v1/activities/dailies/'.$team->id)->assertOk();
        $this->getJson('/api/v1/activities/dailies/'.$outOfScope->id)->assertForbidden();
        $this->getJson('/api/v1/activities/dailies/'.$homeArea->id)->assertForbidden();

        $this->putJson('/api/v1/activities/dailies/'.$outOfScope->id, ['task' => 'X'])->assertForbidden();
        $this->deleteJson('/api/v1/activities/dailies/'.$outOfScope->id)->assertForbidden();
        $this->getJson('/api/v1/activities/dailies/'.$outOfScope->id.'/logs')->assertForbidden();
        $this->postJson('/api/v1/activities/dailies/'.$outOfScope->id.'/logs', ['activity' => 'X'])->assertForbidden();
        $this->putJson('/api/v1/activities/dailies/'.$outOfScope->id.'/logs/'.$outLog->id, ['activity' => 'X'])->assertForbidden();
        $this->deleteJson('/api/v1/activities/dailies/'.$outOfScope->id.'/logs/'.$outLog->id)->assertForbidden();
        $this->assertNotNull(Daily::query()->find($outOfScope->id));
        $this->assertNotNull(DailyLog::query()->find($outLog->id));

        $this->getJson('/api/v1/activities/summary?user_id='.$this->gmdStaff->id)->assertForbidden();
        $this->getJson('/api/v1/activities/summary?user_id='.$this->ulsStaff->id)->assertOk();

        $outWeekly = $this->makeWeekly($this->gmdStaff);
        $outMonthly = $this->makeMonthly($this->gmdStaff);
        $this->getJson('/api/v1/activities/weeklies/'.$outWeekly->id)->assertForbidden();
        $this->putJson('/api/v1/activities/weeklies/'.$outWeekly->id, ['task' => 'X'])->assertForbidden();
        $this->getJson('/api/v1/activities/monthlies/'.$outMonthly->id)->assertForbidden();
        $this->deleteJson('/api/v1/activities/monthlies/'.$outMonthly->id)->assertForbidden();

        // Membuat data untuk karyawan di luar cakupan ditolak; di dalam cakupan diizinkan.
        $this->postJson('/api/v1/activities/dailies', [
            'user_id' => $this->gmdStaff->id,
            'task' => 'Luar cakupan',
            'date' => '2026-10-17',
        ])->assertForbidden();
        $this->postJson('/api/v1/activities/weeklies', [
            'user_id' => $this->gmdStaff->id,
            'task' => 'Luar cakupan',
            'week' => 42,
            'year' => 2026,
        ])->assertForbidden();
        $this->postJson('/api/v1/activities/monthlies', [
            'user_id' => $this->gmdStaff->id,
            'task' => 'Luar cakupan',
            'date' => '2026-10-01',
        ])->assertForbidden();
        $this->postJson('/api/v1/activities/dailies', [
            'user_id' => $this->ulsStaff->id,
            'task' => 'Dalam cakupan',
            'date' => '2026-10-17',
        ])->assertCreated();
    }

    public function test_area_scoped_admin_cannot_tag_users_outside_scope(): void
    {
        Sanctum::actingAs($this->scopedAdmin);

        $this->postJson('/api/v1/activities/dailies', [
            'user_id' => $this->ulsStaff->id,
            'task' => 'Tag luar cakupan',
            'date' => '2026-10-17',
            'tag_id' => $this->gmdStaff->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('tag_id');

        $this->postJson('/api/v1/activities/weeklies', [
            'user_id' => $this->ulsStaff->id,
            'task' => 'Tag luar cakupan',
            'week' => 42,
            'year' => 2026,
            'tag_id' => $this->cooStaff->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('tag_id');

        $monthly = $this->makeMonthly($this->ulsStaff);
        $this->putJson('/api/v1/activities/monthlies/'.$monthly->id, [
            'tag_id' => $this->gmdStaff->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('tag_id');

        $this->postJson('/api/v1/activities/dailies', [
            'user_id' => $this->ulsStaff->id,
            'task' => 'Tag anggota tim',
            'date' => '2026-10-17',
            'tag_id' => $this->teamMember->id,
        ])->assertCreated()->assertJsonPath('data.tagged_user.id', $this->teamMember->id);

        // Super admin tidak dibatasi.
        Sanctum::actingAs($this->superAdmin);
        $this->postJson('/api/v1/activities/dailies', [
            'user_id' => $this->gmdStaff->id,
            'task' => 'Super admin',
            'date' => '2026-10-17',
            'tag_id' => $this->cooStaff->id,
        ])->assertCreated();
    }

    /**
     * (int) dari array non-kosong selalu 1, sedangkan query builder memakai elemen pertama
     * array. Parameter user berbentuk array tidak boleh lolos dari pemeriksaan cakupan.
     */
    public function test_array_user_parameters_cannot_escape_scope(): void
    {
        // Pastikan user id 1 terlihat oleh admin ini, agar pemeriksaan berbasis (int) cast lolos
        // sementara GMD (pemilik data yang diincar) tetap di luar cakupan.
        $userOne = User::withTrashed()->findOrFail(1);
        $this->assertNotEquals($this->gmd->id, $userOne->area_id);
        $admin = $this->fixtureScopedAdmin('ojan_hq', $this->coo, [$this->uls, Area::query()->findOrFail($userOne->area_id)]);
        $this->resetAccessMemos();

        $this->makeDaily($this->gmdStaff);
        $daily = $this->makeDaily($this->ulsStaff);
        $weekly = $this->makeWeekly($this->ulsStaff);
        $monthly = $this->makeMonthly($this->ulsStaff);
        $overopen = Overopen::query()->create(['user_id' => $this->ulsStaff->id, 'atasan' => null, 'week' => 40, 'year' => 2026, 'point' => 1]);

        Sanctum::actingAs($admin);

        // Ringkasan aktivitas: user_id[] ditolak, nilai skalar tetap berfungsi.
        $this->getJson('/api/v1/activities/summary?user_id[]='.$this->gmdStaff->id)->assertUnprocessable();
        $this->getJson('/api/v1/activities/summary?user_id='.$this->gmdStaff->id)->assertForbidden();
        $this->getJson('/api/v1/activities/summary?user_id='.$this->ulsStaff->id)
            ->assertOk()
            ->assertJsonPath('data.user_id', $this->ulsStaff->id);
        $this->getJson('/api/v1/activities/summary')
            ->assertOk()
            ->assertJsonPath('data.user_id', $admin->id);

        // tag_id / atasan berbentuk array pada pembaruan tidak boleh menyimpan user di luar cakupan.
        $this->putJson('/api/v1/activities/dailies/'.$daily->id, ['tag_id' => [$this->gmdStaff->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tag_id');
        $this->putJson('/api/v1/activities/weeklies/'.$weekly->id, ['tag_id' => [$this->gmdStaff->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tag_id');
        $this->putJson('/api/v1/activities/monthlies/'.$monthly->id, ['tag_id' => [$this->gmdStaff->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tag_id');
        $this->putJson('/api/v1/overopens/'.$overopen->id, ['atasan' => [$this->gmdStaff->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('atasan');

        $this->assertNull($daily->fresh()->tag_id);
        $this->assertNull($weekly->fresh()->tag_id);
        $this->assertNull($monthly->fresh()->tag_id);
        $this->assertNull($overopen->fresh()->atasan);

        // user_id / tag_id / atasan berbentuk array pada pembuatan data ditolak dengan 422.
        $this->postJson('/api/v1/activities/dailies', [
            'user_id' => [$this->gmdStaff->id],
            'task' => 'Array user',
            'date' => '2026-10-17',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/v1/activities/weeklies', [
            'user_id' => $this->ulsStaff->id,
            'task' => 'Array tag',
            'week' => 42,
            'year' => 2026,
            'tag_id' => [$this->gmdStaff->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('tag_id');
        $this->postJson('/api/v1/activities/monthlies', [
            'user_id' => [$this->gmdStaff->id],
            'task' => 'Array user',
            'date' => '2026-10-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/v1/overopens', [
            'user_id' => [$this->gmdStaff->id],
            'week' => 41,
            'year' => 2026,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/v1/overopens', [
            'user_id' => $this->ulsStaff->id,
            'atasan' => [$this->gmdStaff->id],
            'week' => 41,
            'year' => 2026,
        ])->assertUnprocessable()->assertJsonValidationErrors('atasan');

        $this->assertSame(1, Daily::query()->where('user_id', $this->gmdStaff->id)->count());
        $this->assertFalse(Monthly::query()->where('user_id', $this->gmdStaff->id)->exists());
        $this->assertFalse(Overopen::query()->where('user_id', $this->gmdStaff->id)->exists());
        $this->assertFalse(Weekly::query()->where('tag_id', $this->gmdStaff->id)->exists());

        // Non-admin: ringkasan dengan user_id[] juga ditolak; ringkasan diri sendiri tetap berfungsi.
        Sanctum::actingAs($this->managerStaff);
        $this->getJson('/api/v1/activities/summary?user_id[]='.$this->gmdStaff->id)->assertUnprocessable();
        $this->getJson('/api/v1/activities/summary')
            ->assertOk()
            ->assertJsonPath('data.user_id', $this->managerStaff->id);
    }

    public function test_area_scoped_admin_keeps_records_of_soft_deleted_employees(): void
    {
        $leaver = $this->fixtureUser('uls_leaver', 'STAFF', $this->uls);
        $daily = $this->makeDaily($leaver);
        $request = $this->makeRequest($leaver, $this->superAdmin);
        $overopen = Overopen::query()->create(['user_id' => $leaver->id, 'atasan' => null, 'week' => 40, 'year' => 2026, 'point' => 1]);
        $leaver->delete();
        $this->resetAccessMemos();

        $this->assertContains((int) $leaver->id, $this->listedOwners($this->scopedAdmin, '/api/v1/activities/dailies'));

        Sanctum::actingAs($this->scopedAdmin);
        $this->getJson('/api/v1/activities/dailies/'.$daily->id)->assertOk();
        $this->getJson('/api/v1/overopens/'.$overopen->id)->assertOk();
        $this->getJson('/api/v1/requests/'.$request->id)->assertOk();
    }

    public function test_non_admin_activity_access_is_unchanged(): void
    {
        $teamDaily = $this->makeDaily($this->managerStaff);
        $otherDaily = $this->makeDaily($this->ulsStaff);

        Sanctum::actingAs($this->manager);
        $this->getJson('/api/v1/activities/dailies/'.$teamDaily->id)->assertOk();
        $this->getJson('/api/v1/activities/dailies/'.$otherDaily->id)->assertForbidden();
        $this->postJson('/api/v1/activities/dailies', [
            'user_id' => $this->managerStaff->id,
            'task' => 'Untuk tim',
            'date' => '2026-10-17',
            // Celah lama yang sengaja tidak diubah: non-admin boleh menandai user mana pun.
            'tag_id' => $this->ulsStaff->id,
        ])->assertCreated();

        Sanctum::actingAs($this->managerStaff);
        $this->getJson('/api/v1/activities/dailies/'.$teamDaily->id)->assertOk();
        $this->getJson('/api/v1/activities/dailies/'.$otherDaily->id)->assertForbidden();
    }

    // ==========================================
    // OVEROPEN
    // ==========================================

    public function test_overopen_scope_and_atasan_validation(): void
    {
        foreach ([$this->ulsStaff, $this->gmdStaff, $this->teamMember, $this->cooStaff, $this->managerStaff] as $owner) {
            Overopen::query()->create(['user_id' => $owner->id, 'atasan' => null, 'week' => 40, 'year' => 2026, 'point' => 1]);
        }

        Sanctum::actingAs($this->superAdmin);
        $this->assertCount(5, $this->getJson('/api/v1/overopens')->assertOk()->json('data'));

        Sanctum::actingAs($this->scopedAdmin);
        $listed = collect($this->getJson('/api/v1/overopens')->assertOk()->json('data'))->pluck('user.id')->map(fn ($id): int => (int) $id)->all();
        $this->assertEqualsCanonicalizing($this->ids([$this->ulsStaff, $this->teamMember]), $listed);

        $outOfScope = Overopen::query()->where('user_id', $this->gmdStaff->id)->firstOrFail();
        $this->getJson('/api/v1/overopens/'.$outOfScope->id)->assertForbidden();
        $this->putJson('/api/v1/overopens/'.$outOfScope->id, ['keterangan' => 'X'])->assertForbidden();
        $this->deleteJson('/api/v1/overopens/'.$outOfScope->id)->assertForbidden();

        $payload = ['week' => 41, 'year' => 2026, 'keterangan' => 'Terlambat'];
        $this->postJson('/api/v1/overopens', $payload + ['user_id' => $this->gmdStaff->id])->assertForbidden();
        $this->postJson('/api/v1/overopens', $payload + ['user_id' => $this->ulsStaff->id, 'atasan' => $this->gmdStaff->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('atasan');
        $this->postJson('/api/v1/overopens', $payload + ['user_id' => $this->ulsStaff->id, 'atasan' => $this->teamMember->id])
            ->assertCreated()
            ->assertJsonPath('data.supervisor.id', $this->teamMember->id);

        $inScope = Overopen::query()->where('user_id', $this->ulsStaff->id)->firstOrFail();
        $this->putJson('/api/v1/overopens/'.$inScope->id, ['atasan' => $this->cooStaff->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('atasan');

        // Non-admin: atasan selalu dipaksa menjadi dirinya sendiri (tidak berubah).
        Sanctum::actingAs($this->manager);
        $this->postJson('/api/v1/overopens', $payload + ['user_id' => $this->managerStaff->id, 'atasan' => $this->gmdStaff->id])
            ->assertCreated()
            ->assertJsonPath('data.supervisor.id', $this->manager->id);
        $managerListed = collect($this->getJson('/api/v1/overopens')->assertOk()->json('data'))->pluck('user.id')->unique()->values()->all();
        $this->assertSame([$this->managerStaff->id], array_map('intval', $managerListed));
    }

    // ==========================================
    // PENGAJUAN & APPROVAL
    // ==========================================

    public function test_request_list_show_and_pending_follow_scope(): void
    {
        $uls = $this->makeRequest($this->ulsStaff, $this->superAdmin);
        $gmd = $this->makeRequest($this->gmdStaff, $this->superAdmin);
        $team = $this->makeRequest($this->teamMember, $this->scopedAdmin);
        $directToScopedAdmin = $this->makeRequest($this->cooStaff, $this->scopedAdmin);
        $managerTeam = $this->makeRequest($this->managerStaff, $this->manager);

        Sanctum::actingAs($this->superAdmin);
        $this->assertCount(5, $this->getJson('/api/v1/requests')->assertOk()->json('data'));
        $this->assertCount(5, $this->getJson('/api/v1/requests/pending-approvals')->assertOk()->json('data'));

        Sanctum::actingAs($this->scopedAdmin);
        $expected = [$uls->id, $team->id, $directToScopedAdmin->id];
        $this->assertEqualsCanonicalizing($expected, collect($this->getJson('/api/v1/requests')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertEqualsCanonicalizing($expected, collect($this->getJson('/api/v1/requests/pending-approvals')->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson('/api/v1/requests/'.$uls->id)->assertOk();
        $this->getJson('/api/v1/requests/'.$gmd->id)->assertNotFound();

        // Non-admin tidak berubah: milik sendiri atau approval_id = dirinya.
        Sanctum::actingAs($this->manager);
        $this->assertSame([$managerTeam->id], collect($this->getJson('/api/v1/requests')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame([$managerTeam->id], collect($this->getJson('/api/v1/requests/pending-approvals')->assertOk()->json('data'))->pluck('id')->all());
    }

    public function test_request_review_and_modification_are_limited_to_visible_requesters(): void
    {
        $uls = $this->makeRequest($this->ulsStaff, $this->superAdmin);
        $gmd = $this->makeRequest($this->gmdStaff, $this->superAdmin);
        $gmdReject = $this->makeRequest($this->gmdStaff, $this->superAdmin);
        $gmdUpdate = $this->makeRequest($this->gmdStaff, $this->superAdmin);
        $team = $this->makeRequest($this->teamMember, $this->scopedAdmin);
        $directToScopedAdmin = $this->makeRequest($this->cooStaff, $this->scopedAdmin);
        $managerTeam = $this->makeRequest($this->managerStaff, $this->manager);

        Sanctum::actingAs($this->scopedAdmin);
        $this->postJson('/api/v1/requests/'.$gmd->id.'/approve')->assertForbidden();
        $this->postJson('/api/v1/requests/'.$gmdReject->id.'/reject')->assertForbidden();
        $this->putJson('/api/v1/requests/'.$gmdUpdate->id, ['todo_request' => 'X'])->assertForbidden();
        $this->deleteJson('/api/v1/requests/'.$gmd->id)->assertForbidden();
        $this->assertSame('PENDING', $gmd->fresh()->status);
        $this->assertSame('PENDING', $gmdReject->fresh()->status);
        $this->assertNotNull(TodoRequest::query()->find($gmd->id));

        $this->postJson('/api/v1/requests/'.$uls->id.'/approve')->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->postJson('/api/v1/requests/'.$team->id.'/reject')->assertOk()->assertJsonPath('data.status', 'REJECTED');
        // Ditujukan langsung kepadanya walau pengaju di luar badan usaha yang diizinkan.
        $this->postJson('/api/v1/requests/'.$directToScopedAdmin->id.'/approve')->assertOk();

        $ulsPending = $this->makeRequest($this->ulsStaff, $this->superAdmin);
        $this->putJson('/api/v1/requests/'.$ulsPending->id, ['todo_request' => 'Diubah admin'])->assertOk();
        $this->deleteJson('/api/v1/requests/'.$ulsPending->id)->assertOk();

        // Super admin: semua.
        Sanctum::actingAs($this->superAdmin);
        $this->postJson('/api/v1/requests/'.$gmd->id.'/approve')->assertOk();
        $this->deleteJson('/api/v1/requests/'.$gmdUpdate->id)->assertOk();

        // Non-admin: hanya approver langsung; bukan pemilik tidak boleh mengubah.
        Sanctum::actingAs($this->manager);
        $this->postJson('/api/v1/requests/'.$gmdReject->id.'/reject')->assertForbidden();
        $this->postJson('/api/v1/requests/'.$managerTeam->id.'/approve')->assertOk();
        $this->deleteJson('/api/v1/requests/'.$gmdReject->id)->assertForbidden();
    }

    // ==========================================
    // REMINDER (API)
    // ==========================================

    public function test_reminder_api_is_super_admin_only(): void
    {
        $setting = $this->makeReminderSetting();

        foreach ([$this->scopedAdmin, $this->manager] as $actor) {
            Sanctum::actingAs($actor);

            $this->getJson('/api/v1/reminders/settings')->assertForbidden();
            $this->getJson('/api/v1/reminders/settings/'.$setting->id)->assertForbidden();
            $this->postJson('/api/v1/reminders/settings', [
                'type' => 'pengisian_kpi',
                'title' => 'Baru',
                'deadline_day' => 25,
            ])->assertForbidden();
            $this->putJson('/api/v1/reminders/settings/'.$setting->id, ['title' => 'Ubah'])->assertForbidden();
            $this->postJson('/api/v1/reminders/settings/'.$setting->id.'/toggle')->assertForbidden();
            $this->deleteJson('/api/v1/reminders/settings/'.$setting->id)->assertForbidden();
            $this->getJson('/api/v1/reminders/logs')->assertForbidden();
            $this->postJson('/api/v1/reminders/trigger', ['dry_run' => true])->assertForbidden();
            $this->postJson('/api/v1/reminders/send-test', [
                'channel' => 'email',
                'destination' => 'test@example.test',
            ])->assertForbidden();
        }

        $this->assertSame('Pengingat', $setting->fresh()->title);
        $this->assertTrue((bool) $setting->fresh()->is_active);

        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/v1/reminders/settings')->assertOk();
        $this->getJson('/api/v1/reminders/logs')->assertOk();
        $this->putJson('/api/v1/reminders/settings/'.$setting->id, ['title' => 'Diubah'])->assertOk();
    }

    // ==========================================
    // MASTER DATA
    // ==========================================

    public function test_master_data_policies_are_super_admin_only(): void
    {
        $records = [
            Area::class => $this->uls,
            Divisi::class => $this->fixtureDivisi($this->uls),
            Role::class => $this->fixtureRole('STAFF'),
            Position::class => Position::query()->create(['name' => 'POSISI SCOPE']),
            KpiCategory::class => KpiCategory::query()->create(['name' => 'KATEGORI SCOPE']),
            ApprovalRule::class => ApprovalRule::query()->create(['name' => 'Aturan', 'area_id' => $this->uls->id, 'approver_id' => $this->superAdmin->id]),
        ];
        $records[KpiDescription::class] = KpiDescription::query()->create([
            'kpi_category_id' => $records[KpiCategory::class]->id,
            'description' => 'Deskripsi scope',
        ]);

        foreach ($records as $class => $record) {
            foreach (['viewAny', 'create', 'deleteAny', 'restoreAny', 'forceDeleteAny'] as $ability) {
                $this->assertTrue(Gate::forUser($this->superAdmin)->allows($ability, $class), "{$class} {$ability}");
                $this->assertFalse(Gate::forUser($this->scopedAdmin)->allows($ability, $class), "{$class} {$ability}");
                $this->assertFalse(Gate::forUser($this->manager)->allows($ability, $class), "{$class} {$ability}");
            }

            foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
                $this->assertTrue(Gate::forUser($this->superAdmin)->allows($ability, $record), "{$class} {$ability}");
                $this->assertFalse(Gate::forUser($this->scopedAdmin)->allows($ability, $record), "{$class} {$ability}");
            }
        }
    }

    public function test_master_data_api_mutations_are_super_admin_only_and_reads_stay_open(): void
    {
        $divisi = $this->fixtureDivisi($this->gmd);
        $position = Position::query()->create(['name' => 'POSISI API']);

        Sanctum::actingAs($this->scopedAdmin);
        $this->postJson('/api/v1/master/areas', ['name' => 'area baru'])->assertForbidden();
        $this->putJson('/api/v1/master/areas/'.$this->gmd->id, ['name' => 'rename'])->assertForbidden();
        $this->deleteJson('/api/v1/master/areas/'.$this->uls->id)->assertForbidden();
        $this->postJson('/api/v1/master/divisis', ['name' => 'div', 'area_id' => $this->uls->id])->assertForbidden();
        $this->putJson('/api/v1/master/divisis/'.$divisi->id, ['area_id' => $this->uls->id])->assertForbidden();
        $this->deleteJson('/api/v1/master/divisis/'.$divisi->id)->assertForbidden();
        $this->postJson('/api/v1/master/positions', ['name' => 'pos'])->assertForbidden();
        $this->putJson('/api/v1/master/positions/'.$position->id, ['name' => 'rename'])->assertForbidden();
        $this->deleteJson('/api/v1/master/positions/'.$position->id)->assertForbidden();
        $this->assertSame('GMD', $this->gmd->fresh()->name);
        $this->assertNotNull(Area::query()->find($this->uls->id));

        // Pembacaan master tetap terbuka untuk semua user yang login.
        Sanctum::actingAs($this->managerStaff);
        $this->getJson('/api/v1/master/areas')->assertOk();
        $this->getJson('/api/v1/master/divisis')->assertOk();
        $this->getJson('/api/v1/master/positions')->assertOk();
        $this->getJson('/api/v1/master/roles')->assertOk();

        Sanctum::actingAs($this->superAdmin);
        $this->postJson('/api/v1/master/areas', ['name' => 'area baru'])->assertCreated();
        $this->putJson('/api/v1/master/divisis/'.$divisi->id, ['name' => 'divisi edit'])->assertOk();
    }

    public function test_master_data_filament_resources_are_super_admin_only(): void
    {
        $resources = [
            AreaResource::class,
            DivisiResource::class,
            RoleResource::class,
            PositionResource::class,
            ApprovalRuleResource::class,
            KpiCategoryResource::class,
            KpiDescriptionResource::class,
            KpiReminderSettingResource::class,
        ];

        // canAccess() juga menyembunyikan menu (HasNavigation::registerNavigationItems).
        $this->actingAs($this->scopedAdmin);
        foreach ($resources as $resource) {
            $this->assertFalse($resource::canAccess(), $resource);
            $this->assertFalse($resource::canCreate(), $resource);
            $this->assertFalse($resource::canDeleteAny(), $resource);
        }
        $this->assertFalse(ApprovalRuleResource::shouldRegisterNavigation());
        $this->assertFalse(KpiReminderSettingResource::shouldRegisterNavigation());

        $this->actingAs($this->superAdmin);
        foreach ($resources as $resource) {
            $this->assertTrue($resource::canAccess(), $resource);
            $this->assertTrue($resource::canCreate(), $resource);
            $this->assertTrue($resource::canDeleteAny(), $resource);
        }
        $this->assertTrue(ApprovalRuleResource::shouldRegisterNavigation());
        $this->assertTrue(KpiReminderSettingResource::shouldRegisterNavigation());
    }

    public function test_kpi_reminder_setting_resource_guards_crud_and_test_send(): void
    {
        $setting = $this->makeReminderSetting();

        $this->actingAs($this->scopedAdmin);
        $this->assertFalse(KpiReminderSettingResource::canEdit($setting));
        $this->assertFalse(KpiReminderSettingResource::canDelete($setting));
        $this->assertFalse(KpiReminderSettingResource::canView($setting));

        Livewire::actingAs($this->scopedAdmin);
        Livewire::test(ListKpiReminderSettings::class)->assertForbidden();

        $this->actingAs($this->superAdmin);
        $this->assertTrue(KpiReminderSettingResource::canEdit($setting));
        $this->assertTrue(KpiReminderSettingResource::canDelete($setting));

        Livewire::actingAs($this->superAdmin);
        Livewire::test(ListKpiReminderSettings::class)
            ->assertSuccessful()
            ->assertTableActionVisible('test_send', $setting);
    }

    // ==========================================
    // OBSERVABILITY
    // ==========================================

    public function test_observability_is_super_admin_only(): void
    {
        $this->assertTrue(ObservabilityAccess::allowed($this->superAdmin));
        $this->assertFalse(ObservabilityAccess::allowed($this->scopedAdmin));
        $this->assertFalse(ObservabilityAccess::allowed($this->manager));

        $this->assertTrue(Gate::forUser($this->superAdmin)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($this->scopedAdmin)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($this->scopedAdmin)->allows('viewLogViewer'));
        $this->assertFalse(Gate::forUser($this->scopedAdmin)->allows('downloadLogFile'));

        $this->actingAs($this->scopedAdmin);
        $rendered = view('filament.partials.sidebar-observability')->render();
        $this->assertStringNotContainsString('/horizon', $rendered);
        $this->assertStringNotContainsString('/log-viewer', $rendered);

        $this->actingAs($this->superAdmin);
        $rendered = view('filament.partials.sidebar-observability')->render();
        $this->assertStringContainsString('/horizon', $rendered);
        $this->assertStringContainsString('/log-viewer', $rendered);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    /**
     * @param  array<User>  $users
     * @return array<int>
     */
    private function ids(array $users): array
    {
        return array_map(static fn (User $user): int => (int) $user->id, $users);
    }

    /**
     * @return array<int>
     */
    private function listedOwners(User $actor, string $uri): array
    {
        Sanctum::actingAs($actor);
        $this->resetAccessMemos();

        $separator = str_contains($uri, '?') ? '&' : '?';

        return collect($this->getJson($uri.$separator.'per_page=100')->assertOk()->json('data'))
            ->pluck('user.id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function makeDaily(User $owner): Daily
    {
        return Daily::query()->create([
            'user_id' => $owner->id,
            'date' => Date::parse('2026-10-17'),
            'task' => 'Tugas '.$owner->username,
        ]);
    }

    private function makeWeekly(User $owner): Weekly
    {
        return Weekly::query()->create([
            'user_id' => $owner->id,
            'task' => 'Mingguan '.$owner->username,
            'week' => 42,
            'year' => 2026,
            'tipe' => 'weekly',
        ]);
    }

    private function makeMonthly(User $owner): Monthly
    {
        return Monthly::query()->create([
            'user_id' => $owner->id,
            'task' => 'Bulanan '.$owner->username,
            'date' => Date::parse('2026-10-01'),
            'tipe' => 'monthly',
        ]);
    }

    private function makeRequest(User $requester, User $approver): TodoRequest
    {
        return TodoRequest::query()->create([
            'user_id' => $requester->id,
            'jenistodo' => 'daily',
            'todo_request' => 'Izin '.$requester->username,
            'todo_replace' => '',
            'approval_id' => $approver->id,
            'status' => 'PENDING',
        ]);
    }

    private function makeReminderSetting(): KpiReminderSetting
    {
        return KpiReminderSetting::query()->create([
            'type' => 'pengisian_kpi',
            'title' => 'Pengingat',
            'deadline_day' => 25,
            'reminder_days_before' => [1],
            'send_overdue_reminder' => false,
            'send_email' => false,
            'send_whatsapp' => false,
            'is_active' => true,
        ]);
    }
}
