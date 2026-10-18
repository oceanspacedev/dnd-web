<?php

namespace Tests\Feature\DataAccess;

use App\Filament\Resources\Kpis\Pages\CreateKpi;
use App\Filament\Resources\Kpis\Pages\ListKpis;
use App\Models\Area;
use App\Models\Daily;
use App\Models\Kpi;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\KpiDetail;
use App\Models\KpiType;
use App\Models\Overopen;
use App\Models\Position;
use App\Models\Request as TodoRequest;
use App\Models\User;
use App\Models\Weekly;
use App\Services\DataAccessService;
use App\Services\KpiCacheService;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
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

    public function test_scoped_admin_cannot_bulk_delete_kpis_of_admin_accounts(): void
    {
        $peerKpi = $this->makeKpi($this->ulsPeerAdmin);
        $superKpi = $this->makeKpi($this->ulsSuper);
        $staffKpi = $this->makeKpi($this->ulsStaff);
        $this->actAsFilament($this->ojan);

        // KPI akun ADMIN lain terlihat di daftar, tetapi hapus massal mengecek KpiPolicy::delete per baris.
        Livewire::test(ListKpis::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$peerKpi, $superKpi, $staffKpi])
            ->callTableBulkAction('delete', [$peerKpi, $superKpi, $staffKpi]);

        $this->assertNotSoftDeleted($peerKpi);
        $this->assertNotSoftDeleted($superKpi);
        $this->assertSoftDeleted($staffKpi);
    }

    public function test_scoped_admin_does_not_create_or_copy_kpis_for_admin_accounts(): void
    {
        $sales = Position::query()->create(['name' => 'SALES']);
        foreach ([$this->ojan, $this->ulsSuper, $this->ulsPeerAdmin, $this->ulsStaff] as $holder) {
            $holder->update(['position_id' => $sales->id]);
        }

        $description = KpiDescription::query()->create([
            'kpi_category_id' => 3,
            'description' => 'Target penjualan',
            'is_negative' => false,
        ]);
        $this->actAsFilament($this->ojan);

        // Opsi posisi hanya menyebut karyawan yang akan mendapat KPI.
        $positionLabel = KpiCacheService::getPositionsForUser()[$sales->id];
        $this->assertStringContainsString($this->ulsStaff->nama_lengkap, $positionLabel);
        $this->assertStringContainsString($this->ojan->nama_lengkap, $positionLabel);
        $this->assertStringNotContainsString($this->ulsPeerAdmin->nama_lengkap, $positionLabel);
        $this->assertStringNotContainsString($this->ulsSuper->nama_lengkap, $positionLabel);

        $undoRepeaterFake = Repeater::fake();

        try {
            Livewire::test(CreateKpi::class)
                ->fillForm([
                    'position_id' => $sales->id,
                    'date' => '2030-01-01',
                    'percentageMain' => 100,
                    'percentageAdm' => 0,
                    'percentageRep' => 0,
                    'kpi_details_main' => [[
                        'kpi_description_id_main' => $description->id,
                        'count_typeMain' => 'NON',
                    ]],
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        } finally {
            $undoRepeaterFake();
        }

        // Diri sendiri dan karyawan biasa; akun ADMIN lain (peer dan super admin) tidak.
        $this->assertEqualsCanonicalizing(
            [$this->ojan->id, $this->ulsStaff->id],
            Kpi::query()->whereYear('date', 2030)->whereMonth('date', 1)->pluck('user_id')->all(),
        );

        // Copy KPI: bulan sumber berisi KPI semua pemegang posisi.
        $from = now()->format('Y-m');
        $to = now()->addMonthNoOverflow();
        foreach ([$this->ulsSuper, $this->ulsPeerAdmin, $this->ulsStaff] as $owner) {
            $this->makeKpi($owner);
        }

        Livewire::test(ListKpis::class)
            ->callAction('copy', [
                'copy_mode' => 'position',
                'position' => $sales->id,
                'tanggal1' => $from,
                'tanggal2' => $to->format('Y-m'),
            ])
            ->assertHasNoActionErrors();
        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id],
            Kpi::query()->whereYear('date', $to->year)->whereMonth('date', $to->month)->pluck('user_id')->all(),
        );

        // Mode individual: akun ADMIN lain boleh jadi sumber (terlihat), tetapi tidak jadi target.
        Livewire::test(ListKpis::class)
            ->callAction('copy', [
                'copy_mode' => 'individual',
                'source_users' => $this->ulsStaff->id,
                'target_users' => [$this->ulsSuper->id],
                'tanggal1' => $from,
                'tanggal2' => $to->format('Y-m'),
            ])
            ->assertHasActionErrors(['target_users.0']);

        Livewire::test(ListKpis::class)
            ->callAction('copy', [
                'copy_mode' => 'individual',
                'source_users' => $this->ulsPeerAdmin->id,
                'target_users' => [$this->ojan->id],
                'tanggal1' => $from,
                'tanggal2' => $to->format('Y-m'),
            ])
            ->assertHasNoActionErrors();

        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->ojan->id],
            Kpi::query()->whereYear('date', $to->year)->whereMonth('date', $to->month)->pluck('user_id')->all(),
        );
        $this->assertFalse(Kpi::query()
            ->whereIn('user_id', [$this->ulsSuper->id, $this->ulsPeerAdmin->id])
            ->whereYear('date', 2030)->whereMonth('date', 1)
            ->exists());
    }

    public function test_view_only_kpi_subtasks_cannot_be_changed_from_the_view_modal(): void
    {
        $peerKpi = $this->makeKpi($this->ulsPeerAdmin);
        $peerDetail = $this->makeDetail($peerKpi, [['description' => 'ORIGINAL']]);
        $staffKpi = $this->makeKpi($this->ulsStaff);
        $staffDetail = $this->makeDetail($staffKpi, [['description' => 'ORIGINAL']]);
        $this->actAsFilament($this->ojan);

        $this->assertTrue($this->ojan->can('view', $peerKpi));
        $this->assertFalse($this->ojan->can('update', $peerKpi));

        foreach ([$peerKpi->id => $peerDetail, $staffKpi->id => $staffDetail] as $kpiId => $detail) {
            Livewire::test(ListKpis::class)
                ->loadTable()
                ->call('mountAction', 'view', [], ['table' => true, 'recordKey' => (string) $kpiId])
                ->call('mountAction', 'manage_subtasks', [], ['schemaComponent' => 'mountedActionSchema0.kpi_detail.record-'.$detail->id])
                ->set('mountedActions.1.data.subtasks', [['description' => 'INJECTED']])
                ->call('callMountedAction');
        }

        // KPI yang hanya boleh dilihat tidak berubah; KPI yang boleh diubah tetap bisa diatur subtask-nya.
        $this->assertSame([['description' => 'ORIGINAL']], $peerDetail->fresh()->subtasks);
        $this->assertSame([['description' => 'INJECTED']], $staffDetail->fresh()->subtasks);
    }

    private function actAsFilament(User $user): void
    {
        $this->resetAccessMemos();
        KpiCacheService::clearKpiCache();
        $this->actingAs($user);
        Livewire::actingAs($user);
    }

    /**
     * @param  array<int, array<string, string>>  $subtasks
     */
    private function makeDetail(Kpi $kpi, array $subtasks): KpiDetail
    {
        $description = KpiDescription::query()->create([
            'kpi_category_id' => 3,
            'description' => 'Tugas '.$kpi->id,
            'is_negative' => false,
        ]);

        return $kpi->kpi_detail()->create([
            'kpi_description_id' => $description->id,
            'count_type' => 'NON',
            'subtasks' => $subtasks,
        ]);
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
