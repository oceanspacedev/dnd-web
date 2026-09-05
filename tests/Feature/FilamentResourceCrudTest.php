<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\KpiStaffGuide;
use App\Filament\Pages\KpiSupervisorGuide;
use App\Filament\Pages\UserPositionGuide;
use App\Filament\Resources\Areas\Pages\ManageAreas;
use App\Filament\Resources\Attendances\Pages\CreateAttendance;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\Cutpoints\Pages\ManageCutpoints;
use App\Filament\Resources\Divisis\Pages\ManageDivisis;
use App\Filament\Resources\EmployeeReviews\Pages\CreateEmployeeReview;
use App\Filament\Resources\EmployeeReviews\Pages\ListEmployeeReviews;
use App\Filament\Resources\KpiCategories\Pages\ManageKpiCategories;
use App\Filament\Resources\KpiDescriptions\Pages\ManageKpiDescriptions;
use App\Filament\Resources\KpiReminderSettings\Pages\CreateKpiReminderSetting;
use App\Filament\Resources\KpiReminderSettings\Pages\ListKpiReminderSettings;
use App\Filament\Resources\Kpis\Pages\CreateKpi;
use App\Filament\Resources\Kpis\Pages\ListKpis;
use App\Filament\Resources\Positions\Pages\ManagePositions;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\WorkJournals\Pages\ManageWorkJournals;
use App\Filament\Widgets\ChecklistKPI;
use App\Filament\Widgets\LeaderboardKPI;
use App\Models\Area;
use App\Models\Attendance;
use App\Models\Cutpoint;
use App\Models\Divisi;
use App\Models\EmployeeReview;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\KpiReminderSetting;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkJournal;
use App\Services\ApprovalScopeService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentResourceCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Role $staffRole;

    private Area $area;

    private Divisi $divisi;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalScopeService::clearMemo();

        $adminRole = Role::query()->create(['name' => 'ADMIN', 'requires_approval' => false]);
        $this->staffRole = Role::query()->create(['name' => 'STAFF', 'requires_approval' => true]);
        $this->area = Area::query()->create(['name' => 'AREA PANEL']);
        $this->divisi = Divisi::query()->create([
            'name' => 'DIVISI PANEL',
            'area_id' => $this->area->id,
        ]);
        $this->position = Position::query()->create(['name' => 'POSISI PANEL']);

        $this->admin = $this->makeUser('admin-panel', $adminRole);
        $this->staff = $this->makeUser('staff-panel', $this->staffRole, $this->admin);

        $this->actingAs($this->admin);
        Livewire::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        ApprovalScopeService::clearMemo();
        parent::tearDown();
    }

    public function test_dashboard_guides_and_all_resource_pages_render(): void
    {
        Livewire::test(Dashboard::class)->assertSuccessful();
        Livewire::test(ChecklistKPI::class)->assertSuccessful();
        Livewire::test(LeaderboardKPI::class)->assertSuccessful();
        Livewire::test(KpiStaffGuide::class)->assertSuccessful();
        Livewire::test(KpiSupervisorGuide::class)->assertSuccessful();
        Livewire::test(UserPositionGuide::class)->assertSuccessful();

        Livewire::test(ListUsers::class)->assertSuccessful();
        Livewire::test(CreateUser::class)->assertSuccessful();
        Livewire::test(ListKpis::class)->assertSuccessful();
        Livewire::test(CreateKpi::class)->assertSuccessful();
        Livewire::test(ListAttendances::class)->assertSuccessful();
        Livewire::test(CreateAttendance::class)->assertSuccessful();
        Livewire::test(ListEmployeeReviews::class)->assertSuccessful();
        Livewire::test(CreateEmployeeReview::class)->assertSuccessful();
        Livewire::test(ListKpiReminderSettings::class)->assertSuccessful();
        Livewire::test(CreateKpiReminderSetting::class)->assertSuccessful();
        Livewire::test(ManageAreas::class)->assertSuccessful();
        Livewire::test(ManageDivisis::class)->assertSuccessful();
        Livewire::test(ManagePositions::class)->assertSuccessful();
        Livewire::test(ManageRoles::class)->assertSuccessful();
        Livewire::test(ManageKpiCategories::class)->assertSuccessful();
        Livewire::test(ManageKpiDescriptions::class)->assertSuccessful();
        Livewire::test(ManageCutpoints::class)->assertSuccessful();
        Livewire::test(ManageWorkJournals::class)->assertSuccessful();
    }

    public function test_panel_can_crud_areas_divisis_positions_roles_and_kpi_masters(): void
    {
        Livewire::test(ManageAreas::class)
            ->callAction('create', ['name' => 'AREA LIVEWIRE'])
            ->assertHasNoActionErrors();
        $area = Area::query()->where('name', 'AREA LIVEWIRE')->firstOrFail();

        Livewire::test(ManageAreas::class)
            ->callAction(TestAction::make('edit')->table($area), ['name' => 'AREA LIVEWIRE EDIT'])
            ->assertHasNoActionErrors();
        $this->assertSame('AREA LIVEWIRE EDIT', $area->fresh()->name);

        Livewire::test(ManageDivisis::class)
            ->callAction('create', [
                'name' => 'DIVISI LIVEWIRE',
                'area_id' => $area->id,
            ])
            ->assertHasNoActionErrors();
        $divisi = Divisi::query()->where('name', 'DIVISI LIVEWIRE')->firstOrFail();
        Livewire::test(ManageDivisis::class)
            ->callAction(TestAction::make('edit')->table($divisi), [
                'name' => 'DIVISI LIVEWIRE EDIT',
                'area_id' => $area->id,
            ])
            ->assertHasNoActionErrors();

        Livewire::test(ManagePositions::class)
            ->callAction('create', ['name' => 'POSISI LIVEWIRE'])
            ->assertHasNoActionErrors();
        $position = Position::query()->where('name', 'POSISI LIVEWIRE')->firstOrFail();
        Livewire::test(ManagePositions::class)
            ->callAction(TestAction::make('edit')->table($position), ['name' => 'POSISI LIVEWIRE EDIT'])
            ->assertHasNoActionErrors();

        Livewire::test(ManageRoles::class)
            ->callAction('create', [
                'name' => 'ROLE LIVEWIRE',
                'requires_approval' => false,
            ])
            ->assertHasNoActionErrors();
        $role = Role::query()->where('name', 'ROLE LIVEWIRE')->firstOrFail();
        Livewire::test(ManageRoles::class)
            ->callAction(TestAction::make('edit')->table($role), [
                'name' => 'ROLE LIVEWIRE EDIT',
                'requires_approval' => true,
            ])
            ->assertHasNoActionErrors();

        Livewire::test(ManageKpiCategories::class)
            ->callAction('create', ['name' => 'KATEGORI LIVEWIRE'])
            ->assertHasNoActionErrors();
        $category = KpiCategory::query()->where('name', 'KATEGORI LIVEWIRE')->firstOrFail();

        Livewire::test(ManageKpiDescriptions::class)
            ->callAction('create', [
                'description' => 'Deskripsi Livewire',
                'kpi_category_id' => $category->id,
                'is_negative' => false,
            ])
            ->assertHasNoActionErrors();
        $description = KpiDescription::query()->where('description', 'Deskripsi Livewire')->firstOrFail();

        Livewire::test(ManageKpiDescriptions::class)
            ->callAction(TestAction::make('delete')->table($description))
            ->assertHasNoActionErrors();
        Livewire::test(ManageKpiCategories::class)
            ->callAction(TestAction::make('delete')->table($category))
            ->assertHasNoActionErrors();
        Livewire::test(ManagePositions::class)
            ->callAction(TestAction::make('delete')->table($position))
            ->assertHasNoActionErrors();
        Livewire::test(ManageDivisis::class)
            ->callAction(TestAction::make('delete')->table($divisi))
            ->assertHasNoActionErrors();
        Livewire::test(ManageAreas::class)
            ->callAction(TestAction::make('delete')->table($area))
            ->assertHasNoActionErrors();
        Livewire::test(ManageRoles::class)
            ->callAction(TestAction::make('delete')->table($role))
            ->assertHasNoActionErrors();
    }

    public function test_panel_can_create_and_edit_users_attendance_reviews_journals_cutpoints_and_reminders(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'nama_lengkap' => 'User Panel Baru',
                'username' => 'userpanelbaru',
                'password' => 'complete123',
                'area_id' => $this->area->id,
                'divisi_id' => $this->divisi->id,
                'role_id' => $this->staffRole->id,
                'position_id' => $this->position->id,
                'approval_id' => $this->admin->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertDatabaseHas('users', ['username' => 'userpanelbaru']);

        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'user_id' => $this->staff->id,
                'periode' => '2099-02',
                'work_days' => 20,
                'late_less_30' => 0,
                'late_more_30' => 0,
                'sick_days' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(Attendance::query()->where('user_id', $this->staff->id)->where('periode', '2099-02')->exists());

        Livewire::test(CreateEmployeeReview::class)
            ->fillForm([
                'user_id' => $this->staff->id,
                'periode' => '2099-02',
                'responsiveness' => 4,
                'problem_solver' => 4,
                'helpfulness' => 4,
                'initiative' => 4,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(EmployeeReview::query()->where('user_id', $this->staff->id)->where('periode', '2099-02')->exists());

        Livewire::test(ManageWorkJournals::class)
            ->callAction('create', [
                'date' => '2099-02-10',
                'activity' => 'Jurnal panel CRUD',
                'notes' => 'Catatan panel',
            ])
            ->assertHasNoActionErrors();
        $journal = WorkJournal::query()->where('activity', 'Jurnal panel CRUD')->firstOrFail();
        Livewire::test(ManageWorkJournals::class)
            ->callAction(TestAction::make('edit')->table($journal), [
                'date' => '2099-02-10',
                'activity' => 'Jurnal panel diedit',
            ])
            ->assertHasNoActionErrors();
        $this->assertSame('Jurnal panel diedit', $journal->fresh()->activity);

        Livewire::test(ManageCutpoints::class)
            ->callAction('create', [
                'user_id' => $this->staff->id,
                'periode' => '2099-02',
                'point' => 2,
                'keterangan' => 'Potongan panel',
            ])
            ->assertHasNoActionErrors();
        $this->assertTrue(Cutpoint::query()->where('keterangan', 'Potongan panel')->exists());

        Livewire::test(CreateKpiReminderSetting::class)
            ->fillForm([
                'title' => 'Pengingat panel CRUD',
                'type' => 'pengisian_kpi',
                'deadline_day' => 20,
                'is_active' => true,
                'send_email' => true,
                'send_whatsapp' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(KpiReminderSetting::query()->where('title', 'Pengingat panel CRUD')->exists());
    }

    private function makeUser(string $username, Role $role, ?User $approval = null): User
    {
        return User::query()->create([
            'nama_lengkap' => strtoupper($username),
            'username' => $username,
            'email' => $username.'@example.test',
            'password' => Hash::make('complete123'),
            'role_id' => $role->id,
            'area_id' => $this->area->id,
            'divisi_id' => $this->divisi->id,
            'position_id' => $this->position->id,
            'approval_id' => $approval?->id,
            'd' => true,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);
    }
}
