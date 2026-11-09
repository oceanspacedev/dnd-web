<?php

namespace Tests\Unit\Scoring;

use App\Services\KpiScoringService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * value_result satu indikator RESULT (nilai skor) dibatasi maksimal 100% (1.0): kelebihan pencapaian satu
 * indikator tidak boleh menutupi indikator lain yang belum tercapai pada rata-rata KPI. Pencapaian mentah
 * (calculateRawResultValue) tetap tanpa batas untuk tampilan.
 */
class KpiResultCapTest extends TestCase
{
    private const DELTA = 1e-9;

    public function test_result_cap_is_one_hundred_percent(): void
    {
        $this->assertSame(1.0, KpiScoringService::RESULT_CAP);
    }

    /**
     * @return array<string, array{plan: ?float, actual: ?float, negative: bool, expected: float}>
     */
    public static function resultCases(): array
    {
        return [
            // Positif: actual / plan.
            'positif di bawah target tetap apa adanya' => ['plan' => 6.0, 'actual' => 3.0, 'negative' => false, 'expected' => 0.5],
            'positif tepat target = 100%' => ['plan' => 6.0, 'actual' => 6.0, 'negative' => false, 'expected' => 1.0],
            'positif melebihi target dibatasi 100%' => ['plan' => 2.0, 'actual' => 12.0, 'negative' => false, 'expected' => 1.0],
            'positif sedikit melebihi target dibatasi 100%' => ['plan' => 10.0, 'actual' => 10.5, 'negative' => false, 'expected' => 1.0],
            // Negatif: plan / max(actual, 1).
            'negatif di bawah target tetap apa adanya' => ['plan' => 2.0, 'actual' => 4.0, 'negative' => true, 'expected' => 0.5],
            'negatif tepat target = 100%' => ['plan' => 2.0, 'actual' => 2.0, 'negative' => true, 'expected' => 1.0],
            'negatif actual 0 (dulu 600%) dibatasi 100%' => ['plan' => 6.0, 'actual' => 0.0, 'negative' => true, 'expected' => 1.0],
            'negatif actual 1 dari plan 2 (dulu 200%) dibatasi 100%' => ['plan' => 2.0, 'actual' => 1.0, 'negative' => true, 'expected' => 1.0],
            // Tanpa plan/actual tetap 0.
            'plan null = 0' => ['plan' => null, 'actual' => 5.0, 'negative' => false, 'expected' => 0.0],
            'plan 0 = 0' => ['plan' => 0.0, 'actual' => 5.0, 'negative' => false, 'expected' => 0.0],
            'actual null = 0' => ['plan' => 5.0, 'actual' => null, 'negative' => false, 'expected' => 0.0],
            'actual 0 positif = 0' => ['plan' => 5.0, 'actual' => 0.0, 'negative' => false, 'expected' => 0.0],
        ];
    }

    #[DataProvider('resultCases')]
    public function test_result_value_never_exceeds_one_hundred_percent(?float $plan, ?float $actual, bool $negative, float $expected): void
    {
        $result = KpiScoringService::calculateResultValue($plan, $actual, $negative);

        $this->assertEqualsWithDelta($expected, $result, self::DELTA);
        $this->assertLessThanOrEqual(KpiScoringService::RESULT_CAP, $result);
    }

    /**
     * @return array<string, array{plan: ?float, actual: ?float, negative: bool, raw: float}>
     */
    public static function rawCases(): array
    {
        return [
            'positif 12 dari plan 2 = 600% mentah' => ['plan' => 2.0, 'actual' => 12.0, 'negative' => false, 'raw' => 6.0],
            'positif 10.5 dari plan 10 = 105% mentah' => ['plan' => 10.0, 'actual' => 10.5, 'negative' => false, 'raw' => 1.05],
            'negatif actual 0 dari plan 6 = 600% mentah' => ['plan' => 6.0, 'actual' => 0.0, 'negative' => true, 'raw' => 6.0],
            'negatif actual 1 dari plan 2 = 200% mentah' => ['plan' => 2.0, 'actual' => 1.0, 'negative' => true, 'raw' => 2.0],
            'negatif tepat target = 100%' => ['plan' => 2.0, 'actual' => 2.0, 'negative' => true, 'raw' => 1.0],
            'di bawah target sama dengan skor' => ['plan' => 6.0, 'actual' => 3.0, 'negative' => false, 'raw' => 0.5],
            'plan null = 0' => ['plan' => null, 'actual' => 5.0, 'negative' => false, 'raw' => 0.0],
            'actual null = 0' => ['plan' => 5.0, 'actual' => null, 'negative' => true, 'raw' => 0.0],
        ];
    }

    /**
     * Tampilan memakai pencapaian mentah tanpa batas; skor selalu min(1, mentah).
     */
    #[DataProvider('rawCases')]
    public function test_raw_result_is_uncapped_while_scored_result_is_its_capped_value(?float $plan, ?float $actual, bool $negative, float $raw): void
    {
        $this->assertEqualsWithDelta($raw, KpiScoringService::calculateRawResultValue($plan, $actual, $negative), self::DELTA);
        $this->assertEqualsWithDelta(
            min(KpiScoringService::RESULT_CAP, $raw),
            KpiScoringService::calculateResultValue($plan, $actual, $negative),
            self::DELTA,
        );
    }
}
