<?php

namespace Tests\Feature\Scoring;

use App\Filament\Widgets\LeaderboardKPI;
use App\Imports\EmployeeReviewImport;
use App\Models\EmployeeReview;
use App\Models\ScoringRule;
use App\Services\PerformanceScoreCalculator;
use App\Services\ScoringRuleService;
use App\Support\Scoring\ScoringRuleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Perbaikan lintas bagian setelah aturan penilaian berversi dipasang ke semua permukaan.
 */
class ScoringIntegrationTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_model_rejects_review_max_points_below_reachable_twenty(): void
    {
        $valid = [
            'name' => 'Versi uji',
            'effective_from' => '2027-03',
            'kpi_weight' => 70,
            'attendance_weight' => 15,
            'review_weight' => 15,
            'attendance_rules' => [],
        ];

        try {
            ScoringRule::query()->create(['review_max_points' => 19] + $valid);
            $this->fail('review_max_points 19 seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review_max_points', $exception->errors());
        }

        $this->assertSame(20, ScoringRule::query()->create(['review_max_points' => 20] + $valid)->review_max_points);
    }

    public function test_both_legacy_review_formulas_match_the_engine_bit_for_bit_on_the_valid_scale(): void
    {
        $legacy = ScoringRuleData::legacy();

        // 4 aspek x skala 0-5: total 0..20. Widget/export lama memakai sum/20*100*0.15, API (sum/20)*15.
        for ($sum = 0; $sum <= 20; $sum++) {
            $engine = PerformanceScoreCalculator::review((float) $sum, $legacy);

            $this->assertSame((float) ($sum / 20 * 100 * 0.15), $engine, "widget/export, total {$sum}");
            $this->assertSame((float) (($sum / 20) * 15), $engine, "API, total {$sum}");
        }
    }

    public function test_review_import_rejects_points_outside_the_zero_to_five_scale(): void
    {
        $area = $this->fixtureArea('COO');
        $this->fixtureGlobalAdmin('boss', $area);
        $employee = $this->fixtureUser('reviewed', 'STAFF', $area, null, ['employee_id' => 'EMP-REV']);
        $row = fn (array $points, string $periode): array => [
            'id_karyawan' => 'EMP-REV', 'nama_lengkap' => 'REVIEWED', 'periode' => $periode,
            ...array_combine(['responsiveness', 'problem_solver', 'helpfulness', 'initiative'], $points),
        ];

        $import = new EmployeeReviewImport;
        $this->assertNull($import->model($row([6, 5, 5, 5], '2026-09')));
        $this->assertNull($import->model($row([4.5, 5, 5, 5], '2026-09')));
        $this->assertNull($import->model($row([-1, 5, 5, 5], '2026-09')));
        $this->assertNull($import->model($row(['lima', 5, 5, 5], '2026-09')));

        $review = $import->model($row([5, '4', 0, ''], '2026-09'));
        $this->assertInstanceOf(EmployeeReview::class, $review);
        $this->assertSame([5, 4, 0, 0], [$review->responsiveness, $review->problem_solver, $review->helpfulness, $review->initiative]);
        $this->assertSame($employee->id, (int) $review->user_id);

        $summary = $import->getImportSummary();
        $this->assertSame(4, $summary['skippedCount']);
        $this->assertSame('Nilai penilaian harus bilangan bulat 0 sampai 5 untuk setiap aspek.', $summary['skippedDetails'][0]['reason']);
    }

    public function test_rule_cache_is_not_shared_between_databases_on_the_same_cache_store(): void
    {
        // Store file mengabaikan CACHE_PREFIX: server lain (mis. server demo) dengan database berbeda menulis ke
        // store yang sama. Payload database lain tidak boleh terbaca oleh aplikasi dengan database ini.
        $connection = (string) config('database.default');
        $original = config("database.connections.{$connection}.database");
        $payloadKeys = fn (): array => array_values(array_filter(
            array_keys((fn () => $this->storage)->call(Cache::store()->getStore())),
            fn (string $key): bool => str_starts_with($key, 'scoring_rules:') && str_ends_with($key, ':payload'),
        ));

        $this->assertContains('izin', ScoringRuleService::components()->keys()->all());
        $ownKeys = $payloadKeys();
        $this->assertCount(1, $ownKeys);

        // Database lain menulis payload-nya sendiri (di sini dipalsukan tanpa komponen izin).
        config(["database.connections.{$connection}.database" => 'dnd_demo_lain']);
        $this->resetAccessMemos();
        ScoringRuleService::components();
        $otherKey = array_values(array_diff($payloadKeys(), $ownKeys))[0] ?? null;
        $this->assertNotNull($otherKey, 'Database lain harus memakai kunci cache sendiri.');
        $fake = Cache::get($otherKey);
        $fake['components'] = array_values(array_filter($fake['components'], fn (array $c): bool => $c['code'] !== 'izin'));
        Cache::put($otherKey, $fake, 600);

        config(["database.connections.{$connection}.database" => $original]);
        $this->resetAccessMemos();
        $this->assertContains('izin', ScoringRuleService::components()->keys()->all());
    }

    public function test_leaderboard_widget_ignores_array_filters_from_a_tampered_payload(): void
    {
        $area = $this->fixtureArea('COO');
        $superAdmin = $this->fixtureGlobalAdmin('boss', $area);
        $scopedAdmin = $this->fixtureScopedAdmin('ojan', $area, [$area]);

        foreach ([$superAdmin, $scopedAdmin] as $viewer) {
            $this->actingAs($viewer);
            Livewire::actingAs($viewer);
            $this->resetAccessMemos();

            Livewire::test(LeaderboardKPI::class)
                ->set('area', ['1'])
                ->set('division', ['x' => 'y'])
                ->assertOk()
                ->assertSet('area', '')
                ->assertSet('division', '');
        }
    }
}
