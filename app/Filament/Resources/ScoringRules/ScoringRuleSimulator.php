<?php

namespace App\Filament\Resources\ScoringRules;

use App\Services\PerformanceScoreCalculator;
use App\Support\Periode;
use App\Support\Scoring\ScoringRuleData;

/**
 * Simulasi nilai kinerja pada form Aturan Penilaian.
 *
 * Isian form (belum disimpan) diubah menjadi ScoringRuleData sementara lalu dihitung dengan
 * PerformanceScoreCalculator, rumus yang sama dengan leaderboard, API, dan export. Isian yang belum
 * valid (kosong, bukan angka, negatif) dianggap 0 agar simulasi tetap tampil selama form diisi.
 */
final class ScoringRuleSimulator
{
    public const DEFAULT_WORK_DAYS = 22;

    public const DEFAULT_REVIEW_POINTS = 16;

    public const DEFAULT_KPI_RAW = 100;

    /**
     * Isian awal simulasi: hari kerja 22, semua komponen 0, poin penilaian 16, KPI mentah 100%.
     *
     * @param  iterable<string>  $componentCodes
     * @return array{work_days: int, counts: array<string, int>, review_points: int, kpi_raw: int}
     */
    public static function defaultInputs(iterable $componentCodes): array
    {
        $counts = [];

        foreach ($componentCodes as $code) {
            $counts[$code] = 0;
        }

        return [
            'work_days' => self::DEFAULT_WORK_DAYS,
            'counts' => $counts,
            'review_points' => self::DEFAULT_REVIEW_POINTS,
            'kpi_raw' => self::DEFAULT_KPI_RAW,
        ];
    }

    /**
     * Versi aturan sementara dari state form (nama, berlaku mulai, bobot, poin maksimal, aturan kehadiran).
     *
     * @param  array<string, mixed>  $state
     */
    public static function draftRule(array $state): ScoringRuleData
    {
        $rows = is_array($state['attendance_rules'] ?? null) ? $state['attendance_rules'] : [];
        $attendanceRules = [];

        foreach ($rows as $row) {
            $code = is_array($row) ? ($row['component'] ?? null) : null;

            if (! is_string($code) || $code === '') {
                continue;
            }

            $attendanceRules[$code] = [
                'reduces_base' => (bool) ($row['reduces_base'] ?? false),
                'penalty_points' => (float) self::nonNegative($row['penalty_points'] ?? null),
            ];
        }

        $effectiveFrom = is_string($state['effective_from'] ?? null) && Periode::isValid($state['effective_from'])
            ? $state['effective_from']
            : Periode::current();
        $maxPoints = filter_var($state['review_max_points'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return new ScoringRuleData(
            id: null,
            name: filled($state['name'] ?? null) ? (string) $state['name'] : 'Draf',
            effectiveFrom: $effectiveFrom,
            engine: PerformanceScoreCalculator::ENGINE_STANDARD,
            kpiWeight: (float) self::nonNegative($state['kpi_weight'] ?? null),
            attendanceWeight: (float) self::nonNegative($state['attendance_weight'] ?? null),
            reviewWeight: (float) self::nonNegative($state['review_weight'] ?? null),
            reviewMaxPoints: $maxPoints === false ? 0 : $maxPoints,
            attendanceRules: $attendanceRules,
        );
    }

    /**
     * Hasil simulasi satu versi aturan.
     *
     * @param  array<string, mixed>  $inputs  {work_days, counts: {kode: jumlah}, review_points, kpi_raw}
     * @return array{base_pct: float, penalty: float, final_pct: float, attendance_score: float, review_score: float, kpi_score: float, total: float, has_work_days: bool, kpi_weight: float, attendance_weight: float, review_weight: float}
     */
    public static function run(array $inputs, ScoringRuleData $rule): array
    {
        $counts = [];

        foreach (is_array($inputs['counts'] ?? null) ? $inputs['counts'] : [] as $code => $count) {
            $counts[(string) $code] = self::nonNegative($count);
        }

        $attendance = PerformanceScoreCalculator::attendance($counts, self::nonNegative($inputs['work_days'] ?? null), $rule);
        $review = PerformanceScoreCalculator::review((float) self::nonNegative($inputs['review_points'] ?? null), $rule);
        $kpi = PerformanceScoreCalculator::kpiFinal((float) self::nonNegative($inputs['kpi_raw'] ?? null), $rule);

        return [
            'base_pct' => $attendance->basePct,
            'penalty' => $attendance->penaltyPoints,
            'final_pct' => $attendance->finalPct,
            'attendance_score' => $attendance->score,
            'review_score' => $review,
            'kpi_score' => $kpi,
            'total' => $kpi + $attendance->score + $review,
            'has_work_days' => $attendance->hasWorkDays,
            'kpi_weight' => $rule->kpiWeight,
            'attendance_weight' => $rule->attendanceWeight,
            'review_weight' => $rule->reviewWeight,
        ];
    }

    /**
     * Teks satu metrik hasil simulasi, mis. '81,82%', '10 poin', '10,77 / 15'.
     *
     * @param  array<string, mixed>  $result  hasil run()
     */
    public static function format(array $result, string $metric): string
    {
        $value = (float) ($result[$metric] ?? 0);
        $limit = self::limit($result, $metric);

        $text = match ($metric) {
            'base_pct', 'final_pct' => ScoringRulePresenter::number($value).'%',
            'penalty' => ScoringRulePresenter::number($value).' poin',
            'attendance_score', 'review_score', 'kpi_score', 'total' => ScoringRulePresenter::number($value).' / '.ScoringRulePresenter::number((float) $limit),
            default => ScoringRulePresenter::number($value),
        };

        if (self::exceedsLimit($result, $metric)) {
            $text .= $metric === 'total' ? ' (melebihi 100)' : ' (melebihi bobot)';
        }

        return $text;
    }

    /**
     * True bila skor melebihi bobotnya (atau total melebihi 100), mis. total poin penilaian di atas poin
     * maksimal penilaian.
     *
     * @param  array<string, mixed>  $result  hasil run()
     */
    public static function exceedsLimit(array $result, string $metric): bool
    {
        $limit = self::limit($result, $metric);

        return $limit !== null && (float) ($result[$metric] ?? 0) > $limit + 1e-9;
    }

    /**
     * Batas atas metrik skor: bobotnya, atau 100 untuk total; null untuk metrik lain.
     *
     * @param  array<string, mixed>  $result
     */
    private static function limit(array $result, string $metric): ?float
    {
        return match ($metric) {
            'attendance_score' => (float) ($result['attendance_weight'] ?? 0),
            'review_score' => (float) ($result['review_weight'] ?? 0),
            'kpi_score' => (float) ($result['kpi_weight'] ?? 0),
            'total' => 100.0,
            default => null,
        };
    }

    /**
     * Angka >= 0 (bilangan bulat tetap int agar urutan floating point sama dengan data kehadiran asli);
     * isian kosong/bukan angka/negatif => 0.
     */
    private static function nonNegative(mixed $value): int|float
    {
        if (is_bool($value) || ! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
            return 0;
        }

        $number = $value + 0;

        return is_float($number) && floor($number) === $number && abs($number) < PHP_INT_MAX
            ? (int) $number
            : $number;
    }
}
