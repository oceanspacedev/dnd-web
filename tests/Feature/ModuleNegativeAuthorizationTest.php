<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Cutpoint;
use App\Models\Daily;
use App\Models\EmployeeReview;
use App\Models\Kpi;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\KpiReminderLog;
use App\Models\KpiReminderSetting;
use App\Models\KpiType;
use App\Models\Monthly;
use App\Models\Overopen;
use App\Models\Request as TodoRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Weekly;
use App\Models\WorkJournal;
use App\Services\ApprovalScopeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleNegativeAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearEloquentGuardableColumns();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        ApprovalScopeService::clearMemo();

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('nama_lengkap');
            $table->string('username')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('no_hp')->nullable()->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('divisis', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('area_id')->nullable();
            $table->timestamps();
        });

        Schema::create('task_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('task_category');
            $table->timestamps();
        });

        Schema::create('task_status', function (Blueprint $table): void {
            $table->id();
            $table->string('task_status');
            $table->timestamps();
        });

        Schema::create('dailies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->dateTime('date');
            $table->string('task');
            $table->string('time')->nullable();
            $table->boolean('status')->default(false);
            $table->double('ontime')->default(0);
            $table->boolean('isplan')->default(true);
            $table->boolean('isupdate')->default(false);
            $table->foreignId('tag_id')->nullable();
            $table->foreignId('add_id')->nullable();
            $table->string('task_category_id')->nullable();
            $table->string('task_status_id')->nullable();
            $table->bigInteger('value_plan')->nullable();
            $table->bigInteger('value_actual')->nullable();
            $table->boolean('status_result')->nullable();
            $table->double('value')->default(0);
            $table->string('tipe')->default('NON');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('daily_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('task_id');
            $table->text('activity');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('weeklies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('task');
            $table->integer('week');
            $table->integer('year');
            $table->string('tipe');
            $table->bigInteger('value_plan')->nullable();
            $table->bigInteger('value_actual')->nullable();
            $table->boolean('status_non')->nullable();
            $table->boolean('status_result')->nullable();
            $table->double('value')->default(0);
            $table->boolean('is_add')->default(false);
            $table->boolean('is_update')->default(false);
            $table->foreignId('tag_id')->nullable();
            $table->foreignId('add_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('monthlies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('task');
            $table->timestamp('date')->nullable();
            $table->string('tipe');
            $table->bigInteger('value_plan')->nullable();
            $table->bigInteger('value_actual')->nullable();
            $table->boolean('status_non')->nullable();
            $table->boolean('status_result')->nullable();
            $table->double('value')->default(0);
            $table->boolean('is_add')->default(false);
            $table->boolean('is_update')->default(false);
            $table->foreignId('tag_id')->nullable();
            $table->foreignId('add_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('overopens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('atasan')->nullable();
            $table->integer('week');
            $table->integer('year');
            $table->integer('daily')->nullable();
            $table->integer('weekly')->nullable();
            $table->integer('monthly')->nullable();
            $table->integer('point')->nullable();
            $table->string('keterangan')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('kpi_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('kpi_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('kpi_descriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kpi_category_id');
            $table->string('description');
            $table->boolean('is_negative')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kpis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('kpi_category_id');
            $table->foreignId('kpi_type_id');
            $table->dateTime('date');
            $table->double('percentage')->default(100);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('kpi_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kpi_id');
            $table->foreignId('kpi_description_id');
            $table->string('count_type')->default('NON');
            $table->double('value_plan')->nullable();
            $table->double('value_actual')->nullable();
            $table->double('value_result')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('periode');
            $table->integer('work_days');
            $table->integer('late_less_30')->default(0);
            $table->integer('late_more_30')->default(0);
            $table->integer('sick_days')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('employee_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('periode');
            $table->tinyInteger('responsiveness')->default(0);
            $table->tinyInteger('problem_solver')->default(0);
            $table->tinyInteger('helpfulness')->default(0);
            $table->tinyInteger('initiative')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cutpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->integer('point');
            $table->string('periode');
            $table->string('keterangan');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('work_journals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->date('date');
            $table->text('activity');
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['user_id', 'date']);
        });

        Schema::create('requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('jenistodo');
            $table->string('todo_request');
            $table->string('todo_replace');
            $table->foreignId('approval_id');
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kpi_reminder_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->enum('type', ['pembuatan_kpi', 'pengisian_kpi'])->default('pengisian_kpi');
            $table->unsignedTinyInteger('deadline_day')->default(25);
            $table->json('reminder_days_before')->nullable();
            $table->boolean('send_overdue_reminder')->default(true);
            $table->boolean('send_email')->default(true);
            $table->boolean('send_whatsapp')->default(true);
            $table->string('email_subject')->default('Pengingat KPI - DnD System');
            $table->text('email_body')->nullable();
            $table->text('whatsapp_template')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('kpi_reminder_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kpi_reminder_setting_id');
            $table->foreignId('user_id');
            $table->enum('channel', ['email', 'whatsapp']);
            $table->string('recipient');
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        ApprovalScopeService::clearMemo();
        $this->clearEloquentGuardableColumns();

        Schema::dropIfExists('kpi_reminder_logs');
        Schema::dropIfExists('kpi_reminder_settings');
        Schema::dropIfExists('requests');
        Schema::dropIfExists('overopens');
        Schema::dropIfExists('monthlies');
        Schema::dropIfExists('weeklies');
        Schema::dropIfExists('daily_logs');
        Schema::dropIfExists('dailies');
        Schema::dropIfExists('task_status');
        Schema::dropIfExists('task_categories');
        Schema::dropIfExists('divisis');
        Schema::dropIfExists('areas');
        Schema::dropIfExists('work_journals');
        Schema::dropIfExists('cutpoints');
        Schema::dropIfExists('employee_reviews');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('kpi_details');
        Schema::dropIfExists('kpis');
        Schema::dropIfExists('kpi_descriptions');
        Schema::dropIfExists('kpi_types');
        Schema::dropIfExists('kpi_categories');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');

        parent::tearDown();
    }

    public function test_login_route_is_rate_limited_after_five_failed_attempts_per_minute(): void
    {
        $role = Role::query()->create(['name' => 'STAFF']);
        $user = $this->createUser('user-rate', $role);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'login' => $user->username,
                'password' => 'wrong-password',
            ]);
            $response->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->username,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_activity_endpoints_are_scoped_to_owner_and_approval_scope(): void
    {
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $manager = $this->createUser('activity-manager', $managerRole);
        $managed = $this->createUser('activity-managed', $staffRole, $manager);
        $outside = $this->createUser('activity-outside', $staffRole);

        $managedDaily = Daily::query()->create([
            'user_id' => $managed->id,
            'date' => '2026-09-01',
            'task' => 'Tugas bawahan',
        ]);
        $outsideDaily = Daily::query()->create([
            'user_id' => $outside->id,
            'date' => '2026-09-01',
            'task' => 'Tugas di luar scope',
        ]);
        $managedWeekly = Weekly::query()->create([
            'user_id' => $managed->id,
            'task' => 'Rencana bawahan',
            'week' => 36,
            'year' => 2026,
            'tipe' => 'weekly',
        ]);
        $outsideWeekly = Weekly::query()->create([
            'user_id' => $outside->id,
            'task' => 'Rencana di luar scope',
            'week' => 36,
            'year' => 2026,
            'tipe' => 'weekly',
        ]);
        $managedMonthly = Monthly::query()->create([
            'user_id' => $managed->id,
            'task' => 'Target bawahan',
            'date' => '2026-09-01',
            'tipe' => 'monthly',
        ]);
        $outsideMonthly = Monthly::query()->create([
            'user_id' => $outside->id,
            'task' => 'Target di luar scope',
            'date' => '2026-09-01',
            'tipe' => 'monthly',
        ]);

        Sanctum::actingAs($manager);

        foreach ([
            ['/api/v1/activities/dailies', $managedDaily->id, $outsideDaily->id],
            ['/api/v1/activities/weeklies', $managedWeekly->id, $outsideWeekly->id],
            ['/api/v1/activities/monthlies', $managedMonthly->id, $outsideMonthly->id],
        ] as [$uri, $visibleId, $hiddenId]) {
            $response = $this->getJson($uri)->assertOk();
            $ids = collect($response->json('data'))->pluck('id')->all();
            $this->assertContains($visibleId, $ids);
            $this->assertNotContains($hiddenId, $ids);
        }

        $this->getJson("/api/v1/activities/dailies/{$outsideDaily->id}")->assertForbidden();
        $this->getJson("/api/v1/activities/weeklies/{$outsideWeekly->id}")->assertForbidden();
        $this->getJson("/api/v1/activities/monthlies/{$outsideMonthly->id}")->assertForbidden();

        $this->postJson('/api/v1/activities/dailies', [
            'user_id' => $outside->id,
            'task' => 'Impersonasi daily',
            'date' => '2026-09-02',
        ])->assertForbidden();
        $this->postJson('/api/v1/activities/weeklies', [
            'user_id' => $outside->id,
            'task' => 'Impersonasi weekly',
            'week' => 37,
            'year' => 2026,
        ])->assertForbidden();
        $this->postJson('/api/v1/activities/monthlies', [
            'user_id' => $outside->id,
            'task' => 'Impersonasi monthly',
            'date' => '2026-10-01',
        ])->assertForbidden();
    }

    public function test_overopen_endpoints_reject_records_outside_the_authenticated_scope(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $actor = $this->createUser('overopen-actor', $staffRole);
        $outside = $this->createUser('overopen-outside', $staffRole);

        $outsideOveropen = Overopen::query()->create([
            'user_id' => $outside->id,
            'atasan' => $actor->id,
            'week' => 36,
            'year' => 2026,
            'daily' => 1,
            'weekly' => 0,
            'monthly' => 0,
            'point' => 1,
            'keterangan' => 'Di luar scope',
        ]);

        Sanctum::actingAs($actor);

        $response = $this->getJson('/api/v1/overopens')->assertOk();
        $this->assertSame(0, $response->json('meta.total'));
        $this->getJson("/api/v1/overopens/{$outsideOveropen->id}")->assertForbidden();
        $this->postJson('/api/v1/overopens', [
            'user_id' => $outside->id,
            'week' => 37,
            'year' => 2026,
            'point' => 1,
        ])->assertForbidden();
        $this->putJson("/api/v1/overopens/{$outsideOveropen->id}", [
            'point' => 2,
        ])->assertForbidden();
        $this->deleteJson("/api/v1/overopens/{$outsideOveropen->id}")->assertForbidden();
    }

    public function test_master_data_mutations_are_admin_only(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $actor = $this->createUser('master-data-actor', $staffRole);
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/master/areas', ['name' => 'Tidak boleh'])->assertForbidden();
        $this->postJson('/api/v1/master/divisis', [
            'name' => 'Tidak boleh',
            'area_id' => 1,
        ])->assertForbidden();
        $this->postJson('/api/v1/master/positions', ['name' => 'Tidak boleh'])->assertForbidden();
    }

    public function test_kpi_checklist_rejects_a_user_outside_the_approval_scope(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $actor = $this->createUser('analytics-actor', $staffRole);
        $outside = $this->createUser('analytics-outside', $staffRole);
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/analytics/kpi-checklist?user_id='.$outside->id)
            ->assertForbidden();
        $this->getJson('/api/v1/evaluations/user/'.$outside->id.'/score')
            ->assertForbidden();
    }

    public function test_user_json_import_is_admin_only(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $actor = $this->createUser('import-actor', $staffRole);
        Sanctum::actingAs($actor);

        $file = UploadedFile::fake()->createWithContent('users.json', '[]');

        $this->post('/api/v1/users/import-json', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertForbidden();
    }

    public function test_kpi_endpoints_reject_unauthorized_regular_staff(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $actor = $this->createUser('actor-staff', $staffRole);
        $owner = $this->createUser('kpi-owner', $managerRole);

        $category = KpiCategory::query()->create([
            'name' => 'Output',
            'description' => 'Tes KPI kategori',
        ]);
        $type = KpiType::query()->create(['name' => 'Utama']);
        KpiDescription::query()->create([
            'kpi_category_id' => $category->id,
            'description' => 'Deskripsi',
            'is_negative' => false,
        ]);

        $kpi = Kpi::query()->create([
            'user_id' => $owner->id,
            'kpi_category_id' => $category->id,
            'kpi_type_id' => $type->id,
            'date' => now(),
            'percentage' => 100,
        ]);

        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/kpis')->assertForbidden();
        $this->getJson("/api/v1/kpis/{$kpi->id}")->assertForbidden();
    }

    public function test_kpi_scope_blocks_outside_records_and_creation_targets(): void
    {
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $manager = $this->createUser('kpi-scope-manager', $managerRole);
        $managed = $this->createUser('kpi-scope-managed', $staffRole, $manager);
        $outside = $this->createUser('kpi-scope-outside', $staffRole);

        $category = KpiCategory::query()->create(['name' => 'Scope Category']);
        $type = KpiType::query()->create(['name' => 'Scope Type']);
        $description = KpiDescription::query()->create([
            'kpi_category_id' => $category->id,
            'description' => 'Scope indicator',
            'is_negative' => false,
        ]);

        $managedKpi = Kpi::query()->create([
            'user_id' => $managed->id,
            'kpi_category_id' => $category->id,
            'kpi_type_id' => $type->id,
            'date' => now(),
            'percentage' => 100,
        ]);
        $outsideKpi = Kpi::query()->create([
            'user_id' => $outside->id,
            'kpi_category_id' => $category->id,
            'kpi_type_id' => $type->id,
            'date' => now(),
            'percentage' => 100,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/kpis')->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame([$managedKpi->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($outsideKpi->id, collect($response->json('data'))->pluck('id')->all());

        $this->postJson('/api/v1/kpis', [
            'user_id' => $outside->id,
            'kpi_category_id' => $category->id,
            'kpi_type_id' => $type->id,
            'date' => '2026-09-01',
            'percentage' => 100,
            'details' => [[
                'kpi_description_id' => $description->id,
                'value_plan' => 1,
            ]],
        ])->assertForbidden();

        $this->putJson("/api/v1/kpis/{$managedKpi->id}", [
            'user_id' => $outside->id,
        ])->assertForbidden();
    }

    public function test_attendance_endpoints_reject_unauthorized_regular_staff(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $actor = $this->createUser('attendance-actor', $staffRole);
        $target = $this->createUser('attendance-target', $staffRole);

        $attendance = Attendance::query()->create([
            'user_id' => $target->id,
            'periode' => '2026-09',
            'work_days' => 20,
            'late_less_30' => 0,
            'late_more_30' => 0,
            'sick_days' => 0,
        ]);

        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/attendances')->assertForbidden();
        $this->getJson("/api/v1/attendances/{$attendance->id}")->assertForbidden();
        $this->postJson('/api/v1/attendances', [
            'user_id' => $target->id,
            'periode' => '2026-09',
            'work_days' => 10,
            'late_less_30' => 1,
            'late_more_30' => 0,
            'sick_days' => 0,
        ])->assertForbidden();
    }

    public function test_attendance_scope_blocks_outside_records_and_creation_targets(): void
    {
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $manager = $this->createUser('attendance-scope-manager', $managerRole);
        $managed = $this->createUser('attendance-scope-managed', $staffRole, $manager);
        $outside = $this->createUser('attendance-scope-outside', $staffRole);

        $managedAttendance = Attendance::query()->create([
            'user_id' => $managed->id,
            'periode' => '2026-09',
            'work_days' => 20,
            'late_less_30' => 0,
            'late_more_30' => 0,
            'sick_days' => 0,
        ]);
        $outsideAttendance = Attendance::query()->create([
            'user_id' => $outside->id,
            'periode' => '2026-09',
            'work_days' => 20,
            'late_less_30' => 0,
            'late_more_30' => 0,
            'sick_days' => 0,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/attendances')->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame([$managedAttendance->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($outsideAttendance->id, collect($response->json('data'))->pluck('id')->all());

        $this->postJson('/api/v1/attendances', [
            'user_id' => $outside->id,
            'periode' => '2026-10',
            'work_days' => 20,
        ])->assertForbidden();
    }

    public function test_employee_review_endpoints_reject_unauthorized_regular_staff(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $managerRole = Role::query()->create(['name' => 'COORDINATOR']);
        $actor = $this->createUser('review-actor', $staffRole);
        $managed = $this->createUser('review-managed', $managerRole);

        $review = EmployeeReview::query()->create([
            'user_id' => $managed->id,
            'periode' => '2026-09',
            'responsiveness' => 4,
            'problem_solver' => 4,
            'helpfulness' => 5,
            'initiative' => 3,
        ]);

        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/employee-reviews')->assertForbidden();
        $this->getJson("/api/v1/employee-reviews/{$review->id}")->assertForbidden();
        $this->postJson('/api/v1/employee-reviews', [
            'user_id' => $managed->id,
            'periode' => '2026-09',
            'responsiveness' => 5,
            'problem_solver' => 5,
            'helpfulness' => 5,
            'initiative' => 5,
        ])->assertForbidden();
    }

    public function test_employee_review_scope_blocks_outside_records_and_creation_targets(): void
    {
        $managerRole = Role::query()->create(['name' => 'COORDINATOR']);
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $manager = $this->createUser('review-scope-manager', $managerRole);
        $managed = $this->createUser('review-scope-managed', $staffRole, $manager);
        $outside = $this->createUser('review-scope-outside', $staffRole);

        $managedReview = EmployeeReview::query()->create([
            'user_id' => $managed->id,
            'periode' => '2026-09',
            'responsiveness' => 4,
            'problem_solver' => 4,
            'helpfulness' => 4,
            'initiative' => 4,
        ]);
        $outsideReview = EmployeeReview::query()->create([
            'user_id' => $outside->id,
            'periode' => '2026-09',
            'responsiveness' => 4,
            'problem_solver' => 4,
            'helpfulness' => 4,
            'initiative' => 4,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/employee-reviews')->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame([$managedReview->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($outsideReview->id, collect($response->json('data'))->pluck('id')->all());

        $this->postJson('/api/v1/employee-reviews', [
            'user_id' => $outside->id,
            'periode' => '2026-10',
            'responsiveness' => 4,
            'problem_solver' => 4,
            'helpfulness' => 4,
            'initiative' => 4,
        ])->assertForbidden();
    }

    public function test_cutpoint_endpoints_reject_unauthorized_regular_staff(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $actor = $this->createUser('cutpoint-actor', $staffRole);
        $target = $this->createUser('cutpoint-target', $managerRole);

        $cutpoint = Cutpoint::query()->create([
            'user_id' => $target->id,
            'point' => 5,
            'periode' => '2026-09',
            'keterangan' => 'Datang terlambat',
        ]);

        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/cutpoints', [
            'user_id' => $target->id,
            'point' => 3,
            'periode' => '2026-09',
            'keterangan' => 'Catatan',
        ])->assertForbidden();
        $this->getJson("/api/v1/cutpoints/{$cutpoint->id}")->assertForbidden();
        $this->putJson("/api/v1/cutpoints/{$cutpoint->id}", [
            'point' => 8,
        ])->assertForbidden();
    }

    public function test_work_journal_record_owner_is_enforced_for_modify_and_view_scope_checks(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $managerRole = Role::query()->create(['name' => 'COORDINATOR']);

        $actor = $this->createUser('journal-actor', $staffRole);
        $other = $this->createUser('journal-other', $managerRole);

        $journal = WorkJournal::query()->create([
            'user_id' => $other->id,
            'date' => '2026-09-01',
            'activity' => 'Membuat rencana',
            'notes' => 'Catatan',
        ]);

        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/journals/team')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->putJson("/api/v1/journals/{$journal->id}", [
            'activity' => 'Diubah',
        ])->assertForbidden();
        $this->deleteJson("/api/v1/journals/{$journal->id}")->assertForbidden();
    }

    public function test_work_journal_list_and_create_are_scoped_to_the_authenticated_user(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $actor = $this->createUser('journal-scope-actor', $staffRole);
        $other = $this->createUser('journal-scope-other', $staffRole);

        $actorJournal = WorkJournal::query()->create([
            'user_id' => $actor->id,
            'date' => '2026-09-01',
            'activity' => 'Aktivitas actor',
            'notes' => null,
        ]);
        $otherJournal = WorkJournal::query()->create([
            'user_id' => $other->id,
            'date' => '2026-09-01',
            'activity' => 'Aktivitas user lain',
            'notes' => null,
        ]);

        Sanctum::actingAs($actor);

        $response = $this->getJson('/api/v1/journals')->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame([$actorJournal->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($otherJournal->id, collect($response->json('data'))->pluck('id')->all());

        $this->postJson('/api/v1/journals', [
            'user_id' => $other->id,
            'date' => '2026-09-02',
            'activity' => 'Percobaan impersonasi',
        ])->assertCreated();

        $this->assertDatabaseHas('work_journals', [
            'date' => '2026-09-02',
            'activity' => 'Percobaan impersonasi',
            'user_id' => $actor->id,
        ]);
        $this->assertDatabaseMissing('work_journals', [
            'date' => '2026-09-02',
            'activity' => 'Percobaan impersonasi',
            'user_id' => $other->id,
        ]);
    }

    public function test_request_approval_endpoints_reject_unauthorized_staff_outside_scope(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $managerRole = Role::query()->create(['name' => 'MANAGER']);

        $requester = $this->createUser('requester', $staffRole);
        $owner = $this->createUser('request-owner', $managerRole);
        $approver = $this->createUser('approver', $staffRole);

        $entry = TodoRequest::query()->create([
            'user_id' => $requester->id,
            'jenistodo' => 'daily',
            'todo_request' => 'Laporan keuangan',
            'todo_replace' => 'Libur',
            'approval_id' => $owner->id,
            'status' => 'PENDING',
        ]);

        Sanctum::actingAs($approver);

        $this->getJson('/api/v1/requests/pending-approvals')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->postJson("/api/v1/requests/{$entry->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/requests/{$entry->id}/reject")->assertForbidden();
        $this->putJson("/api/v1/requests/{$entry->id}", [
            'todo_request' => 'Perubahan',
            'jenistodo' => 'monthly',
            'todo_replace' => 'Diganti',
        ])->assertForbidden();
        $this->deleteJson("/api/v1/requests/{$entry->id}")->assertForbidden();
    }

    public function test_request_creation_cannot_impersonate_user_or_approver(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $manager = $this->createUser('request-create-manager', $managerRole);
        $actor = $this->createUser('request-create-actor', $staffRole, $manager);
        $other = $this->createUser('request-create-other', $staffRole);

        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/requests', [
            'user_id' => $other->id,
            'approval_id' => $other->id,
            'status' => 'APPROVED',
            'jenistodo' => 'daily',
            'todo_request' => 'Percobaan membuat atas nama user lain',
            'todo_replace' => 'Pengganti',
        ])->assertCreated();

        $this->assertDatabaseHas('requests', [
            'user_id' => $actor->id,
            'approval_id' => $manager->id,
            'status' => 'PENDING',
            'todo_request' => 'Percobaan membuat atas nama user lain',
        ]);
        $this->assertDatabaseMissing('requests', [
            'user_id' => $other->id,
            'approval_id' => $other->id,
            'todo_request' => 'Percobaan membuat atas nama user lain',
        ]);

        $entry = TodoRequest::query()->where('todo_request', 'Percobaan membuat atas nama user lain')->firstOrFail();
        $this->putJson("/api/v1/requests/{$entry->id}", [
            'approval_id' => $other->id,
            'todo_request' => 'Tetap milik actor',
            'jenistodo' => 'daily',
            'todo_replace' => 'Pengganti',
        ])->assertOk();

        $this->assertSame($manager->id, $entry->fresh()->approval_id);
    }

    public function test_reminder_endpoints_are_admin_only(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $actor = $this->createUser('reminder-actor', $staffRole);
        Sanctum::actingAs($actor);

        $setting = KpiReminderSetting::query()->create([
            'title' => 'Pengingat test',
            'type' => 'pengisian_kpi',
            'deadline_day' => 20,
            'reminder_days_before' => [3, 1],
            'send_overdue_reminder' => true,
            'send_email' => true,
            'send_whatsapp' => false,
            'is_active' => true,
        ]);

        $log = KpiReminderLog::query()->create([
            'kpi_reminder_setting_id' => $setting->id,
            'user_id' => $actor->id,
            'channel' => 'whatsapp',
            'recipient' => '08123456789',
            'status' => 'sent',
            'error_message' => null,
            'sent_at' => now(),
        ]);

        $this->postJson('/api/v1/reminders/trigger')->assertForbidden();
        $this->postJson('/api/v1/reminders/send-test')->assertForbidden();
        $this->getJson('/api/v1/reminders/logs')->assertForbidden();
        $this->getJson("/api/v1/reminders/logs/{$log->id}")->assertForbidden();
        $this->getJson('/api/v1/reminders/settings')->assertForbidden();
        $this->postJson('/api/v1/reminders/settings', [
            'title' => 'Pengingat lain',
            'type' => 'pembuatan_kpi',
            'deadline_day' => 10,
        ])->assertForbidden();
        $this->getJson("/api/v1/reminders/settings/{$setting->id}")->assertForbidden();
        $this->putJson("/api/v1/reminders/settings/{$setting->id}", [
            'title' => 'Diubah',
        ])->assertForbidden();
        $this->deleteJson("/api/v1/reminders/settings/{$setting->id}")->assertForbidden();
        $this->postJson("/api/v1/reminders/settings/{$setting->id}/toggle")->assertForbidden();
    }

    private function createRole(string $name): Role
    {
        return Role::query()->create(['name' => $name]);
    }

    private function createUser(string $username, Role $role, ?User $approval = null): User
    {
        return User::query()->create([
            'nama_lengkap' => strtoupper($username),
            'username' => $username,
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'approval_id' => $approval?->id,
        ]);
    }

    private function clearEloquentGuardableColumns(): void
    {
        $property = new \ReflectionProperty(Model::class, 'guardableColumns');
        $property->setValue(null, []);
    }
}
