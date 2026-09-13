<?php

namespace Tests\Feature;

use App\Console\Commands\SendKpiRemindersCommand;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Mail\KpiReminderMail;
use App\Models\Kpi;
use App\Models\KpiDetail;
use App\Models\KpiReminderLog;
use App\Models\KpiReminderSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class KpiReminderCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        config()->set('kpi-reminders.cache_store', 'array');

        $this->createSchema();
        Date::setTestNow('2026-09-15 08:00:00');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_creation_targets_real_supervisors_and_ignores_future_kpis(): void
    {
        $supervisor = $this->createUser('Atasan');
        $subordinate = $this->createUser('Bawahan', $supervisor->id);

        Kpi::create([
            'user_id' => $subordinate->id,
            'date' => '2026-10-01 00:00:00',
        ]);

        $setting = new KpiReminderSetting(['type' => 'pembuatan_kpi']);

        $this->assertSame(
            [$supervisor->id],
            $this->targetIds($setting),
        );

        Kpi::create([
            'user_id' => $subordinate->id,
            'date' => '2026-09-01 00:00:00',
        ]);

        $this->assertSame([], $this->targetIds($setting));
    }

    public function test_filling_targets_only_incomplete_current_month_parent_details(): void
    {
        $completedNonUser = $this->createUser('NON selesai');
        $incompleteNonUser = $this->createUser('NON belum selesai');
        $pendingResultUser = $this->createUser('RESULT belum selesai');
        $futurePendingUser = $this->createUser('RESULT bulan depan');
        $extraTaskOnlyUser = $this->createUser('Extra task');

        $this->createKpiDetail($completedNonUser, '2026-09-01', 'NON', null, 1);
        $this->createKpiDetail($incompleteNonUser, '2026-09-01', 'NON', null, 0);
        $this->createKpiDetail($pendingResultUser, '2026-09-01', 'RESULT', null, null);
        $this->createKpiDetail($futurePendingUser, '2026-10-01', 'RESULT', null, null);
        $this->createKpiDetail($extraTaskOnlyUser, '2026-09-01', 'NON', null, null, true);

        $setting = new KpiReminderSetting(['type' => 'pengisian_kpi']);

        $this->assertEqualsCanonicalizing(
            [$incompleteNonUser->id, $pendingResultUser->id],
            $this->targetIds($setting),
        );
    }

    public function test_failed_email_log_does_not_block_same_day_retry(): void
    {
        Mail::fake();

        $user = $this->createUser('Penerima Email', null, 'user@example.test');
        $this->createKpiDetail($user, '2026-09-01', 'RESULT', null, null);
        $setting = $this->createSetting(sendEmail: true);

        KpiReminderLog::create([
            'kpi_reminder_setting_id' => $setting->id,
            'user_id' => $user->id,
            'channel' => 'email',
            'recipient' => $user->email,
            'status' => 'failed',
            'error_message' => 'SMTP sementara tidak tersedia',
            'sent_at' => Date::now(),
        ]);

        $this->artisan('kpi:send-reminders', ['--setting-id' => $setting->id])
            ->assertSuccessful();

        Mail::assertSent(KpiReminderMail::class, 1);
        $this->assertDatabaseHas('kpi_reminder_logs', [
            'kpi_reminder_setting_id' => $setting->id,
            'user_id' => $user->id,
            'channel' => 'email',
            'status' => 'sent',
        ]);
    }

    public function test_log_filters_and_resource_use_current_schema_columns(): void
    {
        $user = $this->createUser('Penerima Log', email: 'recipient@example.test');
        $setting = $this->createSetting(sendEmail: true);

        $admin = new User([
            'nama_lengkap' => 'Admin Reminder',
            'approval_id' => null,
            'email' => 'admin-test@example.test',
            'no_hp' => null,
        ]);
        $admin->setAttribute('id', -1);
        $admin->setRelation('role', new class
        {
            public string $name = 'ADMIN';
        });
        Auth::login($admin);

        $matchingLog = KpiReminderLog::create([
            'kpi_reminder_setting_id' => $setting->id,
            'user_id' => $user->id,
            'channel' => 'email',
            'recipient' => $user->email,
            'status' => 'sent',
            'sent_at' => Date::parse('2026-09-15 08:00:00'),
        ]);

        KpiReminderLog::create([
            'kpi_reminder_setting_id' => $setting->id,
            'user_id' => $user->id,
            'channel' => 'email',
            'recipient' => 'other@example.test',
            'status' => 'sent',
            'sent_at' => Date::parse('2026-10-15 08:00:00'),
        ]);

        $request = Request::create('/api/v1/reminders/logs', 'GET', [
            'periode' => '2026-09',
            'search' => 'recipient@example.test',
        ]);
        $payload = (new ReminderController)->logs($request)->getData(true);

        Auth::logout();

        $this->assertSame(1, $payload['meta']['total']);
        $this->assertSame($matchingLog->id, $payload['data'][0]['id']);
        $this->assertSame('recipient@example.test', $payload['data'][0]['destination']);
        $this->assertSame('2026-09', $payload['data'][0]['periode']);
    }

    public function test_command_returns_failure_when_whatsapp_delivery_fails(): void
    {
        config()->set('services.whatsapp.api_key');

        $user = $this->createUser('Penerima WA', null, null, '081234567890');
        $this->createKpiDetail($user, '2026-09-01', 'RESULT', null, null);
        $setting = $this->createSetting(sendWhatsApp: true);

        $this->artisan('kpi:send-reminders', ['--setting-id' => $setting->id])
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseHas('kpi_reminder_logs', [
            'kpi_reminder_setting_id' => $setting->id,
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'status' => 'failed',
        ]);
    }

    public function test_command_reports_an_enabled_channel_with_no_recipient_as_failure(): void
    {
        $user = $this->createUser('Penerima Tanpa Email');
        $this->createKpiDetail($user, '2026-09-01', 'RESULT', null, null);
        $setting = $this->createSetting(sendEmail: true);

        $this->artisan('kpi:send-reminders', ['--setting-id' => $setting->id])
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseHas('kpi_reminder_logs', [
            'kpi_reminder_setting_id' => $setting->id,
            'user_id' => $user->id,
            'channel' => 'email',
            'recipient' => '-',
            'status' => 'failed',
            'error_message' => 'Alamat email user belum tersedia.',
        ]);
    }

    public function test_explicit_inactive_setting_returns_failure(): void
    {
        $setting = $this->createSetting(sendEmail: true);
        $setting->update(['is_active' => false]);

        $this->artisan('kpi:send-reminders', ['--setting-id' => $setting->id])
            ->assertExitCode(Command::FAILURE);
    }

    private function targetIds(KpiReminderSetting $setting): array
    {
        $method = new ReflectionMethod(SendKpiRemindersCommand::class, 'identifyTargetUsers');
        $targets = $method->invoke(
            resolve(SendKpiRemindersCommand::class),
            $setting,
            Date::parse('2026-09-01 00:00:00'),
            Date::parse('2026-10-01 00:00:00'),
        );

        return collect($targets)->pluck('id')->sort()->values()->all();
    }

    private function createUser(
        string $name,
        ?int $approvalId = null,
        ?string $email = null,
        ?string $phone = null,
    ): User {
        return User::create([
            'nama_lengkap' => $name,
            'approval_id' => $approvalId,
            'email' => $email,
            'no_hp' => $phone,
        ]);
    }

    private function createKpiDetail(
        User $user,
        string $date,
        string $countType,
        ?float $actual,
        ?float $result,
        bool $isExtraTask = false,
    ): KpiDetail {
        $kpi = Kpi::create([
            'user_id' => $user->id,
            'date' => $date.' 00:00:00',
        ]);

        return KpiDetail::create([
            'kpi_id' => $kpi->id,
            'count_type' => $countType,
            'value_actual' => $actual,
            'value_result' => $result,
            'is_extra_task' => $isExtraTask,
        ]);
    }

    private function createSetting(bool $sendEmail = false, bool $sendWhatsApp = false): KpiReminderSetting
    {
        return KpiReminderSetting::create([
            'title' => 'Pengingat test',
            'type' => 'pengisian_kpi',
            'deadline_day' => 25,
            'reminder_days_before' => [0],
            'send_overdue_reminder' => true,
            'send_email' => $sendEmail,
            'send_whatsapp' => $sendWhatsApp,
            'email_subject' => 'Pengingat KPI',
            'email_body' => 'Halo {nama}',
            'whatsapp_template' => 'Halo {nama}',
            'is_active' => true,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->string('nama_lengkap')->nullable();
            $table->string('email')->nullable();
            $table->string('no_hp')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kpis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->dateTime('date');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kpi_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kpi_id');
            $table->string('count_type');
            $table->double('value_actual')->nullable();
            $table->double('value_result')->nullable();
            $table->boolean('is_extra_task')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kpi_reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type');
            $table->unsignedTinyInteger('deadline_day');
            $table->json('reminder_days_before')->nullable();
            $table->boolean('send_overdue_reminder');
            $table->boolean('send_email');
            $table->boolean('send_whatsapp');
            $table->string('email_subject');
            $table->text('email_body')->nullable();
            $table->text('whatsapp_template')->nullable();
            $table->boolean('is_active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kpi_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kpi_reminder_setting_id');
            $table->unsignedBigInteger('user_id');
            $table->string('channel');
            $table->string('recipient');
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }
}
