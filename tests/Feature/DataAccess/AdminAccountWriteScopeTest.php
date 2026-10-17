<?php

namespace Tests\Feature\DataAccess;

use App\Models\Area;
use App\Models\Daily;
use App\Models\Kpi;
use App\Models\KpiCategory;
use App\Models\KpiType;
use App\Models\Overopen;
use App\Models\Request as TodoRequest;
use App\Models\User;
use App\Models\Weekly;
use App\Services\DataAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Lintas modul: admin badan usaha tertentu boleh melihat, tetapi tidak boleh mengubah,
 * data milik akun ADMIN lain di badan usahanya (sama seperti Kehadiran/Penilaian/Cutpoint).
 */
class AdminAccountWriteScopeTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private Area $coo;

    private Area $uls;

    private User $ojan;

    private User $ulsSuper;

    private User $ulsPeerAdmin;

    private User $ulsManager;

    private User $ulsStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();

        KpiCategory::query()->forceCreate(['id' => 3, 'name' => 'MAIN JOB']);
        KpiType::query()->forceCreate(['id' => 3, 'name' => 'MONTHLY']);

        $this->coo = $this->fixtureArea('COO');
        $this->uls = $this->fixtureArea('ULS');

        $this->ojan = $this->fixtureScopedAdmin('ojan', $this->coo, [$this->uls]);
        $this->ulsSuper = $this->fixtureGlobalAdmin('uls_super', $this->uls);
        $this->ulsPeerAdmin = $this->fixtureScopedAdmin('uls_peer', $this->uls, [$this->uls]);
        $this->ulsManager = $this->fixtureUser('uls_manager', 'MANAGER', $this->uls);
        $this->ulsStaff = $this->fixtureUser('uls_staff', 'STAFF', $this->uls, $this->ulsManager);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_service_separates_read_and_write_for_admin_accounts(): void
    {
        $this->assertTrue(DataAccessService::canSeeUser($this->ojan, $this->ulsSuper));
        $this->assertFalse(DataAccessService::canWriteForUser($this->ojan, $this->ulsSuper));
        $this->assertFalse(DataAccessService::canWriteForUser($this->ojan, $this->ulsPeerAdmin->id));
        $this->assertTrue(DataAccessService::canWriteForUser($this->ojan, $this->ulsStaff->id));
        $this->assertTrue(DataAccessService::canWriteForUser($this->ojan, $this->ojan));

        // Super admin dan atasan non-admin tidak terpengaruh.
        $this->assertTrue(DataAccessService::canWriteForUser($this->ulsSuper, $this->ulsPeerAdmin));
        $this->assertTrue(DataAccessService::canWriteForUser($this->ulsManager, $this->ulsStaff));
    }

    public function test_scoped_admin_cannot_write_kpis_of_admin_accounts(): void
    {
        $superKpi = $this->makeKpi($this->ulsSuper);
        $peerKpi = $this->makeKpi($this->ulsPeerAdmin);
        $staffKpi = $this->makeKpi($this->ulsStaff);
        Sanctum::actingAs($this->ojan);

        $this->getJson('/api/v1/kpis/'.$superKpi->id)->assertOk();
        $this->putJson('/api/v1/kpis/'.$superKpi->id, ['percentage' => 1])->assertForbidden();
        $this->putJson('/api/v1/kpis/'.$peerKpi->id, ['percentage' => 1])->assertForbidden();
        $this->deleteJson('/api/v1/kpis/'.$superKpi->id)->assertForbidden();
        $this->postJson('/api/v1/kpis', $this->kpiPayload($this->ulsSuper))->assertForbidden();
        $this->putJson('/api/v1/kpis/'.$staffKpi->id, ['user_id' => $this->ulsSuper->id])->assertForbidden();

        $this->assertSame(100.0, (float) $superKpi->fresh()->percentage);
        $this->assertNull($superKpi->fresh()->deleted_at);

        // Karyawan biasa di badan usaha yang diizinkan tetap bisa dikelola.
        $this->putJson('/api/v1/kpis/'.$staffKpi->id, ['percentage' => 50])->assertOk();
        $this->postJson('/api/v1/kpis', $this->kpiPayload($this->ulsStaff))->assertCreated();
    }

    public function test_scoped_admin_cannot_write_activities_overopens_or_requests_of_admin_accounts(): void
    {
        $superDaily = Daily::query()->create(['user_id' => $this->ulsSuper->id, 'date' => '2026-10-17', 'task' => 'Tugas super']);
        $superWeekly = Weekly::query()->create(['user_id' => $this->ulsSuper->id, 'task' => 'Mingguan super', 'week' => 42, 'year' => 2026, 'tipe' => 'weekly']);
        $superOveropen = Overopen::query()->create(['user_id' => $this->ulsSuper->id, 'atasan' => null, 'week' => 40, 'year' => 2026, 'point' => 1]);
        $superRequest = TodoRequest::query()->create([
            'user_id' => $this->ulsSuper->id,
            'jenistodo' => 'daily',
            'todo_request' => 'Izin super',
            'todo_replace' => '',
            'approval_id' => $this->ulsManager->id,
            'status' => 'PENDING',
        ]);
        $staffDaily = Daily::query()->create(['user_id' => $this->ulsStaff->id, 'date' => '2026-10-17', 'task' => 'Tugas staff']);
        Sanctum::actingAs($this->ojan);

        $this->getJson('/api/v1/activities/dailies/'.$superDaily->id)->assertOk();
        $this->putJson('/api/v1/activities/dailies/'.$superDaily->id, ['task' => 'Diubah'])->assertForbidden();
        $this->deleteJson('/api/v1/activities/weeklies/'.$superWeekly->id)->assertForbidden();
        $this->postJson('/api/v1/activities/dailies', [
            'user_id' => $this->ulsSuper->id,
            'date' => '2026-10-18',
            'task' => 'Titipan',
        ])->assertForbidden();
        $this->putJson('/api/v1/overopens/'.$superOveropen->id, ['point' => 5])->assertForbidden();
        $this->postJson('/api/v1/requests/'.$superRequest->id.'/approve')->assertForbidden();

        $this->assertSame('Tugas super', $superDaily->fresh()->task);
        $this->assertNull($superWeekly->fresh()->deleted_at);
        $this->assertSame('PENDING', $superRequest->fresh()->status);

        $this->putJson('/api/v1/activities/dailies/'.$staffDaily->id, ['task' => 'Diubah admin'])->assertOk();
        $this->assertSame('Diubah admin', $staffDaily->fresh()->task);
    }

    private function makeKpi(User $owner): Kpi
    {
        return Kpi::query()->create([
            'user_id' => $owner->id,
            'kpi_category_id' => 3,
            'kpi_type_id' => 3,
            'date' => now()->startOfMonth()->addDays(2)->toDateString(),
            'percentage' => 100,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function kpiPayload(User $owner): array
    {
        return [
            'user_id' => $owner->id,
            'kpi_category_id' => 3,
            'kpi_type_id' => 3,
            'date' => now()->startOfMonth()->addDays(3)->toDateString(),
            'percentage' => 100,
        ];
    }
}
