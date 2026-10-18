<?php

namespace Tests\Unit\Scoring;

use App\Services\PerformanceScoreCalculator;
use App\Support\Scoring\ScoringRuleData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kalkulator berparameter + aturan lama harus identik dengan rumus kehadiran lama yang di-hard-code
 * (LeaderboardScoreService / LeaderboardKPI / export / AttendanceResource) untuk seluruh grid nilai.
 */
class LegacyAttendanceFormulaEquivalenceTest extends TestCase
{
    private const DELTA = 1e-9;

    /**
     * 14 baris produksi (hanya angka hitungan, anonim) dengan hitungan melebihi hari kerja (> 0) => base negatif.
     *
     * @var list<array{0: int, 1: int, 2: int, 3: int}> [work_days, late_less_30, late_more_30, sick_days]
     */
    private const PRODUCTION_OVERFLOW_ROWS = [
        [26, 26, 0, 1],
        [23, 23, 0, 1],
        [17, 15, 10, 0],
        [24, 21, 6, 0],
        [10, 0, 0, 14],
        [23, 22, 0, 2],
        [22, 25, 0, 0],
        [22, 22, 0, 1],
        [22, 21, 0, 2],
        [2, 0, 3, 0],
        [1, 0, 12, 0],
        [2, 0, 3, 0],
        [1, 0, 7, 0],
        [14, 4, 0, 13],
    ];

    public function test_legacy_rule_matches_the_hard_coded_formula_on_the_whole_grid(): void
    {
        $rule = ScoringRuleData::legacy();
        $cases = 0;
        $bitIdentical = 0;

        for ($workDays = 0; $workDays <= 31; $workDays++) {
            for ($lateLess30 = 0; $lateLess30 <= 10; $lateLess30++) {
                for ($lateMore30 = 0; $lateMore30 <= 3; $lateMore30++) {
                    for ($sickDays = 0; $sickDays <= 6; $sickDays++) {
                        $bitIdentical += $this->assertEquivalent($rule, $workDays, $lateLess30, $lateMore30, $sickDays) ? 1 : 0;
                        $cases++;
                    }
                }
            }
        }

        $this->assertSame(32 * 11 * 4 * 7, $cases);
        // Urutan operasi sama dengan kode lama sehingga skornya bahkan identik bit per bit.
        $this->assertSame($cases, $bitIdentical);
    }

    public function test_legacy_rule_matches_production_rows_whose_counters_exceed_work_days(): void
    {
        $rule = ScoringRuleData::legacy();

        foreach (self::PRODUCTION_OVERFLOW_ROWS as [$workDays, $lateLess30, $lateMore30, $sickDays]) {
            $this->assertGreaterThan(0, $workDays);
            $this->assertLessThan(0, $workDays - $lateLess30 - $lateMore30 - $sickDays);
            $this->assertTrue($this->assertEquivalent($rule, $workDays, $lateLess30, $lateMore30, $sickDays));

            $score = PerformanceScoreCalculator::attendance(
                ['late_less_30' => $lateLess30, 'late_more_30' => $lateMore30, 'sick_days' => $sickDays],
                $workDays,
                $rule,
            );
            $this->assertLessThan(0, $score->basePct);
            $this->assertSame(0.0, $score->clampedBasePct());
            $this->assertSame(0.0, $score->score);
        }

        $this->assertCount(14, self::PRODUCTION_OVERFLOW_ROWS);
    }

    public function test_stored_v1_seed_parses_to_the_hard_coded_legacy_rule(): void
    {
        // Bentuk yang ditulis migration 2026_10_18_090100 (json list) setelah dibaca model / cache.
        $seed = ScoringRuleData::fromArray([
            'id' => null,
            'name' => 'Aturan Lama',
            'effective_from' => '2000-01',
            'engine' => 'standard',
            'kpi_weight' => '70.00',
            'attendance_weight' => '15.00',
            'review_weight' => '15.00',
            'review_max_points' => 20,
            'attendance_rules' => json_decode(json_encode([
                ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 1],
                ['component' => 'late_more_30', 'reduces_base' => true, 'penalty_points' => 3],
                ['component' => 'sick_days', 'reduces_base' => true, 'penalty_points' => 5],
            ]), true),
        ]);

        $this->assertEquals(ScoringRuleData::legacy(), $seed);
        $this->assertSame(ScoringRuleData::legacy()->toArray(), ScoringRuleData::fromArray(ScoringRuleData::legacy()->toArray())->toArray());
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function kpiRawProvider(): iterable
    {
        foreach ([-5.0, -0.5, 0.0, 0.1, 1.56, 4.0, 45.0, 67.2, 96.0, 99.99, 100.0, 100.000001, 101.75, 122.5, 175.0, 1000.0] as $raw) {
            yield (string) $raw => [$raw, min(70, $raw * 0.7)];
        }
    }

