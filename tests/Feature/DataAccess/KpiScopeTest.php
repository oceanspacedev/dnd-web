<?php

namespace Tests\Feature\DataAccess;

use App\Exports\KpiMonthlyDetailSheet;
use App\Exports\KpiMonthlySummarySheet;
use App\Exports\KpiPerDivisionDetailSheet;
use App\Exports\KpiPerDivisionExport;
use App\Exports\KpiPerDivisionSummarySheet;
use App\Filament\Resources\Kpis\KpiResource;
use App\Filament\Resources\Kpis\Pages\CreateKpi;
use App\Filament\Resources\Kpis\Pages\ListKpis;
use App\Filament\Widgets\ChecklistKPI;
use App\Models\Area;
use App\Models\Kpi;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\KpiDetail;
use App\Models\KpiType;
use App\Models\Position;
use App\Models\User;
use App\Services\KpiCacheService;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use ReflectionMethod;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

class KpiScopeTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private Area $coo;

    private Area $uls;

    private Area $gmd;

    private User $superAdmin;

    private User $scopedAdmin;

    private User $ulsStaff;

    private User $gmdStaff;

    private User $cooStaff;

    private User $cooTeam;

    private User $gmdInUlsDivision;

    private User $manager;

    private User $managerTeam;

    private Position $sharedPosition;

    private Kpi $ownKpi;

    private Kpi $ulsKpi;

    private Kpi $gmdKpi;

    private Kpi $cooStaffKpi;

    private Kpi $cooTeamKpi;

    private Kpi $gmdInUlsDivisionKpi;

    private Kpi $managerTeamKpi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();
        KpiCacheService::clearKpiCache();

        foreach ([1 => 'ADMINISTRATION', 2 => 'REPORTING', 3 => 'MAIN JOB'] as $id => $name) {
            KpiCategory::query()->forceCreate(['id' => $id, 'name' => $name]);
        }

        foreach ([1 => 'DAILY', 2 => 'WEEKLY', 3 => 'MONTHLY'] as $id => $name) {
            KpiType::query()->forceCreate(['id' => $id, 'name' => $name]);
        }

        $this->coo = $this->fixtureArea('COO');
        $this->uls = $this->fixtureArea('ULS');
        $this->gmd = $this->fixtureArea('GMD');

        $this->sharedPosition = Position::query()->create(['name' => 'SALES']);

        $this->superAdmin = $this->fixtureGlobalAdmin('super_admin', $this->coo);
        $this->scopedAdmin = $this->fixtureScopedAdmin('ojan', $this->coo, [$this->uls]);

        $this->ulsStaff = $this->fixtureUser('uls_staff', 'STAFF', $this->uls, null, ['position_id' => $this->sharedPosition->id]);
        $this->gmdStaff = $this->fixtureUser('gmd_staff', 'STAFF', $this->gmd, null, ['position_id' => $this->sharedPosition->id]);
        $this->cooStaff = $this->fixtureUser('coo_staff', 'STAFF', $this->coo);
        $this->cooTeam = $this->fixtureUser('coo_team', 'STAFF', $this->coo, $this->scopedAdmin, ['position_id' => $this->sharedPosition->id]);

        // users.area_id menentukan badan usaha, walaupun divisinya milik ULS.
        $this->gmdInUlsDivision = $this->fixtureUser('gmd_in_uls_div', 'STAFF', $this->gmd, null, [
            'divisi_id' => $this->fixtureDivisi($this->uls)->id,
        ]);

        $this->manager = $this->fixtureUser('gmd_manager', 'MANAGER', $this->gmd);
        $this->managerTeam = $this->fixtureUser('gmd_manager_team', 'STAFF', $this->gmd, $this->manager);
        $this->resetAccessMemos();

        $this->ownKpi = $this->makeKpi($this->scopedAdmin);
        $this->ulsKpi = $this->makeKpi($this->ulsStaff);
        $this->gmdKpi = $this->makeKpi($this->gmdStaff);
        $this->cooStaffKpi = $this->makeKpi($this->cooStaff);
        $this->cooTeamKpi = $this->makeKpi($this->cooTeam);
        $this->gmdInUlsDivisionKpi = $this->makeKpi($this->gmdInUlsDivision);
        $this->managerTeamKpi = $this->makeKpi($this->managerTeam);
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_resource_query_scopes_kpis_by_badan_usaha_team_and_self(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertEqualsCanonicalizing(
            Kpi::query()->pluck('id')->all(),
            KpiResource::getEloquentQuery()->pluck('kpis.id')->all(),
        );

        $this->actingAs($this->scopedAdmin);
        $this->assertEqualsCanonicalizing(
            [$this->ownKpi->id, $this->ulsKpi->id, $this->cooTeamKpi->id],
            KpiResource::getEloquentQuery()->pluck('kpis.id')->all(),
        );

        // Non-admin tetap memakai approval line (diri sendiri + tim).
        $managerKpi = $this->makeKpi($this->manager);
        $this->actingAs($this->manager);
        $this->assertEqualsCanonicalizing(
            [$managerKpi->id, $this->managerTeamKpi->id],
            KpiResource::getEloquentQuery()->pluck('kpis.id')->all(),
        );

        // Non-admin tanpa bawahan tetap tidak melihat apa pun di daftar (perilaku lama).
        $this->actingAs($this->ulsStaff);
        $this->assertSame([], KpiResource::getEloquentQuery()->pluck('kpis.id')->all());
    }

    public function test_list_page_table_only_shows_scoped_records_for_area_scoped_admin(): void
    {
        $this->actingAs($this->scopedAdmin);
        Livewire::actingAs($this->scopedAdmin);

        Livewire::test(ListKpis::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$this->ownKpi, $this->ulsKpi, $this->cooTeamKpi])
            ->assertCanNotSeeTableRecords([$this->gmdKpi, $this->cooStaffKpi, $this->gmdInUlsDivisionKpi]);
    }

    public function test_kpi_policy_scopes_area_admins_and_keeps_non_admin_rules(): void
    {
        $scoped = Gate::forUser($this->scopedAdmin);

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue($scoped->allows($ability, $this->ulsKpi), $ability);
            $this->assertTrue($scoped->allows($ability, $this->cooTeamKpi), $ability);
            $this->assertTrue($scoped->allows($ability, $this->ownKpi), $ability);
            $this->assertFalse($scoped->allows($ability, $this->gmdKpi), $ability);
            $this->assertFalse($scoped->allows($ability, $this->cooStaffKpi), $ability);
            $this->assertFalse($scoped->allows($ability, $this->gmdInUlsDivisionKpi), $ability);
        }

        // Target sintetis (API store/update/userSummary) di-resolve lewat users.area_id.
        $this->assertTrue($scoped->allows('view', new Kpi(['user_id' => $this->ulsStaff->id])));
        $this->assertFalse($scoped->allows('view', new Kpi(['user_id' => $this->gmdStaff->id])));
        $this->assertFalse($scoped->allows('view', new Kpi(['user_id' => 999999])));
        $this->assertTrue($scoped->allows('create', Kpi::class));

        $super = Gate::forUser($this->superAdmin);
        $this->assertTrue($super->allows('update', $this->gmdKpi));
        $this->assertTrue($super->allows('view', new Kpi(['user_id' => 999999])));
        $this->assertTrue($super->allows('create', Kpi::class));

        // Admin terbatas tanpa badan usaha dan tanpa tim tidak boleh membuat KPI.
        $emptyAdmin = $this->fixtureScopedAdmin('empty_admin', $this->coo, []);
        $this->assertFalse(Gate::forUser($emptyAdmin)->allows('create', Kpi::class));
        $this->assertTrue(Gate::forUser($emptyAdmin)->allows('view', $this->makeKpi($emptyAdmin)));
        $this->assertFalse(Gate::forUser($emptyAdmin)->allows('view', $this->ulsKpi));

        // Atasan non-admin mengelola KPI timnya.
        $managerGate = Gate::forUser($this->manager);
        $this->assertTrue($managerGate->allows('update', $this->managerTeamKpi));
        $this->assertFalse($managerGate->allows('update', $this->gmdKpi));
        $this->assertTrue($managerGate->allows('create', Kpi::class));

        // Semua role atasan (bukan hanya MANAGER/COORDINATOR) mengelola KPI timnya, tidak di luar tim.
        $teamLeader = $this->fixtureUser('team_leader', 'TEAM LEADER', $this->gmd);
        $teamLeaderMember = $this->fixtureUser('team_leader_member', 'STAFF', $this->gmd, $teamLeader);
        $this->resetAccessMemos();
        $teamLeaderKpi = $this->makeKpi($teamLeaderMember);
        $teamLeaderGate = Gate::forUser($teamLeader);
        $this->assertTrue($teamLeaderGate->allows('view', $teamLeaderKpi));
        $this->assertTrue($teamLeaderGate->allows('update', $teamLeaderKpi));
        $this->assertTrue($teamLeaderGate->allows('create', Kpi::class));
        $this->assertFalse($teamLeaderGate->allows('update', $this->gmdKpi));

        // Atasan tanpa bawahan tidak bisa membuat KPI untuk orang lain.
        $lonelyLeader = $this->fixtureUser('lonely_leader', 'TEAM LEADER', $this->gmd);
        $this->resetAccessMemos();
        $this->assertFalse(Gate::forUser($lonelyLeader)->allows('create', Kpi::class));
    }

    public function test_checklist_lock_is_bypassed_by_any_admin_only(): void
    {
        $locked = $this->makeKpi($this->ulsStaff, '2020-01-15');

        $this->assertFalse($locked->isChecklistLockedFor($this->scopedAdmin));
        $this->assertFalse($locked->isChecklistLockedFor($this->superAdmin));
        $this->assertTrue($locked->isChecklistLockedFor($this->ulsStaff));
        $this->assertTrue($locked->isChecklistLockedFor($this->manager));
    }

    public function test_api_endpoints_respect_area_scope(): void
    {
        Sanctum::actingAs($this->scopedAdmin);

        $index = $this->getJson('/api/v1/kpis?per_page=100')->assertOk();
        $this->assertEqualsCanonicalizing(
            [$this->ownKpi->id, $this->ulsKpi->id, $this->cooTeamKpi->id],
            collect($index->json('data'))->pluck('id')->all(),
        );

        $this->getJson('/api/v1/kpis/'.$this->ulsKpi->id)->assertOk();
        $this->getJson('/api/v1/kpis/'.$this->gmdKpi->id)->assertForbidden();
        $this->putJson('/api/v1/kpis/'.$this->gmdKpi->id, ['percentage' => 50])->assertForbidden();
        $this->deleteJson('/api/v1/kpis/'.$this->gmdKpi->id)->assertForbidden();
        $this->putJson('/api/v1/kpis/'.$this->ulsKpi->id, ['user_id' => $this->gmdStaff->id])->assertForbidden();

        $payload = [
            'kpi_category_id' => 3,
            'kpi_type_id' => 3,
            'date' => '2026-10-01',
            'percentage' => 100,
        ];
        $this->postJson('/api/v1/kpis', $payload + ['user_id' => $this->gmdStaff->id])->assertForbidden();
        $this->postJson('/api/v1/kpis', $payload + ['user_id' => $this->ulsStaff->id])->assertCreated();

        $gmdDetail = $this->gmdKpi->kpi_detail()->firstOrFail();
        $this->putJson('/api/v1/kpi-details/'.$gmdDetail->id, ['value_actual' => 1])->assertForbidden();
        $this->deleteJson('/api/v1/kpi-details/'.$gmdDetail->id)->assertForbidden();
        $this->postJson('/api/v1/kpis/'.$this->gmdKpi->id.'/details', [
            'kpi_description_id' => $gmdDetail->kpi_description_id,
            'value_plan' => 1,
        ])->assertForbidden();

        // Periode terkunci tetap bisa diubah admin badan usaha, asalkan karyawannya dalam cakupan.
        $lockedDetail = $this->makeKpi($this->ulsStaff, '2020-01-15')->kpi_detail()->firstOrFail();
        $this->putJson('/api/v1/kpi-details/'.$lockedDetail->id, ['value_actual' => 1])->assertOk();

        // Ringkasan: otorisasi sebelum 404 agar keberadaan karyawan di luar cakupan tidak bocor.
        $this->getJson('/api/v1/kpis/user/'.$this->ulsStaff->id.'/summary')->assertOk();
        $this->getJson('/api/v1/kpis/user/'.$this->gmdStaff->id.'/summary')->assertForbidden();
        $this->getJson('/api/v1/kpis/user/999999/summary')->assertForbidden();

        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/v1/kpis/user/999999/summary')->assertNotFound();
        $this->assertSame(Kpi::query()->count(), $this->getJson('/api/v1/kpis?per_page=100')->assertOk()->json('meta.total'));
    }

    public function test_kpis_of_soft_deleted_employees_stay_visible_to_area_admin(): void
    {
        $this->ulsStaff->delete();
        $this->resetAccessMemos();

        $this->actingAs($this->scopedAdmin);
        $this->assertContains($this->ulsKpi->id, KpiResource::getEloquentQuery()->pluck('kpis.id')->all());

        Sanctum::actingAs($this->scopedAdmin);
        $index = $this->getJson('/api/v1/kpis?per_page=100')->assertOk();
        $this->assertContains($this->ulsKpi->id, collect($index->json('data'))->pluck('id')->all());
        $this->getJson('/api/v1/kpis/'.$this->ulsKpi->id)->assertOk();
    }

    public function test_api_store_rejects_detail_parent_from_another_kpi(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $foreignDetail = $this->gmdKpi->kpi_detail()->firstOrFail();

        $this->postJson('/api/v1/kpis', [
            'user_id' => $this->ulsStaff->id,
            'kpi_category_id' => 3,
            'kpi_type_id' => 3,
            'date' => '2026-10-01',
            'percentage' => 100,
            'details' => [[
                'kpi_description_id' => $foreignDetail->kpi_description_id,
                'parent_id' => $foreignDetail->id,
                'value_plan' => 1,
            ]],
        ])->assertStatus(422);

        $this->assertSame(0, KpiDetail::query()->where('parent_id', $foreignDetail->id)->count());
    }

    public function test_api_index_for_non_admin_is_unchanged(): void
    {
        $managerKpi = $this->makeKpi($this->manager);
        Sanctum::actingAs($this->manager);

        $index = $this->getJson('/api/v1/kpis?per_page=100')->assertOk();
        $this->assertEqualsCanonicalizing(
            [$managerKpi->id, $this->managerTeamKpi->id],
            collect($index->json('data'))->pluck('id')->all(),
        );

        Sanctum::actingAs($this->ulsStaff);
        $own = $this->getJson('/api/v1/kpis')->assertOk();
        $this->assertSame([$this->ulsKpi->id], collect($own->json('data'))->pluck('id')->all());
    }

    public function test_position_options_are_scoped_and_cached_per_scope(): void
    {
        $this->actingAs($this->scopedAdmin);
        $options = KpiCacheService::getPositionsForUser();

        $this->assertArrayHasKey($this->sharedPosition->id, $options);
        $this->assertStringContainsString($this->ulsStaff->nama_lengkap, $options[$this->sharedPosition->id]);
        $this->assertStringContainsString($this->cooTeam->nama_lengkap, $options[$this->sharedPosition->id]);
        $this->assertStringNotContainsString($this->gmdStaff->nama_lengkap, $options[$this->sharedPosition->id]);

        // Akses badan usaha diubah: kunci cache ikut berubah sehingga daftar lama tidak dipakai.
        $this->scopedAdmin->dataAccessAreas()->sync([$this->uls->id, $this->gmd->id]);
        $this->resetAccessMemos();

        $widened = KpiCacheService::getPositionsForUser();
        $this->assertStringContainsString($this->gmdStaff->nama_lengkap, $widened[$this->sharedPosition->id]);

        $this->actingAs($this->superAdmin);
        $all = KpiCacheService::getPositionsForUser();
        $this->assertStringContainsString($this->gmdStaff->nama_lengkap, $all[$this->sharedPosition->id]);
    }

    public function test_create_by_position_only_fans_out_to_visible_users(): void
    {
        $description = KpiDescription::query()->create([
            'kpi_category_id' => 3,
            'description' => 'Target penjualan',
            'is_negative' => false,
        ]);

        $this->actingAs($this->scopedAdmin);
        Livewire::actingAs($this->scopedAdmin);
        $undoRepeaterFake = Repeater::fake();

        try {
            Livewire::test(CreateKpi::class)
                ->fillForm([
                    'position_id' => $this->sharedPosition->id,
                    'date' => '2026-11-01',
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

        $owners = Kpi::query()
            ->whereYear('date', 2026)
            ->whereMonth('date', 11)
            ->pluck('user_id')
            ->all();

        $this->assertEqualsCanonicalizing([$this->ulsStaff->id, $this->cooTeam->id], $owners);
    }

    public function test_export_options_are_scoped_for_area_admin(): void
    {
        $this->actingAs($this->scopedAdmin);

        $divisionOptions = $this->invokeListKpis('exportDivisionOptions');
        $this->assertEqualsCanonicalizing(
            [$this->fixtureDivisi($this->uls)->id, $this->fixtureDivisi($this->coo)->id],
            array_keys($divisionOptions),
        );

        $userOptions = $this->invokeListKpis('exportUserOptions');
        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->cooTeam->id, $this->scopedAdmin->id],
            array_keys($userOptions),
        );

        // Pencarian user di form edit KPI juga dibatasi.
        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->cooTeam->id, $this->scopedAdmin->id],
            $this->invokeResource('visibleUsersQuery')->pluck('users.id')->all(),
        );

        $copyOptions = $this->invokeListKpis('copyUserOptions');
        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->cooTeam->id, $this->scopedAdmin->id],
            array_keys($copyOptions),
        );

        $this->actingAs($this->superAdmin);
        $this->assertArrayHasKey($this->fixtureDivisi($this->gmd)->id, $this->invokeListKpis('exportDivisionOptions'));
        $this->assertArrayHasKey($this->gmdStaff->id, $this->invokeListKpis('exportUserOptions'));

        // Non-admin: opsi user tetap bawahan langsung + satu level (tanpa diri sendiri).
        $this->actingAs($this->manager);
        $this->assertSame([$this->managerTeam->id], array_keys($this->invokeListKpis('exportUserOptions')));
        $this->assertSame([], $this->invokeListKpis('exportDivisionOptions'));
    }

    public function test_division_export_only_contains_users_visible_to_area_admin(): void
    {
        Excel::fake();
        $month = now()->format('Y-m');
        $ulsDivision = $this->fixtureDivisi($this->uls);

        $this->actingAs($this->scopedAdmin);
        Livewire::actingAs($this->scopedAdmin);

        Livewire::test(ListKpis::class)
            ->callAction('export', [
                'periode' => 'month',
                'export_by' => 'division',
                'divisi' => $ulsDivision->id,
                'bulan' => $month,
            ])
            ->assertHasNoActionErrors();

        Excel::assertDownloaded("KPI_UMUM_{$month}.xlsx", function (KpiPerDivisionExport $export): bool {
            $names = collect($export->sheets()[0]->array())->pluck('name')->all();

            return $names === [$this->ulsStaff->nama_lengkap];
        });

        // Divisi tanpa karyawan dalam cakupan ditolak di server.
        Livewire::test(ListKpis::class)
            ->callAction('export', [
                'periode' => 'month',
                'export_by' => 'division',
                'divisi' => $this->fixtureDivisi($this->gmd)->id,
                'bulan' => $month,
            ])
            ->assertHasActionErrors(['divisi']);

        Livewire::test(ListKpis::class)
            ->callAction('export', [
                'periode' => 'month',
                'export_by' => 'user',
                'user_id' => $this->gmdStaff->id,
                'bulan' => $month,
            ])
            ->assertHasActionErrors(['user_id']);
    }

    public function test_super_admin_division_export_is_unrestricted(): void
    {
        Excel::fake();
        $month = now()->format('Y-m');

        $this->actingAs($this->superAdmin);
        Livewire::actingAs($this->superAdmin);

        Livewire::test(ListKpis::class)
            ->callAction('export', [
                'periode' => 'month',
                'export_by' => 'division',
                'divisi' => $this->fixtureDivisi($this->uls)->id,
                'bulan' => $month,
            ])
            ->assertHasNoActionErrors();

        Excel::assertDownloaded("KPI_UMUM_{$month}.xlsx", function (KpiPerDivisionExport $export): bool {
            $names = collect($export->sheets()[0]->array())->pluck('name')->all();

            return $names === [$this->gmdInUlsDivision->nama_lengkap, $this->ulsStaff->nama_lengkap];
        });
    }

    public function test_non_admin_export_without_user_no_longer_leaks_whole_division(): void
    {
        Excel::fake();
        $month = now()->format('Y-m');

        $this->actingAs($this->cooStaff);
        Livewire::actingAs($this->cooStaff);

        Livewire::test(ListKpis::class)
            ->callAction('export', [
                'periode' => 'month',
                'export_by' => 'user',
                'bulan' => $month,
            ])
            ->assertHasNoActionErrors();

        // Sebelumnya seluruh divisi COO (termasuk coo_team dan ojan) ikut terekspor.
        Excel::assertDownloaded("KPI_UMUM_{$month}.xlsx", function (KpiPerDivisionExport $export): bool {
            $names = collect($export->sheets()[0]->array())->pluck('name')->all();

            return $names === [$this->cooStaff->nama_lengkap];
        });
    }

    public function test_export_sheets_apply_optional_user_constraint(): void
    {
        $month = now()->format('Y-m');
        $year = now()->format('Y');
        $ulsDivisionId = (string) $this->fixtureDivisi($this->uls)->id;
        $expectedAll = [$this->gmdInUlsDivision->nama_lengkap, $this->ulsStaff->nama_lengkap];
        $expectedScoped = [$this->ulsStaff->nama_lengkap];
        $allowed = [$this->ulsStaff->id];

        $names = fn (array $rows): array => collect($rows)->pluck('name')->all();
        $detailNames = fn (array $rows): array => collect($rows)->pluck(1)->unique()->sort()->values()->all();

        $this->assertSame($expectedAll, $names((new KpiPerDivisionSummarySheet($month, $ulsDivisionId))->array()));
        $this->assertSame($expectedScoped, $names((new KpiPerDivisionSummarySheet($month, $ulsDivisionId, null, $allowed))->array()));
        $this->assertSame($expectedAll, $names((new KpiMonthlySummarySheet($year, $ulsDivisionId))->array()));
        $this->assertSame($expectedScoped, $names((new KpiMonthlySummarySheet($year, $ulsDivisionId, null, $allowed))->array()));
        $this->assertSame($expectedAll, $detailNames((new KpiPerDivisionDetailSheet($month, $ulsDivisionId))->array()));
        $this->assertSame($expectedScoped, $detailNames((new KpiPerDivisionDetailSheet($month, $ulsDivisionId, null, $allowed))->array()));
        $this->assertSame($expectedAll, $detailNames((new KpiMonthlyDetailSheet($year, $ulsDivisionId))->array()));
        $this->assertSame($expectedScoped, $detailNames((new KpiMonthlyDetailSheet($year, $ulsDivisionId, null, $allowed))->array()));

        // Batas eksplisit juga berlaku saat userId diminta.
        $this->assertSame([], (new KpiPerDivisionSummarySheet($month, $ulsDivisionId, $this->gmdInUlsDivision->id, $allowed))->array());
    }

    public function test_copy_action_is_limited_to_visible_users_for_area_admin(): void
    {
        $from = now()->format('Y-m');
        $to = now()->addMonth()->format('Y-m');
        $target = now()->addMonth();

        $this->actingAs($this->scopedAdmin);
        Livewire::actingAs($this->scopedAdmin);

        // Mode posisi: posisi dipakai lintas badan usaha, tetapi hanya karyawan terlihat yang disalin.
        Livewire::test(ListKpis::class)
            ->callAction('copy', [
                'copy_mode' => 'position',
                'position' => $this->sharedPosition->id,
                'tanggal1' => $from,
                'tanggal2' => $to,
            ])
            ->assertHasNoActionErrors();

        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->cooTeam->id],
            Kpi::query()->whereYear('date', $target->year)->whereMonth('date', $target->month)->pluck('user_id')->all(),
        );

        // Target di luar cakupan ditolak oleh validasi opsi dan tidak ada KPI yang dibuat.
        Livewire::test(ListKpis::class)
            ->callAction('copy', [
                'copy_mode' => 'individual',
                'source_users' => $this->ulsStaff->id,
                'target_users' => [$this->gmdStaff->id],
                'tanggal1' => $from,
                'tanggal2' => $to,
            ])
            ->assertHasActionErrors(['target_users.0']);

        $this->assertFalse(Kpi::query()
            ->where('user_id', $this->gmdStaff->id)
            ->whereYear('date', $target->year)
            ->whereMonth('date', $target->month)
            ->exists());
    }

    public function test_send_reminder_requires_admin_and_visible_owner(): void
    {
        $this->actingAs($this->scopedAdmin);
        $this->assertTrue($this->invokeResource('canSendReminder', $this->ulsKpi));
        $this->assertTrue($this->invokeResource('canSendReminder', $this->cooTeamKpi));
        $this->assertFalse($this->invokeResource('canSendReminder', $this->gmdKpi));

        $this->actingAs($this->superAdmin);
        $this->assertTrue($this->invokeResource('canSendReminder', $this->gmdKpi));

        $this->actingAs($this->manager);
        $this->assertFalse($this->invokeResource('canSendReminder', $this->managerTeamKpi));
    }

    public function test_checklist_user_picker_and_user_id_are_scoped_for_area_admin(): void
    {
        $this->actingAs($this->scopedAdmin);
        Livewire::actingAs($this->scopedAdmin);

        $component = Livewire::test(ChecklistKPI::class)
            ->assertSeeHtml('id="checklist-user"')
            ->assertSee($this->ulsStaff->nama_lengkap)
            ->assertSee($this->cooTeam->nama_lengkap)
            ->assertDontSee($this->gmdStaff->nama_lengkap)
            ->assertSee('Tugas ojan');

        $component->set('user_id', $this->ulsStaff->id)
            ->assertSee('Tugas uls_staff')
            ->assertDontSee('Tugas ojan');

        // user_id di luar cakupan (diubah dari klien) kembali ke diri sendiri.
        $component->set('user_id', $this->gmdStaff->id)
            ->assertSet('user_id', null)
            ->assertDontSee('Tugas gmd_staff')
            ->assertSee('Tugas ojan');
    }

    public function test_checklist_blocks_staff_from_reading_other_users(): void
    {
        $this->actingAs($this->cooStaff);
        Livewire::actingAs($this->cooStaff);

        Livewire::test(ChecklistKPI::class)
            ->assertDontSeeHtml('id="checklist-user"')
            ->set('user_id', $this->ulsStaff->id)
            ->assertSet('user_id', null)
            ->assertDontSee('Tugas uls_staff')
            ->assertSee('Tugas coo_staff');

        // Atasan dengan tim tetap bisa memilih anggota timnya.
        $this->actingAs($this->manager);
        Livewire::actingAs($this->manager);

        Livewire::test(ChecklistKPI::class)
            ->assertSeeHtml('id="checklist-user"')
            ->set('user_id', $this->managerTeam->id)
            ->assertSee('Tugas gmd_manager_team');
    }

    public function test_checklist_mutations_are_limited_to_viewable_owners(): void
    {
        $foreignDetail = $this->ulsKpi->kpi_detail()->firstOrFail();
        $foreignExtra = $this->makeExtraTask($foreignDetail);

        $this->actingAs($this->cooStaff);
        Livewire::actingAs($this->cooStaff);

        Livewire::test(ChecklistKPI::class)->call('changeKpiStatus', $foreignDetail->id, 'monthly')->assertForbidden();
        Livewire::test(ChecklistKPI::class)->call('openUpdateModal', $foreignDetail->id)->assertForbidden();
        Livewire::test(ChecklistKPI::class)->call('openExtraTaskModal', $foreignDetail->id)->assertForbidden();
        Livewire::test(ChecklistKPI::class)->call('deleteExtraTask', $foreignExtra->id)->assertForbidden();
        Livewire::test(ChecklistKPI::class)
            ->set('parent_id', $foreignDetail->id)
            ->set('description', 'Disusupkan')
            ->set('count_type', 'NON')
            ->call('submitExtraTask')
            ->assertForbidden();

        $this->assertNull($foreignDetail->fresh()->value_result);
        $this->assertNotNull($foreignExtra->fresh());
        $this->assertSame(1, KpiDetail::query()->where('parent_id', $foreignDetail->id)->count());

        // Detail milik sendiri tetap bisa diubah.
        $ownDetail = $this->cooStaffKpi->kpi_detail()->firstOrFail();
        Livewire::test(ChecklistKPI::class)->call('changeKpiStatus', $ownDetail->id, 'monthly')->assertOk();
        $this->assertEquals(1, $ownDetail->fresh()->value_result);
    }

    public function test_checklist_area_admin_can_edit_in_scope_details_past_lock_but_not_outside_scope(): void
    {
        $lockedDetail = $this->makeKpi($this->ulsStaff, '2020-01-15')->kpi_detail()->firstOrFail();
        $outsideDetail = $this->gmdKpi->kpi_detail()->firstOrFail();

        $this->actingAs($this->scopedAdmin);
        Livewire::actingAs($this->scopedAdmin);

        Livewire::test(ChecklistKPI::class)->call('changeKpiStatus', $lockedDetail->id, 'monthly')->assertOk();
        $this->assertEquals(1, $lockedDetail->fresh()->value_result);

        Livewire::test(ChecklistKPI::class)->call('changeKpiStatus', $outsideDetail->id, 'monthly')->assertForbidden();
        $this->assertNull($outsideDetail->fresh()->value_result);
    }

    public function test_team_leader_can_edit_team_kpi_and_checklist_but_not_outside_team(): void
    {
        $teamLeader = $this->fixtureUser('team_leader', 'TEAM LEADER', $this->gmd);
        $member = $this->fixtureUser('team_leader_member', 'STAFF', $this->gmd, $teamLeader);
        $this->resetAccessMemos();

        $memberDetail = $this->makeKpi($member)->kpi_detail()->firstOrFail();
        $outsideDetail = $this->gmdKpi->kpi_detail()->firstOrFail();

        // Menu KPI / API: TEAM LEADER mengelola KPI anggota timnya seperti MANAGER/COORDINATOR.
        $this->assertTrue(Gate::forUser($teamLeader)->allows('update', $memberDetail->kpi));
        $this->assertFalse(Gate::forUser($teamLeader)->allows('update', $outsideDetail->kpi));

        Sanctum::actingAs($teamLeader);
        $this->putJson('/api/v1/kpis/'.$memberDetail->kpi_id, ['percentage' => 80])->assertOk();
        $this->putJson('/api/v1/kpis/'.$outsideDetail->kpi_id, ['percentage' => 80])->assertForbidden();

        $this->actingAs($teamLeader);
        Livewire::actingAs($teamLeader);

        // Checklist dashboard: atasan mengisi checklist anggota timnya.
        Livewire::test(ChecklistKPI::class)
            ->set('user_id', $member->id)
            ->assertSee('Tugas team_leader_member')
            ->assertSeeHtml("changeKpiStatus('{$memberDetail->id}', 'monthly')")
            ->call('changeKpiStatus', $memberDetail->id, 'monthly')
            ->assertOk();
        $this->assertEquals(1, $memberDetail->fresh()->value_result);

        Livewire::test(ChecklistKPI::class)->call('changeKpiStatus', $outsideDetail->id, 'monthly')->assertForbidden();
        $this->assertNull($outsideDetail->fresh()->value_result);
    }

    public function test_delete_extra_task_keeps_kpi_description_shared_with_other_details(): void
    {
        $gmdDetail = $this->gmdKpi->kpi_detail()->with('kpi_description')->firstOrFail();
        $sharedDescriptionId = $gmdDetail->kpi_description_id;
        $ownDetail = $this->cooStaffKpi->kpi_detail()->firstOrFail();

        // Staf COO menempelkan deskripsi milik KPI karyawan GMD ke ekstra task KPI-nya sendiri lewat API.
        Sanctum::actingAs($this->cooStaff);
        $extraId = $this->postJson('/api/v1/kpis/'.$this->cooStaffKpi->id.'/details', [
            'kpi_description_id' => $sharedDescriptionId,
            'parent_id' => $ownDetail->id,
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->cooStaff);
        Livewire::actingAs($this->cooStaff);
        Livewire::test(ChecklistKPI::class)->call('deleteExtraTask', $extraId)->assertOk();

        // Ekstra task terhapus, tetapi deskripsi master yang dipakai karyawan lain tetap ada.
        $this->assertSoftDeleted('kpi_details', ['id' => $extraId]);
        $this->assertNotSoftDeleted('kpi_descriptions', ['id' => $sharedDescriptionId]);
        $this->assertSame('Tugas gmd_staff', $gmdDetail->fresh()->kpi_description?->description);

        // Deskripsi yang hanya dipakai ekstra task itu sendiri tetap ikut dihapus (perilaku lama).
        $privateDescription = KpiDescription::query()->create([
            'kpi_category_id' => 3,
            'description' => 'Ekstra pribadi',
            'is_negative' => false,
        ]);
        $privateExtra = KpiDetail::query()->create([
            'kpi_id' => $ownDetail->kpi_id,
            'parent_id' => $ownDetail->id,
            'kpi_description_id' => $privateDescription->id,
            'count_type' => 'NON',
            'is_extra_task' => 1,
        ]);

        Livewire::test(ChecklistKPI::class)->call('deleteExtraTask', $privateExtra->id)->assertOk();

        $this->assertSoftDeleted('kpi_details', ['id' => $privateExtra->id]);
        $this->assertSoftDeleted('kpi_descriptions', ['id' => $privateDescription->id]);
    }

    public function test_list_page_reuses_eager_loaded_owner_for_scope_checks(): void
    {
        $owners = [$this->ulsStaff->id, $this->cooTeam->id];
        foreach (range(1, 4) as $i) {
            $staff = $this->fixtureUser("uls_extra_{$i}", 'STAFF', $this->uls);
            $this->makeKpi($staff);
            $owners[] = $staff->id;
        }

        $this->actingAs($this->scopedAdmin);
        Livewire::actingAs($this->scopedAdmin);
        $this->resetAccessMemos();

        $areaLookups = 0;
        DB::listen(function (QueryExecuted $query) use (&$areaLookups, $owners): void {
            if (preg_match('/select\s+["`]?area_id["`]?\s+from\s+["`]?users["`]?/i', $query->sql)
                && in_array((int) ($query->bindings[0] ?? 0), $owners, true)) {
                $areaLookups++;
            }
        });

        Livewire::test(ListKpis::class)
            ->loadTable()
            ->assertCanSeeTableRecords(Kpi::query()->whereIn('user_id', $owners)->get())
            ->assertSeeText('Kirim Pengingat');

        // Policy (view/edit) dan send_reminder memakai relasi user yang sudah dimuat: tanpa query area per baris.
        $this->assertSame(0, $areaLookups);
    }

    private function makeKpi(User $owner, ?string $date = null): Kpi
    {
        $description = KpiDescription::query()->create([
            'kpi_category_id' => 3,
            'description' => 'Tugas '.$owner->username,
            'is_negative' => false,
        ]);

        $kpi = Kpi::query()->create([
            'user_id' => $owner->id,
            'kpi_category_id' => 3,
            'kpi_type_id' => 3,
            'date' => $date ?? now()->startOfMonth()->addDays(2)->toDateString(),
            'percentage' => 100,
        ]);

        $kpi->kpi_detail()->create([
            'kpi_description_id' => $description->id,
            'count_type' => 'NON',
        ]);

        return $kpi;
    }

    private function makeExtraTask(KpiDetail $parent): KpiDetail
    {
        return KpiDetail::query()->create([
            'kpi_id' => $parent->kpi_id,
            'parent_id' => $parent->id,
            'kpi_description_id' => $parent->kpi_description_id,
            'count_type' => 'NON',
            'is_extra_task' => 1,
        ]);
    }

    private function invokeListKpis(string $method): mixed
    {
        return (new ReflectionMethod(ListKpis::class, $method))->invoke(null);
    }

    private function invokeResource(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(KpiResource::class, $method))->invoke(null, ...$arguments);
    }
}
