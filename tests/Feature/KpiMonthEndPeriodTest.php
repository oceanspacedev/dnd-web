<?php

namespace Tests\Feature;

use App\Exports\KpiMonthlySummarySheet;
use App\Exports\KpiPerDivisionDetailSheet;
use App\Exports\KpiPerDivisionSummarySheet;
use App\Filament\Resources\Kpis\Pages\CreateKpi;
use App\Filament\Resources\Kpis\Pages\ListKpis;
use App\Filament\Widgets\ChecklistKPI;
use App\Models\Area;
use App\Models\Kpi;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\KpiType;
use App\Models\Position;
use App\Models\User;
use App\Services\KpiCacheService;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Bulan KPI dipilih sebagai bulan/tahun. Pada tanggal 29-31, bulan target yang lebih pendek
 * tidak boleh bergeser ke bulan berikutnya (mis. KPI November yang dibuat 31 Oktober).
 */
class KpiMonthEndPeriodTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private Area $area;

    private User $admin;

    private User $staff;

    private Position $position;

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

        $this->area = $this->fixtureArea('COO');
        $this->position = Position::query()->create(['name' => 'SALES']);
        $this->admin = $this->fixtureGlobalAdmin('super_admin', $this->area);
        $this->staff = $this->fixtureUser('sales_staff', 'STAFF', $this->area, null, ['position_id' => $this->position->id]);
        $this->resetAccessMemos();

        Date::setTestNow('2026-10-31 10:00:00');

        $this->actingAs($this->admin);
        Livewire::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_kpi_created_on_31_october_for_november_is_stored_on_1_november(): void
    {
        $description = KpiDescription::query()->create([
            'kpi_category_id' => 3,
            'description' => 'Target penjualan',
            'is_negative' => false,
        ]);

        $undoRepeaterFake = Repeater::fake();

        try {
            Livewire::test(CreateKpi::class)
                ->fillForm([
                    'position_id' => $this->position->id,
                    // Hari mana pun di November: kolom disimpan sebagai 11/2026.
                    'date' => '2026-11-15',
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

        $this->assertSame(
            ['2026-11-01'],
            Kpi::query()->where('user_id', $this->staff->id)->pluck('date')
                ->map(fn ($date): string => Date::parse($date)->toDateString())
                ->all(),
        );
    }

    public function test_copy_on_31_october_reads_september_and_writes_november(): void
    {
        $this->makeKpi($this->staff, '2026-09-05', 'Tugas September');
        $this->makeKpi($this->staff, '2026-10-03', 'Tugas Oktober');

        Livewire::test(ListKpis::class)
            ->mountAction('copy')
            ->assertActionDataSet(['tanggal1' => '2026-10', 'tanggal2' => '2026-11'])
            ->setActionData([
                'copy_mode' => 'position',
                'position' => $this->position->id,
                'tanggal1' => '2026-09',
                'tanggal2' => '2026-11',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $copied = Kpi::query()
            ->with('kpi_detail.kpi_description')
            ->where('user_id', $this->staff->id)
            ->whereYear('date', 2026)
            ->whereMonth('date', 11)
            ->get();

        $this->assertCount(1, $copied);
        $this->assertSame('2026-11-01', Date::parse($copied->first()->date)->toDateString());
        $this->assertSame('Tugas September', $copied->first()->kpi_detail->first()->kpi_description->description);
        $this->assertFalse(Kpi::query()->whereYear('date', 2026)->whereMonth('date', 12)->exists());
    }

    public function test_checklist_on_31_october_shows_the_chosen_month(): void
    {
        $this->makeKpi($this->admin, '2026-11-01', 'Tugas November');
        $this->makeKpi($this->admin, '2026-12-01', 'Tugas Desember');

        foreach (['11/2026', '2026-11'] as $month) {
            Livewire::test(ChecklistKPI::class, ['month' => $month])
                ->assertSee('Tugas November')
                ->assertDontSee('Tugas Desember');
        }
    }

    public function test_monthly_exports_on_31_october_read_the_chosen_month(): void
    {
        $september = $this->makeKpi($this->staff, '2026-09-01', 'Tugas September');
        $september->kpi_detail()->update(['value_result' => 1]);
        $this->makeKpi($this->staff, '2026-10-01', 'Tugas Oktober');

        // Export per bulan September dibuat 31 Oktober: bukan KPI Oktober (31 September = 1 Oktober).
        $detail = (new KpiPerDivisionDetailSheet('2026-09', '', $this->staff->id))->array();
        $this->assertSame(['2026-09'], array_values(array_unique(array_column($detail, 0))));
        $this->assertSame(['Tugas September'], array_column($detail, 5));

        $summary = (new KpiPerDivisionSummarySheet('2026-09', '', $this->staff->id))->array();
        $this->assertSame('1/1', $summary[0]['total_task']);
        $this->assertSame('100%', $summary[0]['score']);

        // Export tahunan: judul kolom bulan Jan-Des, tanpa bulan yang terlewat/terulang.
        $expectedMonths = array_map(
            static fn (int $month): string => Date::create(2026, $month, 1)->isoFormat('MMM'),
            range(1, 12),
        );
        $this->assertSame(
            ['Name', ...$expectedMonths, 'Average'],
            (new KpiMonthlySummarySheet('2026', '', $this->staff->id))->headings(),
        );
        $this->assertCount(12, array_unique($expectedMonths));
    }

    private function makeKpi(User $owner, string $date, string $description): Kpi
    {
        $kpiDescription = KpiDescription::query()->create([
            'kpi_category_id' => 3,
            'description' => $description,
            'is_negative' => false,
        ]);

        $kpi = Kpi::query()->create([
            'user_id' => $owner->id,
            'kpi_category_id' => 3,
            'kpi_type_id' => 3,
            'date' => $date,
            'percentage' => 100,
        ]);

        $kpi->kpi_detail()->create([
            'kpi_description_id' => $kpiDescription->id,
            'count_type' => 'NON',
        ]);

        return $kpi;
    }
}