    #[DataProvider('kpiRawProvider')]
    public function test_kpi_final_with_legacy_rule_is_identical_to_min_70_raw_times_0_7(float $raw, float $legacy): void
    {
        $this->assertSame(0.7, 70.0 / 100);
        $this->assertSame($legacy, PerformanceScoreCalculator::kpiFinal($raw, ScoringRuleData::legacy()));
    }

    public function test_review_with_legacy_rule_is_identical_to_sum_over_20_times_15(): void
    {
        for ($sum = 0; $sum <= 20; $sum++) {
            $this->assertSame((float) (($sum / 20) * 15), PerformanceScoreCalculator::review((float) $sum, ScoringRuleData::legacy()));
            $this->assertEqualsWithDelta($sum / 20 * 100 * 0.15, PerformanceScoreCalculator::review((float) $sum, ScoringRuleData::legacy()), self::DELTA);
        }

        $this->assertSame(0.0, PerformanceScoreCalculator::review(null, ScoringRuleData::legacy()));
    }

    public function test_unknown_engine_is_rejected_so_future_engines_must_plug_in_explicitly(): void
    {
        $legacy = ScoringRuleData::legacy();
        $rule = new ScoringRuleData(null, 'X', '2030-01', 'experimental', 70, 15, 15, 20, $legacy->attendanceRules);

        $this->expectException(InvalidArgumentException::class);
        PerformanceScoreCalculator::attendance([], 20, $rule);
    }

    /**
     * Bandingkan kalkulator dengan tiga salinan rumus lama; kembalikan true bila skor identik bit per bit.
     */
    private function assertEquivalent(ScoringRuleData $rule, int $workDays, int $lateLess30, int $lateMore30, int $sickDays): bool
    {
        $label = "wd={$workDays} l<30={$lateLess30} l>30={$lateMore30} sick={$sickDays}";
        $score = PerformanceScoreCalculator::attendance(
            ['late_less_30' => $lateLess30, 'late_more_30' => $lateMore30, 'sick_days' => $sickDays],
            $workDays,
            $rule,
        );

        // 1. LeaderboardScoreService::computeRankedScores / LeaderboardKPI::calculateAttendanceScore.
        $legacyScore = 0.0;
        if ($workDays > 0) {
            $initialAchv = (($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays) * 100;
            $penalty = ($lateLess30 * 1) + ($lateMore30 * 3) + ($sickDays * 5);
            $finalAchv = max(0, $initialAchv - $penalty);
            $legacyScore = ($finalAchv / 100) * 15;
        }
        $this->assertEqualsWithDelta($legacyScore, $score->score, self::DELTA, $label);

        // 2. LeaderboardExportController / LeaderboardAttendanceDetailSheet: base tidak dijepit, penalti selalu dihitung.
        $exportInitial = ($workDays > 0)
            ? ($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays * 100
            : 0;
        $exportPenalty = ($lateLess30 * 1) + ($lateMore30 * 3) + ($sickDays * 5);
        $exportFinal = max(0, $exportInitial - $exportPenalty);
        $this->assertEqualsWithDelta($exportInitial, $score->basePct, self::DELTA, $label);
        $this->assertEqualsWithDelta($exportPenalty, $score->penaltyPoints, self::DELTA, $label);
        $this->assertSame($exportPenalty, $score->penaltyPointsNumber(), $label);
        $this->assertEqualsWithDelta($exportFinal, $score->finalPct, self::DELTA, $label);
        $this->assertEqualsWithDelta(($exportFinal / 100) * 15, $score->score, self::DELTA, $label);

        // 3. Api\V1\AttendanceResource / EmployeeReviewController::integratedScore: base dijepit ke 0, hari kerja 0 => 0.
        if ($workDays > 0) {
            $resourceInitial = max(0, ($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays * 100);
            $resourceFinal = max(0, $resourceInitial - $exportPenalty);
            $this->assertEqualsWithDelta($resourceInitial, $score->clampedBasePct(), self::DELTA, $label);
            $this->assertEqualsWithDelta($resourceFinal, $score->finalPct, self::DELTA, $label);
            $this->assertEqualsWithDelta(($resourceFinal / 100) * 15, $score->score, self::DELTA, $label);
            $this->assertTrue($score->hasWorkDays, $label);
        } else {
            $this->assertFalse($score->hasWorkDays, $label);
            $this->assertSame(0.0, $score->score, $label);
        }

        return (float) $legacyScore === $score->score;
    }
}
