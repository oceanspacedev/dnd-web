<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\EmployeeReview;
use App\Support\Scoring\AttendanceScore;
use App\Support\Scoring\ScoringRuleData;
use InvalidArgumentException;

/**
 * Rumus nilai kinerja yang digerakkan oleh versi aturan (ScoringRuleData).
 *
 * Fungsi murni (tanpa query). Mesin 'standard' adalah satu-satunya mesin saat ini; versi aturan
 * dengan mesin lain akan ditolak di sini sampai mesinnya ditambahkan, sehingga versi lama tetap
 * memakai rumusnya sendiri.
 *
 * Urutan operasi floating point sengaja sama dengan kode lama LeaderboardScoreService agar versi
 * "Aturan Lama" menghasilkan nilai yang identik (lihat LegacyAttendanceFormulaEquivalenceTest).
 */
final class PerformanceScoreCalculator
{
    public const ENGINE_STANDARD = 'standard';

    /** @var list<string> */
    public const ENGINES = [self::ENGINE_STANDARD];

    public static function supportsEngine(string $engine): bool
    {
        return in_array($engine, self::ENGINES, true);
    }

    /**
     * base_pct = (hari kerja - Σ hitungan komponen pengurang) / hari kerja * 100
     * penalti  = Σ hitungan * poin penalti
     * final    = max(0, base_pct - penalti); skor = final / 100 * bobot kehadiran.
     * Hari kerja <= 0 => skor 0.
     *
     * @param  array<string, int|float>  $counts  hitungan per kode komponen; komponen yang tidak ada dianggap 0.
     */
    public static function attendance(array $counts, int|float $workDays, ScoringRuleData $rule): AttendanceScore
    {
        self::assertEngine($rule);

        $reducing = 0;
        $penalty = 0.0;
        $components = [];

        foreach ($rule->attendanceRules as $code => $parameters) {
            $count = $counts[$code] ?? 0;
            $componentPenalty = $count * $parameters['penalty_points'];

            if ($parameters['reduces_base']) {
                $reducing += $count;
            }

            $penalty += $componentPenalty;
            $components[$code] = [
                'count' => $count,
                'reduces_base' => $parameters['reduces_base'],
                'penalty_points' => $parameters['penalty_points'],
                'penalty' => (float) $componentPenalty,
            ];
        }

        if ($workDays <= 0) {
            return new AttendanceScore(
                workDays: $workDays,
                hasWorkDays: false,
                basePct: 0.0,
                penaltyPoints: $penalty,
                finalPct: 0.0,
                score: 0.0,
                weight: $rule->attendanceWeight,
                components: $components,
            );
        }

        $basePct = (($workDays - $reducing) / $workDays) * 100;
        $finalPct = max(0, $basePct - $penalty);

        return new AttendanceScore(
            workDays: $workDays,
            hasWorkDays: true,
            basePct: $basePct,
            penaltyPoints: $penalty,
            finalPct: $finalPct,
            score: ($finalPct / 100) * $rule->attendanceWeight,
            weight: $rule->attendanceWeight,
            components: $components,
        );
    }

    /**
     * Skor kehadiran dari satu baris attendances (null = tidak ada data => skor 0).
     * Baris harus dimuat dengan Attendance::SCORING_COLUMNS; kolom yang tidak di-select melempar
     * MissingAttributeException agar komponen (mis. izin/mangkir) tidak hilang diam-diam dari skor.
     */
    public static function attendanceFor(?Attendance $attendance, ScoringRuleData $rule): AttendanceScore
    {
        if ($attendance === null) {
            return self::attendance([], 0, $rule);
        }

        $counts = [];

        foreach ($rule->componentCodes() as $code) {
            $counts[$code] = $attendance->countFor($code);
        }

        return self::attendance($counts, $attendance->scoringWorkDays(), $rule);
    }

    /**
     * Skor penilaian = jumlah poin / poin maksimal * bobot penilaian; null (tidak ada penilaian) => 0.
     */
    public static function review(?float $sumPoints, ScoringRuleData $rule): float
    {
        self::assertEngine($rule);

        if ($sumPoints === null || $rule->reviewMaxPoints <= 0) {
            return 0.0;
        }

        return ($sumPoints / $rule->reviewMaxPoints) * $rule->reviewWeight;
    }

    /**
     * Persentase penilaian = jumlah poin / poin maksimal * 100; null => 0.
     */
    public static function reviewPercentage(?float $sumPoints, ScoringRuleData $rule): float
    {
        if ($sumPoints === null || $rule->reviewMaxPoints <= 0) {
            return 0.0;
        }

        return ($sumPoints / $rule->reviewMaxPoints) * 100;
    }

    public static function reviewPoints(?EmployeeReview $review): ?float
    {
        if ($review === null) {
            return null;
        }

        return (float) ((int) ($review->responsiveness ?? 0)
            + (int) ($review->problem_solver ?? 0)
            + (int) ($review->helpfulness ?? 0)
            + (int) ($review->initiative ?? 0));
    }

    public static function reviewFor(?EmployeeReview $review, ScoringRuleData $rule): float
    {
        return self::review(self::reviewPoints($review), $rule);
    }

    /**
     * Skor KPI akhir = min(bobot, mentah * bobot / 100). Bobot 70 => 70 / 100 sama persis dengan
     * literal 0.7 di IEEE double, jadi identik dengan rumus lama min(70, mentah * 0.7).
     */
    public static function kpiFinal(float $raw, ScoringRuleData $rule): float
    {
        self::assertEngine($rule);

        return min($rule->kpiWeight, $raw * ($rule->kpiWeight / 100));
    }

    private static function assertEngine(ScoringRuleData $rule): void
    {
        if (! self::supportsEngine($rule->engine)) {
            throw new InvalidArgumentException("Mesin perhitungan aturan penilaian '{$rule->engine}' tidak dikenal.");
        }
    }
}
