<?php

namespace Tests\Feature;

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
use App\Services\KpiScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Kolom Result di checklist menampilkan pencapaian mentah (bisa > 100%) sebagai penghargaan,
 * sedangkan skor (value_result dan Total Score) tetap memakai nilai yang dibatasi 100%.
 */
class KpiResultDisplayTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private Area $area;

    private User $staff;

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
        $position = Position::query()->create(['name' => 'PERSONALIA']);
        $this->staff = $this->fixtureUser('personalia_staff', 'STAFF', $this->area, null, ['position_id' => $position->id]);
        $this->resetAccessMemos();

        Date::setTestNow('2026-11-05 10:00:00');

        $this->actingAs($this->staff);
        Livewire::actingAs($this->staff);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_checklist_shows_raw_percentage_above_100_while_total_score_uses_the_capped_value(): void
    {
        $kpi = Kpi::query()->create([
            'user_id' => $this->staff->id,
            'kpi_category_id' => 3,
            'kpi_type_id' => 3,
            'date' => '2026-11-01',
            'percentage' => 100,
        ]);

        // Negatif plan 6 actual 0: mentah 6 / max(0, 1) = 600%, skor 100%.
        $this->makeDetail($kpi, 'gaji (revisi karena kesalahan personalia)', true, 6, 0);
        // Positif plan 6 actual 3: 50%, tidak boleh tertutup kelebihan di atas.
        $this->makeDetail($kpi, 'wajib lapor', false, 6, 3);

        Livewire::test(ChecklistKPI::class)
            ->assertSee('600.00%')
            ->assertSee('50.00%')
            ->assertSeeHtml('title="Melebihi target. Pada skor dihitung 100%."')
            // Total Score = 100% x rata-rata (1.0 + 0.5) / 2 = 75.00 poin, bukan (6.0 + 0.5) / 2 = 325.
            ->assertSee('75.00')
            ->assertDontSee('325.00');
    }

    public function test_raw_result_accessor_is_derived_from_plan_and_actual_even_for_rows_stored_before_the_cap(): void
    {
        $kpi = Kpi::query()->create([
            'user_id' => $this->staff->id,
            'kpi_category_id' => 3,
            'kpi_type_id' => 3,
            'date' => '2026-11-01',
            'percentage' => 100,
        ]);

        // Negatif plan 2 actual 0: mentah 2 / max(0, 1) = 200%, skor 100%.
        $capped = $this->makeDetail($kpi, 'negatif dibatasi', true, 2, 0);
        $this->assertEqualsWithDelta(1.0, (float) $capped->value_result, 1e-9);
        $this->assertEqualsWithDelta(2.0, $capped->raw_result, 1e-9);
        $this->assertTrue($capped->exceeds_target);

        $exact = $this->makeDetail($kpi, 'negatif tepat target', true, 2, 2);
        $this->assertEqualsWithDelta(1.0, $exact->raw_result, 1e-9);
        $this->assertFalse($exact->exceeds_target);

        $non = $kpi->kpi_detail()->create([
            'kpi_description_id' => $exact->kpi_description_id,
            'count_type' => 'NON',
            'value_result' => 1,
        ])->fresh(['kpi_description']);
        $this->assertEqualsWithDelta(1.0, $non->raw_result, 1e-9);
        $this->assertFalse($non->exceeds_target);
    }

    private function makeDetail(Kpi $kpi, string $description, bool $isNegative, float $plan, float $actual): KpiDetail
    {
        $kpiDescription = KpiDescription::query()->create([
            'kpi_category_id' => 3,
            'description' => $description,
            'is_negative' => $isNegative,
        ]);

        return $kpi->kpi_detail()->create([
            'kpi_description_id' => $kpiDescription->id,
            'count_type' => 'RESULT',
            'value_plan' => $plan,
            'value_actual' => $actual,
            'value_result' => KpiScoringService::calculateResultValue($plan, $actual, $isNegative),
        ])->fresh(['kpi_description']);
    }
}
