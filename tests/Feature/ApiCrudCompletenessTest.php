<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Divisi;
use App\Models\Kpi;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\KpiReminderLog;
use App\Models\KpiType;
use App\Models\Position;
use App\Models\Role;
use App\Models\TaskCategory;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\ApprovalScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiCrudCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $staff;

    private Role $adminRole;

    private Role $staffRole;

    private Area $area;

    private Divisi $divisi;

    private Position $position;

    private KpiCategory $kpiCategory;

    private KpiType $kpiType;

    private KpiDescription $kpiDescription;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalScopeService::clearMemo();

        $this->adminRole = Role::query()->create(['name' => 'ADMIN', 'requires_approval' => false]);
        $managerRole = Role::query()->create(['name' => 'MANAGER', 'requires_approval' => true]);
        $this->staffRole = Role::query()->create(['name' => 'STAFF', 'requires_approval' => true]);

        $this->area = Area::query()->create(['name' => 'AREA CRUD']);
        $this->divisi = Divisi::query()->create([
            'name' => 'DIVISI CRUD',
            'area_id' => $this->area->id,
        ]);
        $this->position = Position::query()->create(['name' => 'POSISI CRUD']);

        TaskCategory::query()->create(['task_category' => 'Operasional']);
        TaskStatus::query()->create(['task_status' => 'Open']);

        $this->kpiCategory = KpiCategory::query()->create(['name' => 'MAIN JOB']);
        $this->kpiType = KpiType::query()->create(['name' => 'MONTHLY']);
        $this->kpiDescription = KpiDescription::query()->create([
            'description' => 'Indikator utama CRUD',
            'kpi_category_id' => $this->kpiCategory->id,
            'is_negative' => false,
        ]);

        $this->admin = $this->makeUser('admin-crud', $this->adminRole);
        $this->manager = $this->makeUser('manager-crud', $managerRole, $this->admin);
        $this->staff = $this->makeUser('staff-crud', $this->staffRole, $this->manager);

        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        ApprovalScopeService::clearMemo();
        parent::tearDown();
    }

    public function test_auth_login_profile_password_subordinates_and_logout(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'login' => $this->admin->username,
            'password' => 'complete123',
            'device_name' => 'crud-test',
        ]);
        $login->assertOk()->assertJsonPath('success', true);
        $this->assertNotEmpty($login->json('data.token'));

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.username', $this->admin->username);

        $this->putJson('/api/v1/auth/profile', [
            'email' => 'admin-crud@example.test',
        ])->assertOk()->assertJsonPath('data.email', 'admin-crud@example.test');

        Sanctum::actingAs($this->staff);
        $this->putJson('/api/v1/auth/change-password', [
            'current_password' => 'complete123',
            'new_password' => 'baru123456',
            'new_password_confirmation' => 'baru123456',
        ])->assertOk();
        $this->assertTrue(Hash::check('baru123456', $this->staff->fresh()->password));

        Sanctum::actingAs($this->manager);
        $this->getJson('/api/v1/auth/subordinates')
            ->assertOk()
            ->assertJsonPath('success', true);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_master_area_divisi_position_role_and_task_lookups(): void
    {
        $area = $this->postJson('/api/v1/master/areas', ['name' => 'area baru crud'])
            ->assertCreated()
            ->json('data');
        $this->assertSame('AREA BARU CRUD', $area['name']);

        $this->getJson('/api/v1/master/areas')->assertOk()->assertJsonPath('success', true);
        $this->getJson('/api/v1/master/areas/'.$area['id'])->assertOk();
        $this->putJson('/api/v1/master/areas/'.$area['id'], ['name' => 'area baru edit'])
            ->assertOk()
            ->assertJsonPath('data.name', 'AREA BARU EDIT');

        $divisi = $this->postJson('/api/v1/master/divisis', [
            'name' => 'divisi baru crud',
            'area_id' => $area['id'],
        ])->assertCreated()->json('data');
        $this->getJson('/api/v1/master/divisis')->assertOk();
        $this->getJson('/api/v1/master/divisis/'.$divisi['id'])->assertOk();
        $this->putJson('/api/v1/master/divisis/'.$divisi['id'], ['name' => 'divisi edit'])
            ->assertOk()
            ->assertJsonPath('data.name', 'DIVISI EDIT');

        $position = $this->postJson('/api/v1/master/positions', ['name' => 'posisi baru crud'])
            ->assertCreated()
            ->json('data');
        $this->getJson('/api/v1/master/positions')->assertOk();
        $this->getJson('/api/v1/master/positions/'.$position['id'])->assertOk();
        $this->putJson('/api/v1/master/positions/'.$position['id'], ['name' => 'posisi edit'])
            ->assertOk()
            ->assertJsonPath('data.name', 'POSISI EDIT');

        $this->deleteJson('/api/v1/master/positions/'.$position['id'])->assertOk();
        $this->deleteJson('/api/v1/master/divisis/'.$divisi['id'])->assertOk();
        $this->deleteJson('/api/v1/master/areas/'.$area['id'])->assertOk();

        $this->getJson('/api/v1/master/roles')->assertOk();
        $this->getJson('/api/v1/master/roles/'.$this->adminRole->id)->assertOk();
        $this->getJson('/api/v1/master/task-categories')->assertOk();
        $this->getJson('/api/v1/master/task-statuses')->assertOk();
    }

    public function test_users_crud_supervisors_and_json_import(): void
    {
        $created = $this->postJson('/api/v1/users', [
            'username' => 'karyawanbaru',
            'nama_lengkap' => 'Karyawan Baru CRUD',
            'email' => 'karyawanbaru@example.test',
            'password' => 'complete123',
            'role_id' => $this->staffRole->id,
            'area_id' => $this->area->id,
            'divisi_id' => $this->divisi->id,
            'position_id' => $this->position->id,
            'approval_id' => $this->manager->id,
        ])->assertCreated()->json('data');

        $this->getJson('/api/v1/users')->assertOk()->assertJsonPath('success', true);
        $this->getJson('/api/v1/users/'.$created['id'])->assertOk();
        $this->getJson('/api/v1/users/supervisors')->assertOk();

        $this->putJson('/api/v1/users/'.$created['id'], [
            'nama_lengkap' => 'Karyawan Baru Diedit',
        ])->assertOk()->assertJsonPath('data.nama_lengkap', 'Karyawan Baru Diedit');

        $file = UploadedFile::fake()->createWithContent(
            'users.json',
            json_encode([[
                'nama_lengkap' => 'Import CRUD User',
                'username' => 'importcruduser',
                'email' => 'importcrud@example.test',
                'mobile_phone' => '081288811122',
                'password' => 'complete123',
            ]], JSON_THROW_ON_ERROR),
        );

        $this->post('/api/v1/users/import-json', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('success', true);

        $this->deleteJson('/api/v1/users/'.$created['id'])->assertOk();
        $this->getJson('/api/v1/users/'.$created['id'])->assertNotFound();
    }

    public function test_kpis_details_extra_task_parent_and_period_lock(): void
    {
        $this->getJson('/api/v1/kpis/categories')->assertOk();
        $this->getJson('/api/v1/kpis/types')->assertOk();
        $this->getJson('/api/v1/kpis/descriptions')->assertOk();

        $kpi = $this->postJson('/api/v1/kpis', [
            'user_id' => $this->staff->id,
            'kpi_category_id' => $this->kpiCategory->id,
            'kpi_type_id' => $this->kpiType->id,
            'date' => Date::now()->toDateString(),
            'percentage' => 100,
            'details' => [[
                'kpi_description_id' => $this->kpiDescription->id,
                'value_plan' => 10,
                'value_actual' => 8,
            ]],
        ])->assertCreated()->json('data');

        $this->getJson('/api/v1/kpis')->assertOk();
        $this->getJson('/api/v1/kpis/'.$kpi['id'])->assertOk();
        $this->getJson('/api/v1/kpis/user/'.$this->staff->id.'/summary')->assertOk();

        $this->putJson('/api/v1/kpis/'.$kpi['id'], ['percentage' => 80])
            ->assertOk()
            ->assertJsonPath('data.percentage', 80);

        $parentId = $kpi['details'][0]['id'] ?? null;
        $this->assertNotNull($parentId);
        $extra = $this->postJson('/api/v1/kpis/'.$kpi['id'].'/details', [
            'kpi_description_id' => $this->kpiDescription->id,
            'parent_id' => $parentId,
            'is_extra_task' => true,
            'count_type' => 'NON',
        ])->assertCreated()->json('data');
        $this->assertSame($parentId, $extra['parent_id']);
        $this->assertTrue($extra['is_extra_task']);

        $this->putJson('/api/v1/kpi-details/'.$parentId, [
            'value_actual' => 10,
        ])->assertOk();

        $this->deleteJson('/api/v1/kpi-details/'.$extra['id'])->assertOk();

        $lockedKpi = Kpi::query()->create([
            'user_id' => $this->staff->id,
            'kpi_category_id' => $this->kpiCategory->id,
            'kpi_type_id' => $this->kpiType->id,
            'date' => '2020-01-15',
            'percentage' => 100,
        ]);
        $lockedDetail = $lockedKpi->kpi_detail()->create([
            'kpi_description_id' => $this->kpiDescription->id,
            'value_plan' => 5,
            'value_actual' => 1,
        ]);

        Sanctum::actingAs($this->staff);
        $this->putJson('/api/v1/kpi-details/'.$lockedDetail->id, [
            'value_actual' => 5,
        ])->assertStatus(422);

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/kpi-details/'.$lockedDetail->id, [
            'value_actual' => 5,
        ])->assertOk();

        $this->deleteJson('/api/v1/kpis/'.$kpi['id'])->assertOk();
    }

    public function test_daily_weekly_monthly_activities_and_daily_logs(): void
    {
        $this->getJson('/api/v1/activities/summary')->assertOk();

        $daily = $this->postJson('/api/v1/activities/dailies', [
            'user_id' => $this->staff->id,
            'task' => 'Tugas harian CRUD',
            'date' => Date::now()->toDateString(),
        ])->assertCreated()->json('data');
        $this->getJson('/api/v1/activities/dailies')->assertOk();
        $this->getJson('/api/v1/activities/dailies/'.$daily['id'])->assertOk();
        $this->putJson('/api/v1/activities/dailies/'.$daily['id'], [
            'task' => 'Tugas harian diedit',
        ])->assertOk();

        $log = $this->postJson('/api/v1/activities/dailies/'.$daily['id'].'/logs', [
            'activity' => 'Catatan awal',
        ])->assertCreated()->json('data');
        $this->getJson('/api/v1/activities/dailies/'.$daily['id'].'/logs')->assertOk();
        $this->putJson('/api/v1/activities/dailies/'.$daily['id'].'/logs/'.$log['id'], [
            'activity' => 'Catatan diedit',
        ])->assertOk()->assertJsonPath('data.activity', 'Catatan diedit');
        $this->deleteJson('/api/v1/activities/dailies/'.$daily['id'].'/logs/'.$log['id'])->assertOk();

        $weekly = $this->postJson('/api/v1/activities/weeklies', [
            'user_id' => $this->staff->id,
            'task' => 'Rencana mingguan CRUD',
            'week' => 12,
            'year' => 2026,
        ])->assertCreated()->json('data');
        $this->getJson('/api/v1/activities/weeklies')->assertOk();
        $this->getJson('/api/v1/activities/weeklies/'.$weekly['id'])->assertOk();
        $this->putJson('/api/v1/activities/weeklies/'.$weekly['id'], [
            'task' => 'Rencana mingguan diedit',
        ])->assertOk();

        $monthly = $this->postJson('/api/v1/activities/monthlies', [
            'user_id' => $this->staff->id,
            'task' => 'Rencana bulanan CRUD',
            'date' => '2026-09-01',
        ])->assertCreated()->json('data');
        $this->getJson('/api/v1/activities/monthlies')->assertOk();
        $this->getJson('/api/v1/activities/monthlies/'.$monthly['id'])->assertOk();
        $this->putJson('/api/v1/activities/monthlies/'.$monthly['id'], [
            'task' => 'Rencana bulanan diedit',
        ])->assertOk();

        $this->deleteJson('/api/v1/activities/dailies/'.$daily['id'])->assertOk();
        $this->deleteJson('/api/v1/activities/weeklies/'.$weekly['id'])->assertOk();
        $this->deleteJson('/api/v1/activities/monthlies/'.$monthly['id'])->assertOk();
    }

    public function test_attendances_reviews_and_integrated_evaluation(): void
    {
        $attendance = $this->postJson('/api/v1/attendances', [
            'user_id' => $this->staff->id,
            'periode' => '2099-01',
            'work_days' => 22,
            'late_less_30' => 1,
            'late_more_30' => 0,
            'sick_days' => 0,
        ])->assertCreated()->json('data');
        $this->getJson('/api/v1/attendances')->assertOk();
        $this->getJson('/api/v1/attendances/'.$attendance['id'])->assertOk();
        $this->getJson('/api/v1/attendances/user/'.$this->staff->id)->assertOk();
        $this->putJson('/api/v1/attendances/'.$attendance['id'], [
            'work_days' => 21,
        ])->assertOk();

        $review = $this->postJson('/api/v1/employee-reviews', [
            'user_id' => $this->staff->id,
            'periode' => '2099-01',
            'responsiveness' => 4,
            'problem_solver' => 4,
            'helpfulness' => 5,
            'initiative' => 3,
        ])->assertCreated()->json('data');
        $this->getJson('/api/v1/employee-reviews')->assertOk();
        $this->getJson('/api/v1/employee-reviews/'.$review['id'])->assertOk();
        $this->getJson('/api/v1/employee-reviews/user/'.$this->staff->id)->assertOk();
        $this->putJson('/api/v1/employee-reviews/'.$review['id'], [
            'initiative' => 5,
        ])->assertOk();

        $this->getJson('/api/v1/evaluations/user/'.$this->staff->id.'/score?periode=2099-01')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->deleteJson('/api/v1/attendances/'.$attendance['id'])->assertOk();
        $this->deleteJson('/api/v1/employee-reviews/'.$review['id'])->assertOk();
    }

    public function test_requests_approve_reject_and_pending_queue(): void
    {
        $approve = $this->postJson('/api/v1/requests', [
            'jenistodo' => 'daily',
            'todo_request' => 'Izin tugas CRUD approve',
        ])->assertCreated()->json('data');
        $reject = $this->postJson('/api/v1/requests', [
            'jenistodo' => 'weekly',
            'todo_request' => 'Izin tugas CRUD reject',
        ])->assertCreated()->json('data');
        $pending = $this->postJson('/api/v1/requests', [
            'jenistodo' => 'monthly',
            'todo_request' => 'Izin tugas CRUD pending',
        ])->assertCreated()->json('data');

        $this->getJson('/api/v1/requests')->assertOk();
        $this->getJson('/api/v1/requests/pending-approvals')->assertOk();
        $this->getJson('/api/v1/requests/'.$approve['id'])->assertOk();
        $this->putJson('/api/v1/requests/'.$pending['id'], [
            'todo_request' => 'Izin tugas CRUD pending diedit',
        ])->assertOk();

        $this->postJson('/api/v1/requests/'.$approve['id'].'/approve')->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');
        $this->postJson('/api/v1/requests/'.$reject['id'].'/reject')->assertOk()
            ->assertJsonPath('data.status', 'REJECTED');
        $this->deleteJson('/api/v1/requests/'.$pending['id'])->assertOk();
    }

    public function test_overopens_and_cutpoints_crud(): void
    {
        $overopen = $this->postJson('/api/v1/overopens', [
            'user_id' => $this->staff->id,
            'week' => 12,
            'year' => 2026,
            'keterangan' => 'Terlambat input',
        ])->assertCreated()->json('data');
        $this->assertSame(0, (int) $overopen['point']);
        $this->getJson('/api/v1/overopens')->assertOk();
        $this->getJson('/api/v1/overopens/'.$overopen['id'])->assertOk();
        $this->putJson('/api/v1/overopens/'.$overopen['id'], [
            'keterangan' => 'Terlambat input diedit',
        ])->assertOk();
        $this->deleteJson('/api/v1/overopens/'.$overopen['id'])->assertOk();

        $cutpoint = $this->postJson('/api/v1/cutpoints', [
            'user_id' => $this->staff->id,
            'point' => 3,
            'periode' => '2099-01',
            'keterangan' => 'Potongan tes CRUD',
        ])->assertCreated()->json('data');
        $this->getJson('/api/v1/cutpoints')->assertOk();
        $this->getJson('/api/v1/cutpoints/'.$cutpoint['id'])->assertOk();
        $this->getJson('/api/v1/cutpoints/user/'.$this->staff->id)->assertOk();
        $this->putJson('/api/v1/cutpoints/'.$cutpoint['id'], [
            'point' => 4,
        ])->assertOk();
        $this->deleteJson('/api/v1/cutpoints/'.$cutpoint['id'])->assertOk();
    }

    public function test_analytics_leaderboard_dashboard_and_checklist(): void
    {
        $this->getJson('/api/v1/analytics/leaderboard?periode=2099-01')->assertOk();
        $this->getJson('/api/v1/analytics/dashboard?periode=2099-01')->assertOk();
        $this->getJson('/api/v1/analytics/department-stats?periode=2099-01')->assertOk();
        $this->getJson('/api/v1/analytics/kpi-checklist?user_id='.$this->staff->id.'&periode=2099-01')
            ->assertOk()
            ->assertJsonPath('data.lock_status.is_locked', false);
        $this->getJson('/api/v1/analytics/kpi-checklist?user_id='.$this->staff->id.'&periode=2020-01')
            ->assertOk()
            ->assertJsonPath('data.lock_status.is_locked', true);
        $this->get('/api/v1/analytics/leaderboard/export?periode=2099-01', [
            'Accept' => 'application/json',
        ])->assertOk();
    }

    public function test_reminder_settings_logs_toggle_and_dry_run(): void
    {
        $setting = $this->postJson('/api/v1/reminders/settings', [
            'type' => 'pengisian_kpi',
            'title' => 'Pengingat CRUD',
            'deadline_day' => 25,
            'reminder_days_before' => [3, 1, 0],
            'send_email' => true,
            'send_whatsapp' => false,
            'email_template' => 'Halo {nama}, isi KPI.',
            'is_active' => true,
        ])->assertCreated()->json('data');
        $this->assertSame('Halo {nama}, isi KPI.', $setting['email_template']);

        $this->getJson('/api/v1/reminders/settings')->assertOk();
        $this->getJson('/api/v1/reminders/settings/'.$setting['id'])->assertOk();
        $this->putJson('/api/v1/reminders/settings/'.$setting['id'], [
            'title' => 'Pengingat CRUD diedit',
        ])->assertOk()->assertJsonPath('data.title', 'Pengingat CRUD diedit');

        $this->postJson('/api/v1/reminders/trigger', [
            'setting_id' => $setting['id'],
            'dry_run' => true,
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v1/reminders/settings/'.$setting['id'].'/toggle')->assertOk();

        $this->postJson('/api/v1/reminders/send-test', [
            'channel' => 'email',
            'destination' => 'crud-test@example.test',
            'message' => 'Tes email CRUD',
        ])->assertOk();

        $log = KpiReminderLog::query()->create([
            'user_id' => $this->staff->id,
            'kpi_reminder_setting_id' => $setting['id'],
            'channel' => 'email',
            'recipient' => 'crud-test@example.test',
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        $this->getJson('/api/v1/reminders/logs')->assertOk();
        $this->getJson('/api/v1/reminders/logs/'.$log->id)->assertOk();

        $this->deleteJson('/api/v1/reminders/settings/'.$setting['id'])->assertOk();
    }

    public function test_work_journals_crud_today_and_team(): void
    {
        $this->getJson('/api/v1/journals/today')->assertOk();
        $this->getJson('/api/v1/journals/team')->assertOk();

        $journal = $this->postJson('/api/v1/journals', [
            'user_id' => $this->staff->id,
            'date' => '2099-01-15',
            'activity' => 'Aktivitas jurnal CRUD',
            'notes' => 'Catatan',
        ])->assertCreated()->json('data');
        $this->getJson('/api/v1/journals')->assertOk();
        $this->getJson('/api/v1/journals/'.$journal['id'])->assertOk();
        $this->putJson('/api/v1/journals/'.$journal['id'], [
            'activity' => 'Aktivitas jurnal diedit',
        ])->assertOk()->assertJsonPath('data.activity', 'Aktivitas jurnal diedit');
        $this->deleteJson('/api/v1/journals/'.$journal['id'])->assertOk();
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
