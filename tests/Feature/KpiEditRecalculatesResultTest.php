<?php

namespace Tests\Feature;

use App\Filament\Resources\Kpis\Pages\EditKpi;
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
 * Form edit KPI admin bisa mengubah value_plan, count_type, atau deskripsi baris yang sudah punya
 * value_actual. value_result baris RESULT harus dihitung ulang saat disimpan (EditKpi::afterSave),
 * bukan tertinggal pada nilai lama; baris NON tidak disentuh.
 */
class KpiEditRecalculatesResultTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private Area $area;

    private User $admin;

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
        $this->admin = $this->fixtureGlobalAdmin('super_admin', $this->area);
        $this->staff = $this->fixtureUser('personalia_staff', 'STAFF', $this->area, null, ['position_id' => $position->id]);
        $this->resetAccessMemos();

        Date::setTestNow('2026-11-05 10:00:00');

        $this->actingAs($this->admin);
        Livewire::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_changing_plan_of_negative_row_keeps_capped_score_but_updates_raw_result(): void
    {
        $kpi = $this->makeKpi();
        // Negatif plan 6 actual 0: mentah 6 / max(0, 1) = 600%, skor dibatasi 100%.
        $detail = $this->makeDetail($kpi, 'revisi gaji', true, 6, 0);
        $this->assertEqualsWithDelta(6.0, $detail->raw_result, 1e-9);

        Livewire::test(EditKpi::class, ['record' => $kpi->getRouteKey()])
            ->fillForm(["kpi_detail.record-{$detail->id}.value_plan" => 2])
            ->call('save')
            ->assertHasNoFormErrors();

        $detail = $detail->fresh(['kpi_description']);
        $this->assertEqualsWithDelta(2.0, (float) $detail->value_plan, 1e-9);
        // Skor tetap 100%, tapi pencapaian mentah turun dari 600% ke 200%.
        $this->assertEqualsWithDelta(1.0, (float) $detail->value_result, 1e-9);
        $this->assertEqualsWithDelta(2.0, $detail->raw_result, 1e-9);
    }

    public function test_changing_plan_of_positive_row_recalculates_value_result(): void
    {
        $kpi = $this->makeKpi();
        // Positif plan 10 actual 10: 100%.
        $detail = $this->makeDetail($kpi, 'wajib lapor', false, 10, 10);

        Livewire::test(EditKpi::class, ['record' => $kpi->getRouteKey()])
            ->fillForm(["kpi_detail.record-{$detail->id}.value_plan" => 20])
            ->call('save')
            ->assertHasNoFormErrors();

        // Plan dinaikkan ke 20 dengan actual tetap 10: 50%.
        $this->assertEqualsWithDelta(0.5, (float) $detail->fresh()->value_result, 1e-9);
    }

    public function test_changing_description_or_count_type_recalculates_value_result(): void
    {
        $kpi = $this->makeKpi();
        // Positif plan 10 actual 5: 50%.
        $positive = $this->makeDetail($kpi, 'wajib lapor', false, 10, 5);
        $negativeDescription = KpiDescription::query()->create([
            'kpi_category_id' => 3,
            'description' => 'keluhan pelanggan',
            'is_negative' => true,
        ]);
        // NON yang sudah dicentang (1), lalu diubah jadi RESULT tanpa actual.
        $non = $kpi->kpi_detail()->create([
            'kpi_description_id' => $positive->kpi_description_id,
            'count_type' => 'NON',
            'value_result' => 1,
        ]);

        Livewire::test(EditKpi::class, ['record' => $kpi->getRouteKey()])
            ->fillForm(["kpi_detail.record-{$positive->id}.kpi_description_id" => $negativeDescription->id])
            // count_type diisi lebih dulu: afterStateUpdated-nya mengosongkan value_plan.
            ->fillForm(["kpi_detail.record-{$non->id}.count_type" => 'RESULT'])
            ->fillForm(["kpi_detail.record-{$non->id}.value_plan" => 4])
            ->call('save')
            ->assertHasNoFormErrors();

        // Deskripsi negatif: 10 / 5 = 200% mentah, skor dibatasi 100%.
        $positive = $positive->fresh(['kpi_description']);
        $this->assertSame($negativeDescription->id, $positive->kpi_description_id);
        $this->assertEqualsWithDelta(1.0, (float) $positive->value_result, 1e-9);
        $this->assertEqualsWithDelta(2.0, $positive->raw_result, 1e-9);

        // RESULT tanpa actual: 0, bukan sisa 1 dari toggle NON.
        $non = $non->fresh();
        $this->assertSame('RESULT', $non->count_type);
        $this->assertEqualsWithDelta(0.0, (float) $non->value_result, 1e-9);
    }

    public function test_saving_corrects_stale_result_on_untouched_rows_and_leaves_non_rows_alone(): void
    {
        $kpi = $this->makeKpi();
        // Data lama sebelum batas 100%: negatif plan 2 actual 0 masih menyimpan 6.0 (600%).
        $stale = $this->makeDetail($kpi, 'revisi gaji', true, 2, 0);
        $stale->forceFill(['value_result' => 6.0])->save();
        $checked = $kpi->kpi_detail()->create([
            'kpi_description_id' => $stale->kpi_description_id,
            'count_type' => 'NON',
            'value_result' => 1,
        ]);
        $unchecked = $kpi->kpi_detail()->create([
            'kpi_description_id' => $stale->kpi_description_id,
            'count_type' => 'NON',
            'value_result' => 0,
        ]);

        // Hanya persentase KPI yang diubah; baris detail tidak disentuh.
        Livewire::test(EditKpi::class, ['record' => $kpi->getRouteKey()])
            ->fillForm(['percentage' => 80])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(80, (int) $kpi->fresh()->percentage);
        $this->assertEqualsWithDelta(1.0, (float) $stale->fresh()->value_result, 1e-9);
        $this->assertEqualsWithDelta(1.0, (float) $checked->fresh()->value_result, 1e-9);
        $this->assertEqualsWithDelta(0.0, (float) $unchecked->fresh()->value_result, 1e-9);
    }

    public function test_sync_result_value_only_saves_result_rows_whose_value_changed(): void
    {
        $kpi = $this->makeKpi();
        $upToDate = $this->makeDetail($kpi, 'wajib lapor', false, 10, 5);
        $non = $kpi->kpi_detail()->create([
            'kpi_description_id' => $upToDate->kpi_description_id,
            'count_type' => 'NON',
            'value_result' => 1,
        ]);

        $this->assertFalse($upToDate->syncResultValue());
        $this->assertFalse($non->syncResultValue());
        $this->assertEqualsWithDelta(1.0, (float) $non->fresh()->value_result, 1e-9);

        // Relasi deskripsi yang sudah dimuat (positif) tidak boleh dipakai setelah id-nya diganti ke negatif.
        $negativeDescription = KpiDescription::query()->create([
            'kpi_category_id' => 3,
            'description' => 'keluhan pelanggan',
            'is_negative' => true,
        ]);
        $upToDate->kpi_description_id = $negativeDescription->id;
        $upToDate->save();

        $this->assertTrue($upToDate->syncResultValue());
        $this->assertEqualsWithDelta(1.0, (float) $upToDate->fresh()->value_result, 1e-9);
    }

    private function makeKpi(): Kpi
    {
        return Kpi::query()->create([
            'user_id' => $this->staff->id,
            'kpi_category_id' => 3,
            'kpi_type_id' => 3,
            'date' => '2026-11-01',
            'percentage' => 100,
        ]);
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
