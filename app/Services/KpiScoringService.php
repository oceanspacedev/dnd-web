<?php

namespace App\Services;

use App\Models\Kpi;
use App\Support\Scoring\ScoringRuleData;

class KpiScoringService
{
    /** Batas atas value_result satu indikator: 1.0 = 100%. */
    public const RESULT_CAP = 1.0;

    /**
     * Calculate the score for a single KPI based on its details.
     *
     * @return array Contains 'score' (weighted) and 'actualCount' (sum of value_result)
     */
    public static function calculateKpiScore(Kpi $kpi): array
    {
        // Filter valid KPI details (non-null, non-negative)
        $kpiDetailWithValue = $kpi->kpi_detail->filter(function ($kpiDetail) {
            return $kpiDetail->value_result !== null && $kpiDetail->value_result >= 0;
        });

        // Sum the value_result (0..1 per detail; capped at 1.0 by calculateResultValue since Nov 2026)
        $actualCount = $kpiDetailWithValue->sum('value_result');

        // Use the count of VALID details as the divisor to get the true average
        $count = $kpiDetailWithValue->count();

        $score = 0;
        if ($count > 0) {
            // Formula: (KPI Percentage / 100) * (Sum of Results / Count of Valid Items)
            $score = ($kpi->percentage / 100) * ($actualCount / $count);
        }

        return [
            'score' => $score,
            'actualCount' => $actualCount,
        ];
    }

    /**
     * Calculate the final capped KPI score for a user/period.
     *
     * Dengan $rule: min(bobot KPI, raw * bobot / 100) menurut versi aturan periode itu.
     * Tanpa $rule: rumus lama min(70, raw * 0.7) (pemanggil yang belum memakai aturan berversi).
     *
     * @param  float  $rawKpiScore  Sum of weighted scores from all KPIs (e.g., 100 max)
     * @return float Capped score (max = KPI weight, 70 by default)
     */
    public static function calculateFinalKpiScore(float $rawKpiScore, ?ScoringRuleData $rule = null): float
    {
        if ($rule !== null) {
            return PerformanceScoreCalculator::kpiFinal($rawKpiScore, $rule);
        }

        // Logic from Leaderboard: min(70, rawScore * 0.7)
        return min(70, $rawKpiScore * 0.7);
    }

    /**
     * Calculate the value_result for a KPI detail (nilai yang dipakai skor).
     *
     * Hasil dibatasi maksimal RESULT_CAP (100%). Kelebihan pencapaian satu indikator tidak boleh
     * menutupi indikator lain yang belum tercapai pada rata-rata KPI, agar karyawan tidak hanya
     * mengejar target yang mudah dilampaui. Pencapaian mentah (bisa > 100%) untuk tampilan:
     * calculateRawResultValue().
     */
    public static function calculateResultValue(?float $plan, ?float $actual, bool $isNegative): float
    {
        return min(self::RESULT_CAP, self::calculateRawResultValue($plan, $actual, $isNegative));
    }

    /**
     * Pencapaian mentah satu indikator RESULT tanpa batas atas, hanya untuk tampilan
     * (mis. 600% saat negatif plan 2 actual 0). Skor selalu memakai calculateResultValue().
     *
     * Positif: actual / plan. Negatif: plan / max(actual, 1). Plan kosong/0 atau actual kosong => 0.
     */
    public static function calculateRawResultValue(?float $plan, ?float $actual, bool $isNegative): float
    {
        if ($plan === null || $plan <= 0) {
            return 0;
        }

        if ($actual === null) {
            return 0;
        }

        if ($isNegative) {
            // Avoid division by zero and clamp very small actuals to a sane minimum.
            $denominator = $actual >= 1 ? $actual : 1;

            return $plan / $denominator;
        }

        return $actual / $plan;
    }
}
