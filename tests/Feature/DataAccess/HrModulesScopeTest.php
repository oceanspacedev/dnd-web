<?php

namespace Tests\Feature\DataAccess;

use App\Exports\AttendanceExport;
use App\Exports\AttendanceImportTemplateExport;
use App\Exports\EmployeeReviewExport;
use App\Exports\EmployeeReviewImportTemplateExport;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Attendances\Pages\CreateAttendance;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\Cutpoints\CutpointResource;
use App\Filament\Resources\Cutpoints\Pages\ManageCutpoints;
use App\Filament\Resources\EmployeeReviews\EmployeeReviewResource;
use App\Filament\Resources\EmployeeReviews\Pages\CreateEmployeeReview;
use App\Filament\Resources\EmployeeReviews\Pages\ListEmployeeReviews;
use App\Filament\Resources\WorkJournals\Pages\ManageWorkJournals;
use App\Filament\Resources\WorkJournals\WorkJournalResource;
use App\Imports\AttendanceImport;
use App\Imports\EmployeeReviewImport;
use App\Models\Area;
use App\Models\Attendance;
use App\Models\Cutpoint;
use App\Models\EmployeeReview;
use App\Models\User;
use App\Models\WorkJournal;
use App\Policies\AttendancePolicy;
use App\Policies\EmployeeReviewPolicy;
use App\Policies\WorkJournalPolicy;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Akses data per badan usaha untuk Kehadiran, Penilaian, Cutpoint, dan Jurnal Harian.
 *
 * Skenario: admin per badan usaha "ojan" (homebase COO) hanya boleh ULS, punya satu anggota tim
 * di COO; karyawan COO lain dan tim manager GMD berada di luar cakupannya.
 */
class HrModulesScopeTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private Area $coo;

    private Area $uls;

    private Area $gmd;

    private User $superAdmin;

    private User $scopedAdmin;

    private User $ulsStaff;

    private User $cooTeam;

    private User $cooOutsider;

    private User $manager;

    private User $managedStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();

        $this->coo = $this->fixtureArea('COO');
        $this->uls = $this->fixtureArea('ULS');
        $this->gmd = $this->fixtureArea('GMD');

        $this->superAdmin = $this->fixtureGlobalAdmin('super_admin', $this->coo);
        $this->scopedAdmin = $this->fixtureScopedAdmin('ojan', $this->coo, [$this->uls]);
        $this->ulsStaff = $this->fixtureUser('uls_staff', 'STAFF', $this->uls, null, ['employee_id' => 'EMP-ULS']);
        $this->cooTeam = $this->fixtureUser('coo_team', 'STAFF', $this->coo, $this->scopedAdmin, ['employee_id' => 'EMP-TEAM']);
        $this->cooOutsider = $this->fixtureUser('coo_outsider', 'STAFF', $this->coo, null, ['employee_id' => 'EMP-OUT']);
        $this->manager = $this->fixtureUser('gmd_manager', 'MANAGER', $this->gmd);
        $this->managedStaff = $this->fixtureUser('gmd_staff', 'STAFF', $this->gmd, $this->manager, ['employee_id' => 'EMP-GMD']);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Kehadiran
    // ---------------------------------------------------------------------

    public function test_attendance_api_index_is_scoped_per_actor(): void
    {
        $records = $this->createAttendances('2026-09');

        Sanctum::actingAs($this->superAdmin);
        $this->assertEqualsCanonicalizing(
            array_values($records),
            collect($this->getJson('/api/v1/attendances?per_page=100')->assertOk()->json('data'))->pluck('id')->all(),
        );

        Sanctum::actingAs($this->scopedAdmin);
        $this->assertEqualsCanonicalizing(
            [$records['uls'], $records['team'], $records['self']],
            collect($this->getJson('/api/v1/attendances?per_page=100')->assertOk()->json('data'))->pluck('id')->all(),
        );

        // Non-admin tidak berubah: manager hanya melihat bawahan approval.
        Sanctum::actingAs($this->manager);
        $this->assertSame(
            [$records['managed']],
            collect($this->getJson('/api/v1/attendances?per_page=100')->assertOk()->json('data'))->pluck('id')->all(),
        );
    }

    public function test_attendance_api_denies_out_of_scope_records_and_targets_for_scoped_admin(): void
    {
        $records = $this->createAttendances('2026-09');

        Sanctum::actingAs($this->scopedAdmin);

        $this->getJson("/api/v1/attendances/{$records['uls']}")->assertOk();
        $this->getJson("/api/v1/attendances/{$records['outsider']}")->assertForbidden();
        $this->getJson("/api/v1/attendances/{$records['managed']}")->assertForbidden();
        $this->putJson("/api/v1/attendances/{$records['outsider']}", ['work_days' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/attendances/{$records['outsider']}")->assertForbidden();
        $this->getJson("/api/v1/attendances/user/{$this->cooOutsider->id}")->assertForbidden();
        $this->getJson("/api/v1/attendances/user/{$this->cooTeam->id}")->assertOk();

        $this->postJson('/api/v1/attendances', [
            'user_id' => $this->cooOutsider->id,
            'periode' => '2026-10',
            'work_days' => 20,
        ])->assertForbidden();

        $this->postJson('/api/v1/attendances', [
            'user_id' => $this->ulsStaff->id,
            'periode' => '2026-10',
            'work_days' => 20,
        ])->assertCreated();
    }

    public function test_attendance_global_username_exception_still_sees_everything(): void
    {
        $records = $this->createAttendances('2026-09');
        $darkini = $this->fixtureUser('darkini', 'COORDINATOR', $this->coo);
        $this->resetAccessMemos();

        Sanctum::actingAs($darkini);

        $this->assertEqualsCanonicalizing(
            array_values($records),
            collect($this->getJson('/api/v1/attendances?per_page=100')->assertOk()->json('data'))->pluck('id')->all(),
        );
        $this->assertNull(AttendancePolicy::visibleUserIds($darkini));
    }

    public function test_attendance_panel_list_options_and_create_are_scoped_for_scoped_admin(): void
    {
        $records = $this->createAttendances('2026-09');
        Attendance::query()->create($this->attendanceRow($this->cooOutsider, '2025-01'));

        $this->actingAs($this->scopedAdmin);
        Livewire::actingAs($this->scopedAdmin);

        $this->assertEqualsCanonicalizing(
            [$records['uls'], $records['team'], $records['self']],
            AttendanceResource::getEloquentQuery()->pluck('id')->all(),
        );

        $list = Livewire::test(ListAttendances::class)
            ->loadTable()
            ->assertCanSeeTableRecords(Attendance::query()->whereKey([$records['uls'], $records['team'], $records['self']])->get())
            ->assertCanNotSeeTableRecords(Attendance::query()->whereKey([$records['outsider'], $records['managed']])->get());

        // Periode filter hanya dari data yang terlihat (2025-01 milik karyawan di luar cakupan).
        $this->assertSame(
            ['2026-09' => '2026-09'],
            $list->instance()->getTable()->getFilter('periode')->getOptions(),
        );

        // Karyawan di luar cakupan ditolak di server walau dikirim lewat state Livewire.
        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'user_id' => $this->cooOutsider->id,
                'periode' => '2026-11',
                'work_days' => 20,
                'late_less_30' => 0,
                'late_more_30' => 0,
                'sick_days' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['user_id']);
        $this->assertFalse(Attendance::query()->where('user_id', $this->cooOutsider->id)->where('periode', '2026-11')->exists());

        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'user_id' => $this->ulsStaff->id,
                'periode' => '2026-11',
                'work_days' => 20,
                'late_less_30' => 0,
                'late_more_30' => 0,
                'sick_days' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(Attendance::query()->where('user_id', $this->ulsStaff->id)->where('periode', '2026-11')->exists());

        // Halaman edit record di luar cakupan tidak bisa dibuka.
        $this->get(AttendanceResource::getUrl('edit', ['record' => $records['outsider']]))->assertNotFound();
        $this->get(AttendanceResource::getUrl('edit', ['record' => $records['uls']]))->assertOk();

        // Edit tidak bisa memindahkan data ke karyawan di luar cakupan.
        Livewire::test(EditAttendance::class, ['record' => $records['uls']])
            ->fillForm(['user_id' => $this->cooOutsider->id])
            ->call('save')
            ->assertHasFormErrors(['user_id']);
        $this->assertSame($this->ulsStaff->id, (int) Attendance::query()->findOrFail($records['uls'])->user_id);
    }

    public function test_attendance_exports_and_import_follow_actor_scope(): void
    {
        $this->createAttendances('2026-09');

        $scopedNames = (new AttendanceExport(AttendancePolicy::visibleUserIds($this->scopedAdmin)))
            ->collection()->pluck('nama_lengkap')->all();
        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->nama_lengkap, $this->cooTeam->nama_lengkap, $this->scopedAdmin->nama_lengkap],
            $scopedNames,
        );

        // Manager hanya mengekspor bawahannya (sebelumnya seluruh perusahaan).
        $this->assertSame(
            [$this->managedStaff->nama_lengkap],
            (new AttendanceExport(AttendancePolicy::visibleUserIds($this->manager)))->collection()->pluck('nama_lengkap')->all(),
        );
        $this->assertCount(5, (new AttendanceExport(AttendancePolicy::visibleUserIds($this->superAdmin)))->collection());

        $templateIds = (new AttendanceImportTemplateExport(AttendancePolicy::visibleUserIds($this->scopedAdmin)))
            ->collection()->pluck('employee_id')->filter()->values()->all();
        $this->assertEqualsCanonicalizing(['EMP-ULS', 'EMP-TEAM'], $templateIds);
        $this->assertContains('EMP-OUT', (new AttendanceImportTemplateExport)->collection()->pluck('employee_id')->all());

        $import = new AttendanceImport(AttendancePolicy::visibleUserIds($this->scopedAdmin));
        $this->assertNull($import->model(['EMP-OUT', $this->cooOutsider->nama_lengkap, '2026-12', 20, 0, 0, 0]));
        $this->assertInstanceOf(Attendance::class, $import->model(['EMP-ULS', $this->ulsStaff->nama_lengkap, '2026-12', 20, 0, 0, 0]));
        $this->assertSame(1, $import->getImportSummary()['skippedCount']);
    }

    public function test_attendance_policy_menu_access(): void
    {
        $emptyAdmin = $this->fixtureScopedAdmin('empty_admin', $this->coo, []);
        $staff = $this->fixtureUser('plain_staff', 'STAFF', $this->coo);

        $this->assertTrue($this->superAdmin->can('viewAny', Attendance::class));
        $this->assertTrue($this->scopedAdmin->can('viewAny', Attendance::class));
        $this->assertTrue($this->scopedAdmin->can('create', Attendance::class));
        $this->assertFalse($emptyAdmin->can('viewAny', Attendance::class));
        $this->assertTrue($this->manager->can('viewAny', Attendance::class));
        $this->assertFalse($staff->can('viewAny', Attendance::class));

        $this->assertTrue($this->scopedAdmin->can('update', new Attendance(['user_id' => $this->cooTeam->id])));
        $this->assertFalse($this->scopedAdmin->can('update', new Attendance(['user_id' => $this->cooOutsider->id])));
        $this->assertFalse($this->manager->can('update', new Attendance(['user_id' => $this->ulsStaff->id])));
    }

    // ---------------------------------------------------------------------
    // Penilaian
    // ---------------------------------------------------------------------

    public function test_employee_review_api_is_scoped_and_denies_out_of_scope_targets(): void
    {
        $records = $this->createReviews('2026-09');

        Sanctum::actingAs($this->superAdmin);
        $this->assertCount(5, $this->getJson('/api/v1/employee-reviews?per_page=100')->assertOk()->json('data'));

        Sanctum::actingAs($this->scopedAdmin);
        $this->assertEqualsCanonicalizing(
            [$records['uls'], $records['team'], $records['self']],
            collect($this->getJson('/api/v1/employee-reviews?per_page=100')->assertOk()->json('data'))->pluck('id')->all(),
        );
        $this->getJson("/api/v1/employee-reviews/{$records['outsider']}")->assertForbidden();
        $this->getJson("/api/v1/employee-reviews/user/{$this->cooOutsider->id}")->assertForbidden();
        $this->getJson("/api/v1/evaluations/user/{$this->cooOutsider->id}/score?periode=2026-09")->assertForbidden();
        $this->getJson("/api/v1/evaluations/user/{$this->ulsStaff->id}/score?periode=2026-09")->assertOk();
        $this->postJson('/api/v1/employee-reviews', $this->reviewPayload($this->cooOutsider, '2026-10'))->assertForbidden();
        $this->postJson('/api/v1/employee-reviews', $this->reviewPayload($this->ulsStaff, '2026-10'))->assertCreated();

        Sanctum::actingAs($this->manager);
        $this->assertSame(
            [$records['managed']],
            collect($this->getJson('/api/v1/employee-reviews?per_page=100')->assertOk()->json('data'))->pluck('id')->all(),
        );
    }

    public function test_employee_review_panel_and_exports_are_scoped(): void
    {
        $records = $this->createReviews('2026-09');
        $indirect = $this->fixtureUser('gmd_indirect', 'STAFF', $this->gmd, $this->managedStaff, ['employee_id' => 'EMP-IND']);
        $this->resetAccessMemos();

        $this->actingAs($this->scopedAdmin);
        Livewire::actingAs($this->scopedAdmin);

        $this->assertEqualsCanonicalizing(
            [$records['uls'], $records['team'], $records['self']],
            EmployeeReviewResource::getEloquentQuery()->pluck('id')->all(),
        );

        Livewire::test(ListEmployeeReviews::class)
            ->loadTable()
            ->assertCanNotSeeTableRecords(EmployeeReview::query()->whereKey([$records['outsider'], $records['managed']])->get());

        Livewire::test(CreateEmployeeReview::class)
            ->fillForm($this->reviewPayload($this->cooOutsider, '2026-11'))
            ->call('create')
            ->assertHasFormErrors(['user_id']);
        $this->assertFalse(EmployeeReview::query()->where('user_id', $this->cooOutsider->id)->where('periode', '2026-11')->exists());

        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->nama_lengkap, $this->cooTeam->nama_lengkap, $this->scopedAdmin->nama_lengkap],
            (new EmployeeReviewExport(EmployeeReviewPolicy::visibleUserIds($this->scopedAdmin)))->collection()->pluck('nama_lengkap')->all(),
        );

        // Template: super admin mendapat seluruh karyawan (cek role lama selalu gagal), manager
        // mendapat tim approval satu level ke bawah seperti daftar Penilaian.
        $allTemplateIds = (new EmployeeReviewImportTemplateExport(EmployeeReviewPolicy::visibleUserIds($this->superAdmin)))
            ->collection()->pluck('employee_id')->filter()->values()->all();
        $this->assertEqualsCanonicalizing(['EMP-ULS', 'EMP-TEAM', 'EMP-OUT', 'EMP-GMD', 'EMP-IND'], $allTemplateIds);

        $managerTemplateIds = (new EmployeeReviewImportTemplateExport(EmployeeReviewPolicy::visibleUserIds($this->manager)))
            ->collection()->pluck('employee_id')->all();
        $this->assertEqualsCanonicalizing(['EMP-GMD', 'EMP-IND'], $managerTemplateIds);

        $scopedTemplateIds = (new EmployeeReviewImportTemplateExport(EmployeeReviewPolicy::visibleUserIds($this->scopedAdmin)))
            ->collection()->pluck('employee_id')->filter()->values()->all();
        $this->assertEqualsCanonicalizing(['EMP-ULS', 'EMP-TEAM'], $scopedTemplateIds);

        $import = new EmployeeReviewImport(EmployeeReviewPolicy::visibleUserIds($this->scopedAdmin));
        $this->assertNull($import->model($this->reviewImportRow('EMP-OUT', $this->cooOutsider, '2026-12')));
        $this->assertInstanceOf(EmployeeReview::class, $import->model($this->reviewImportRow('EMP-ULS', $this->ulsStaff, '2026-12')));
        $this->assertNotNull($indirect->id);
    }

    // ---------------------------------------------------------------------
    // Cutpoint
    // ---------------------------------------------------------------------

    public function test_cutpoint_api_and_panel_query_are_scoped(): void
    {
        $records = $this->createCutpoints();

        Sanctum::actingAs($this->superAdmin);
        $this->assertCount(5, $this->getJson('/api/v1/cutpoints?per_page=100')->assertOk()->json('data'));

        Sanctum::actingAs($this->scopedAdmin);
        $this->assertEqualsCanonicalizing(
            [$records['uls'], $records['team'], $records['self']],
            collect($this->getJson('/api/v1/cutpoints?per_page=100')->assertOk()->json('data'))->pluck('id')->all(),
        );
        $this->getJson("/api/v1/cutpoints/{$records['outsider']}")->assertForbidden();
        $this->putJson("/api/v1/cutpoints/{$records['outsider']}", ['point' => 1])->assertForbidden();
        $this->getJson("/api/v1/cutpoints/user/{$this->cooOutsider->id}")->assertForbidden();
        $this->postJson('/api/v1/cutpoints', [
            'user_id' => $this->cooOutsider->id,
            'point' => 1,
            'periode' => '2026-10',
            'keterangan' => 'Di luar cakupan',
        ])->assertForbidden();
        $created = $this->postJson('/api/v1/cutpoints', [
            'user_id' => $this->ulsStaff->id,
            'point' => 1,
            'periode' => '2026-10',
            'keterangan' => 'Dalam cakupan',
        ])->assertCreated()->json('data.id');

        // Non-admin tanpa pengecualian id 3 tetap hanya melihat cutpoint miliknya.
        Sanctum::actingAs($this->cooOutsider);
        $this->assertSame(
            [$records['outsider']],
            collect($this->getJson('/api/v1/cutpoints?per_page=100')->assertOk()->json('data'))->pluck('id')->all(),
        );

        $this->actingAs($this->scopedAdmin);
        $this->assertEqualsCanonicalizing(
            [$records['uls'], $records['team'], $records['self'], $created],
            CutpointResource::getEloquentQuery()->pluck('id')->all(),
        );
    }

    public function test_cutpoint_user_picker_label_and_rule_do_not_resolve_out_of_scope_users(): void
    {
        $this->actingAs($this->scopedAdmin);
        Livewire::actingAs($this->scopedAdmin);

        // Opsi form (tulis) tanpa akun ADMIN, termasuk dirinya; filter daftar tetap mengikuti cakupan lihat.
        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->cooTeam->id],
            CutpointResource::scopeSelectableUsers(User::query(), $this->scopedAdmin)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->cooTeam->id, $this->scopedAdmin->id],
            CutpointResource::scopeSelectableUsers(User::query(), $this->scopedAdmin, writable: false)->pluck('id')->all(),
        );
        $this->assertTrue(CutpointResource::canSelectUser($this->scopedAdmin, (string) $this->ulsStaff->id));
        $this->assertFalse(CutpointResource::canSelectUser($this->scopedAdmin, (string) $this->cooOutsider->id));
        $this->assertFalse(CutpointResource::canSelectUser($this->scopedAdmin, $this->ulsStaff->id.'abc'));
        $this->assertTrue(CutpointResource::canSelectUser($this->superAdmin, $this->cooOutsider->id));
        $this->assertFalse(CutpointResource::canSelectUser($this->manager, $this->ulsStaff->id));
        $this->assertTrue(CutpointResource::canSelectUser($this->manager, $this->managedStaff->id));

        Livewire::test(ManageCutpoints::class)
            ->callAction('create', [
                'user_id' => $this->cooOutsider->id,
                'periode' => '2026-10',
                'point' => 2,
                'keterangan' => 'Potongan di luar cakupan',
            ])
            ->assertHasActionErrors(['user_id']);
        $this->assertFalse(Cutpoint::query()->where('keterangan', 'Potongan di luar cakupan')->exists());

        // afterStateUpdated tidak membocorkan posisi/divisi karyawan di luar cakupan.
        Livewire::test(ManageCutpoints::class)
            ->mountAction('create')
            ->fillForm(['user_id' => $this->cooOutsider->id])
            ->assertSchemaStateSet(['user_divisi' => '-'], 'mountedActionSchema0');

        Livewire::test(ManageCutpoints::class)
            ->callAction('create', [
                'user_id' => $this->ulsStaff->id,
                'periode' => '2026-10',
                'point' => 2,
                'keterangan' => 'Potongan dalam cakupan',
            ])
            ->assertHasNoActionErrors();
        $this->assertTrue(Cutpoint::query()->where('keterangan', 'Potongan dalam cakupan')->where('user_id', $this->ulsStaff->id)->exists());
    }

    public function test_cutpoint_policy_for_scoped_admin(): void
    {
        $inScope = new Cutpoint(['user_id' => $this->ulsStaff->id]);
        $outOfScope = new Cutpoint(['user_id' => $this->cooOutsider->id]);

        $this->assertTrue($this->scopedAdmin->can('create', Cutpoint::class));
        $this->assertTrue($this->scopedAdmin->can('update', $inScope));
        $this->assertTrue($this->scopedAdmin->can('restore', $inScope));
        $this->assertFalse($this->scopedAdmin->can('update', $outOfScope));
        $this->assertFalse($this->scopedAdmin->can('delete', $outOfScope));
        $this->assertFalse($this->scopedAdmin->can('forceDelete', $outOfScope));
        $this->assertTrue($this->superAdmin->can('forceDelete', $outOfScope));

        // Non-admin (bukan id 3) tidak bisa membuat/mengubah cutpoint.
        $this->assertFalse($this->manager->can('create', Cutpoint::class));
        $this->assertFalse($this->manager->can('update', new Cutpoint(['user_id' => $this->managedStaff->id])));
    }

    // ---------------------------------------------------------------------
    // Jurnal Harian
    // ---------------------------------------------------------------------

    public function test_work_journal_api_index_and_team_are_scoped(): void
    {
        $records = $this->createJournals();
        $superTeam = $this->fixtureUser('super_team', 'STAFF', $this->gmd, $this->superAdmin);
        $superTeamJournal = $this->journal($superTeam, '2026-09-01');
        $this->resetAccessMemos();

        Sanctum::actingAs($this->superAdmin);
        $this->assertCount(6, $this->getJson('/api/v1/journals?per_page=100')->assertOk()->json('data'));
        // /journals/team untuk super admin tetap seluruh jurnal, seperti sebelum fitur akses data.
        $teamIds = collect($this->getJson('/api/v1/journals/team?per_page=100')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([...array_values($records), $superTeamJournal->id], $teamIds);

        Sanctum::actingAs($this->scopedAdmin);
        $this->assertEqualsCanonicalizing(
            [$records['uls'], $records['team'], $records['self']],
            collect($this->getJson('/api/v1/journals?per_page=100')->assertOk()->json('data'))->pluck('id')->all(),
        );
        // Admin badan usaha: /journals/team = seluruh cakupan akses datanya (badan usaha + tim + diri sendiri).
        $this->assertEqualsCanonicalizing(
            [$records['uls'], $records['team'], $records['self']],
            collect($this->getJson('/api/v1/journals/team?per_page=100')->assertOk()->json('data'))->pluck('id')->all(),
        );
        $this->getJson("/api/v1/journals/{$records['outsider']}")->assertForbidden();
        $this->putJson("/api/v1/journals/{$records['outsider']}", ['activity' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/journals/{$records['uls']}", ['activity' => 'Diedit admin badan usaha'])->assertOk();

        // Non-admin tidak berubah: diri sendiri + bawahan.
        Sanctum::actingAs($this->manager);
        $this->assertEqualsCanonicalizing(
            [$records['managed']],
            collect($this->getJson('/api/v1/journals?per_page=100')->assertOk()->json('data'))->pluck('id')->all(),
        );
    }

    public function test_work_journal_api_store_validates_owner_for_scoped_admin(): void
    {
        $this->journal($this->cooOutsider, '2026-09-10');

        Sanctum::actingAs($this->scopedAdmin);

        // Ditolak tanpa membocorkan apakah karyawan di luar cakupan sudah punya jurnal di tanggal itu.
        $this->postJson('/api/v1/journals', [
            'user_id' => $this->cooOutsider->id,
            'date' => '2026-09-10',
            'activity' => 'Di luar cakupan',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['user_id'])
            ->assertJsonMissingValidationErrors(['date']);

        $this->postJson('/api/v1/journals', [
            'user_id' => $this->ulsStaff->id,
            'date' => '2026-09-10',
            'activity' => 'Dalam cakupan',
        ])->assertCreated();
        $this->assertDatabaseHas('work_journals', ['activity' => 'Dalam cakupan', 'user_id' => $this->ulsStaff->id]);

        // Super admin tetap boleh mencatat untuk karyawan mana pun.
        Sanctum::actingAs($this->superAdmin);
        $this->postJson('/api/v1/journals', [
            'user_id' => $this->cooOutsider->id,
            'date' => '2026-09-11',
            'activity' => 'Oleh super admin',
        ])->assertCreated();
        $this->assertDatabaseHas('work_journals', ['activity' => 'Oleh super admin', 'user_id' => $this->cooOutsider->id]);
    }

    public function test_work_journal_panel_scope_owner_enforcement_and_bulk_delete(): void
    {
        $records = $this->createJournals();

        $this->actingAs($this->scopedAdmin);
        $this->assertEqualsCanonicalizing(
            [$records['uls'], $records['team'], $records['self']],
            WorkJournalResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->cooTeam->id, $this->scopedAdmin->id],
            WorkJournalPolicy::scopeVisible(User::query(), $this->scopedAdmin, 'users.id')->pluck('id')->all(),
        );

        // Pemilik jurnal baru selalu user login, walau state form dimanipulasi.
        $this->actingAs($this->manager);
        Livewire::actingAs($this->manager);
        Livewire::test(ManageWorkJournals::class)
            ->callAction('create', [
                'user_id' => $this->cooOutsider->id,
                'date' => '2026-09-20',
                'activity' => 'Percobaan impersonasi panel',
            ])
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('work_journals', ['activity' => 'Percobaan impersonasi panel', 'user_id' => $this->manager->id]);
        $this->assertDatabaseMissing('work_journals', ['activity' => 'Percobaan impersonasi panel', 'user_id' => $this->cooOutsider->id]);

        // Edit tidak bisa memindahkan pemilik jurnal.
        $own = WorkJournal::query()->where('activity', 'Percobaan impersonasi panel')->firstOrFail();
        Livewire::test(ManageWorkJournals::class)
            ->callAction(TestAction::make('edit')->table($own), [
                'user_id' => $this->cooOutsider->id,
                'date' => '2026-09-20',
                'activity' => 'Diedit',
            ])
            ->assertHasNoActionErrors();
        $this->assertSame($this->manager->id, (int) $own->fresh()->user_id);

        // Atasan tidak bisa menghapus jurnal bawahan lewat aksi massal.
        $managedJournal = WorkJournal::query()->findOrFail($records['managed']);
        Livewire::test(ManageWorkJournals::class)
            ->callTableBulkAction('delete', [$managedJournal, $own]);
        $this->assertNotSoftDeleted('work_journals', ['id' => $managedJournal->id]);
        $this->assertSoftDeleted('work_journals', ['id' => $own->id]);

        // Admin per badan usaha boleh mengubah jurnal dalam cakupannya, tidak di luar.
        $this->assertTrue($this->scopedAdmin->can('update', WorkJournal::query()->findOrFail($records['uls'])));
        $this->assertFalse($this->scopedAdmin->can('update', WorkJournal::query()->findOrFail($records['outsider'])));
        $this->assertFalse($this->manager->can('update', $managedJournal));
        $this->assertTrue($this->manager->can('view', $managedJournal));
    }

    // ---------------------------------------------------------------------
    // Hasil review: target store, akun ADMIN, data sendiri, query per baris
    // ---------------------------------------------------------------------

    public function test_api_store_rejects_array_user_id_without_leaking_other_rows(): void
    {
        $attendances = $this->createAttendances('2026-09');
        $reviews = $this->createReviews('2026-09');

        // (int) [X] === 1: user id 1 masuk tim manager, sehingga cek akses lama lolos untuk id 1
        // sementara cek duplikat memakai elemen pertama array (karyawan di luar tim).
        $userOne = User::query()->findOrFail(1);
        $this->assertNotSame($this->manager->id, $userOne->id);
        $userOne->update(['approval_id' => $this->manager->id]);
        $this->resetAccessMemos();

        Sanctum::actingAs($this->manager);

        $this->postJson('/api/v1/attendances', [
            'user_id' => [$this->cooOutsider->id],
            'periode' => '2026-09',
            'work_days' => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['user_id'])
            ->assertJsonMissingPath('data');

        $this->postJson('/api/v1/employee-reviews', array_merge(
            $this->reviewPayload($this->cooOutsider, '2026-09'),
            ['user_id' => [$this->cooOutsider->id]],
        ))->assertStatus(422)
            ->assertJsonValidationErrors(['user_id'])
            ->assertJsonMissingPath('data');

        Sanctum::actingAs($this->scopedAdmin);
        $this->postJson('/api/v1/cutpoints', [
            'user_id' => [$this->ulsStaff->id],
            'point' => 1,
            'periode' => '2026-10',
            'keterangan' => 'Array',
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        // Id berupa string angka tetap diterima.
        $this->postJson('/api/v1/cutpoints', [
            'user_id' => (string) $this->ulsStaff->id,
            'point' => 1,
            'periode' => '2026-10',
            'keterangan' => 'String angka',
        ])->assertCreated();

        Sanctum::actingAs($this->superAdmin);
        $this->postJson('/api/v1/journals', [
            'user_id' => [$this->cooOutsider->id],
            'date' => '2026-09-15',
            'activity' => 'Array',
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        // Data di luar cakupan tidak berubah.
        $this->assertSame(20, (int) Attendance::query()->findOrFail($attendances['outsider'])->work_days);
        $this->assertNotNull(EmployeeReview::query()->find($reviews['outsider']));
    }

    public function test_scoped_admin_cannot_write_records_of_admin_accounts_or_own_records(): void
    {
        $ulsSuper = $this->fixtureGlobalAdmin('uls_super', $this->uls);
        $ulsPeer = $this->fixtureScopedAdmin('uls_peer', $this->uls, [$this->uls]);
        $attendances = $this->createAttendances('2026-09');
        $superAttendance = Attendance::query()->create($this->attendanceRow($ulsSuper, '2026-09'))->id;
        $peerReview = EmployeeReview::query()->create($this->reviewPayload($ulsPeer, '2026-09'))->id;
        $cutpoints = $this->createCutpoints();
        $superCutpoint = Cutpoint::query()->create([
            'user_id' => $ulsSuper->id, 'point' => 1, 'periode' => '2026-09', 'keterangan' => 'Super',
        ])->id;
        $journals = $this->createJournals();
        $superJournal = $this->journal($ulsSuper, '2026-09-01')->id;
        $this->resetAccessMemos();

        Sanctum::actingAs($this->scopedAdmin);

        // Lihat tetap boleh (badan usaha + tim + diri sendiri, termasuk akun ADMIN di badan usahanya).
        $this->getJson("/api/v1/attendances/{$superAttendance}")->assertOk();
        $this->getJson("/api/v1/attendances/{$attendances['self']}")->assertOk();
        $this->getJson("/api/v1/journals/{$superJournal}")->assertOk();

        // Tulis data akun ADMIN lain ditolak.
        $this->putJson("/api/v1/attendances/{$superAttendance}", ['work_days' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/attendances/{$superAttendance}")->assertForbidden();
        $this->putJson("/api/v1/employee-reviews/{$peerReview}", ['initiative' => 5])->assertForbidden();
        $this->deleteJson("/api/v1/cutpoints/{$superCutpoint}")->assertForbidden();
        $this->putJson("/api/v1/journals/{$superJournal}", ['activity' => 'Diubah'])->assertForbidden();
        $this->postJson('/api/v1/attendances', $this->attendanceRow($ulsSuper, '2026-10'))->assertForbidden();
        $this->postJson('/api/v1/cutpoints', [
            'user_id' => $ulsSuper->id, 'point' => 5, 'periode' => '2026-10', 'keterangan' => 'Ke super admin',
        ])->assertForbidden();
        $this->postJson('/api/v1/journals', [
            'user_id' => $ulsSuper->id, 'date' => '2026-09-02', 'activity' => 'Atas nama super admin',
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        // Data sendiri (kehadiran, penilaian, cutpoint) tidak boleh diubah sendiri.
        $this->putJson("/api/v1/attendances/{$attendances['self']}", ['late_more_30' => 0])->assertForbidden();
        $this->deleteJson("/api/v1/cutpoints/{$cutpoints['self']}")->assertForbidden();
        $this->postJson('/api/v1/attendances', $this->attendanceRow($this->scopedAdmin, '2026-10'))->assertForbidden();
        $this->postJson('/api/v1/employee-reviews', $this->reviewPayload($this->scopedAdmin, '2026-10'))->assertForbidden();

        // Jurnal sendiri tetap milik sendiri.
        $this->putJson("/api/v1/journals/{$journals['self']}", ['activity' => 'Jurnal sendiri'])->assertOk();
        $this->postJson('/api/v1/journals', [
            'user_id' => $this->scopedAdmin->id, 'date' => '2026-09-03', 'activity' => 'Jurnal sendiri baru',
        ])->assertCreated();

        // Karyawan biasa dalam cakupan tetap bisa dikelola.
        $this->putJson("/api/v1/attendances/{$attendances['uls']}", ['work_days' => 19])->assertOk();
        $this->deleteJson("/api/v1/cutpoints/{$cutpoints['uls']}")->assertOk();

        // Opsi form, template, dan import tanpa akun ADMIN.
        $writable = [$this->ulsStaff->id, $this->cooTeam->id];
        $this->assertEqualsCanonicalizing($writable, AttendancePolicy::scopeWritableUsers(User::query(), $this->scopedAdmin)->pluck('id')->all());
        $this->assertEqualsCanonicalizing($writable, EmployeeReviewPolicy::scopeWritableUsers(User::query(), $this->scopedAdmin)->pluck('id')->all());
        $this->assertEqualsCanonicalizing($writable, AttendancePolicy::writableUserIds($this->scopedAdmin));
        $this->assertEqualsCanonicalizing($writable, EmployeeReviewPolicy::writableUserIds($this->scopedAdmin));
        $this->assertContains($ulsSuper->id, AttendancePolicy::visibleUserIds($this->scopedAdmin));
        $this->assertFalse(AttendancePolicy::canManageUser($this->scopedAdmin, $this->scopedAdmin->id));
        $this->assertFalse(EmployeeReviewPolicy::canManageUser($this->scopedAdmin, (string) $ulsPeer->id));
        $this->assertFalse(CutpointResource::canSelectUser($this->scopedAdmin, $ulsSuper->id));
        $this->assertFalse(CutpointResource::canSelectUser($this->scopedAdmin, $this->scopedAdmin->id));
        $this->assertFalse($this->scopedAdmin->can('restore', Cutpoint::query()->findOrFail($cutpoints['self'])));

        // Jalur relasi pemilik yang sudah dimuat (tabel panel) memberi hasil yang sama.
        $this->assertFalse($this->scopedAdmin->can('update', WorkJournal::with('user.role')->findOrFail($superJournal)));
        $this->assertTrue($this->scopedAdmin->can('update', WorkJournal::with('user.role')->findOrFail($journals['self'])));
        $this->assertTrue($this->scopedAdmin->can('view', WorkJournal::with('user.role')->findOrFail($superJournal)));
        $this->assertFalse($this->scopedAdmin->can('delete', Attendance::with('user.role')->findOrFail($superAttendance)));
        $this->assertFalse($this->scopedAdmin->can('update', EmployeeReview::with('user.role')->findOrFail($peerReview)));
        $this->assertFalse($this->scopedAdmin->can('delete', Cutpoint::with('user.role')->findOrFail($cutpoints['self'])));
        $this->assertTrue($this->scopedAdmin->can('delete', Attendance::with('user.role')->findOrFail($attendances['uls'])));

        $import = new AttendanceImport(AttendancePolicy::writableUserIds($this->scopedAdmin));
        $this->assertNull($import->model(['', $this->scopedAdmin->nama_lengkap, '2026-12', 20, 0, 0, 0]));
        $this->assertFalse(Attendance::query()->where('user_id', $this->scopedAdmin->id)->where('periode', '2026-12')->exists());

        // Panel: create untuk diri sendiri ditolak, bulk delete melewati data sendiri.
        $this->actingAs($this->scopedAdmin, 'web');
        Livewire::actingAs($this->scopedAdmin, 'web');
        Livewire::test(CreateAttendance::class)
            ->fillForm($this->attendanceRow($this->scopedAdmin, '2026-11'))
            ->call('create')
            ->assertHasFormErrors(['user_id']);
        $this->assertFalse(Attendance::query()->where('user_id', $this->scopedAdmin->id)->where('periode', '2026-11')->exists());
        $this->get(AttendanceResource::getUrl('edit', ['record' => $attendances['self']]))->assertForbidden();

        Livewire::test(ListAttendances::class)
            ->loadTable()
            ->callTableBulkAction('delete', Attendance::query()->whereKey([$attendances['self'], $attendances['team']])->get());
        $this->assertNotSoftDeleted('attendances', ['id' => $attendances['self']]);
        $this->assertSoftDeleted('attendances', ['id' => $attendances['team']]);

        // Super admin tetap mengelola semuanya, termasuk datanya sendiri.
        $this->assertTrue($this->superAdmin->can('update', new Attendance(['user_id' => $this->superAdmin->id])));
        $this->assertTrue($this->superAdmin->can('delete', Cutpoint::query()->findOrFail($superCutpoint)));

        // Non-admin tidak berubah: manager tetap mengelola akun ADMIN di tim approval-nya.
        $teamAdmin = $this->fixtureGlobalAdmin('gmd_team_admin', $this->gmd);
        $teamAdmin->update(['approval_id' => $this->manager->id]);
        $this->resetAccessMemos();
        $this->assertTrue($this->manager->can('update', new Attendance(['user_id' => $teamAdmin->id])));
        $this->assertTrue(AttendancePolicy::canManageUser($this->manager, $teamAdmin->id));
        $this->assertTrue($this->manager->can('update', new EmployeeReview(['user_id' => $teamAdmin->id])));
    }

    public function test_scoped_admin_row_policy_checks_do_not_query_per_row(): void
    {
        $owners = collect(range(1, 6))->map(fn (int $i): User => $this->fixtureUser("uls_extra_{$i}", 'STAFF', $this->uls));
        foreach ($owners as $owner) {
            Attendance::query()->create($this->attendanceRow($owner, '2026-09'));
            EmployeeReview::query()->create($this->reviewPayload($owner, '2026-09'));
            Cutpoint::query()->create(['user_id' => $owner->id, 'point' => 1, 'periode' => '2026-09', 'keterangan' => 'X']);
            $this->journal($owner, '2026-09-01');
        }

        // Pemilik yang sudah dihapus (relasi user Attendance tanpa withTrashed) tetap dicek lewat user_id.
        $deletedOwner = $this->fixtureUser('uls_deleted', 'STAFF', $this->uls);
        $deletedAttendance = Attendance::query()->create($this->attendanceRow($deletedOwner, '2026-09'));
        $deletedOwner->delete();
        $this->resetAccessMemos();

        $actor = User::query()->findOrFail($this->scopedAdmin->id);
        $this->actingAs($actor);

        foreach ([AttendanceResource::class, EmployeeReviewResource::class, CutpointResource::class, WorkJournalResource::class] as $resource) {
            $records = $resource::getEloquentQuery()->whereIn('user_id', $owners->pluck('id'))->get();
            $this->assertCount(6, $records, $resource);

            // Memo akses (badan usaha, tim, role actor) terisi oleh baris pertama.
            $this->assertTrue($actor->can('update', $records->first()), $resource);

            DB::flushQueryLog();
            DB::enableQueryLog();
            foreach ($records as $record) {
                $this->assertTrue($actor->can('view', $record), $resource);
                $this->assertTrue($actor->can('update', $record), $resource);
                $this->assertTrue($actor->can('delete', $record), $resource);
            }
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $this->assertSame([], $queries, $resource.' menjalankan query per baris.');
        }

        $deletedAttendance = AttendanceResource::getEloquentQuery()->findOrFail($deletedAttendance->id);
        $this->assertNull($deletedAttendance->user);
        $this->assertTrue($actor->can('view', $deletedAttendance));
        $this->assertTrue($actor->can('update', $deletedAttendance));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return array<string, int>
     */
    private function createAttendances(string $periode): array
    {
        return collect($this->owners())
            ->map(fn (User $owner): int => Attendance::query()->create($this->attendanceRow($owner, $periode))->id)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function createReviews(string $periode): array
    {
        return collect($this->owners())
            ->map(fn (User $owner): int => EmployeeReview::query()->create($this->reviewPayload($owner, $periode))->id)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function createCutpoints(): array
    {
        return collect($this->owners())
            ->map(fn (User $owner): int => Cutpoint::query()->create([
                'user_id' => $owner->id,
                'point' => 1,
                'periode' => '2026-09',
                'keterangan' => 'Cutpoint '.$owner->username,
            ])->id)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function createJournals(): array
    {
        return collect($this->owners())
            ->map(fn (User $owner): int => $this->journal($owner, '2026-09-01')->id)
            ->all();
    }

    private function journal(User $owner, string $date): WorkJournal
    {
        return WorkJournal::query()->create([
            'user_id' => $owner->id,
            'date' => $date,
            'activity' => 'Jurnal '.$owner->username.' '.$date,
        ]);
    }

    /**
     * @return array<string, User>
     */
    private function owners(): array
    {
        return [
            'uls' => $this->ulsStaff,
            'team' => $this->cooTeam,
            'self' => $this->scopedAdmin,
            'outsider' => $this->cooOutsider,
            'managed' => $this->managedStaff,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceRow(User $owner, string $periode): array
    {
        return [
            'user_id' => $owner->id,
            'periode' => $periode,
            'work_days' => 20,
            'late_less_30' => 0,
            'late_more_30' => 0,
            'sick_days' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewPayload(User $owner, string $periode): array
    {
        return [
            'user_id' => $owner->id,
            'periode' => $periode,
            'responsiveness' => 4,
            'problem_solver' => 4,
            'helpfulness' => 4,
            'initiative' => 4,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewImportRow(string $employeeId, User $owner, string $periode): array
    {
        return [
            'id_karyawan' => $employeeId,
            'nama_lengkap' => $owner->nama_lengkap,
            'periode' => $periode,
            'responsiveness' => 4,
            'problem_solver' => 4,
            'helpfulness' => 4,
            'initiative' => 4,
        ];
    }
}
