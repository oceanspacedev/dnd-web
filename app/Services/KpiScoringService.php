<?php

namespace App\Services;

use App\Models\Kpi;

class KpiScoringService
{
    /**
     * Calculate the score for a single KPI based on its details.
     *
     * @param Kpi $kpi
     * @return array Contains 'score' (weighted) and 'actualCount' (sum of value_result)
     */
    public static function calculateKpiScore(Kpi $kpi): array
    {
        // Filter valid KPI details (non-null, non-negative)
        $kpiDetailWithValue = $kpi->kpi_detail->filter(function ($kpiDetail) {
            return $kpiDetail->value_result !== null && $kpiDetail->value_result >= 0;
        });

        // Sum the value_result (which can be > 1.0 for overachievement or negative KPIs)
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
            'actualCount' => $actualCount
        ];
    }

    /**
     * Calculate the final capped KPI score for a user/period.
     * 
     * @param float $rawKpiScore Sum of weighted scores from all KPIs (e.g., 100 max)
     * @return float Capped score (max 70)
     */
    public static function calculateFinalKpiScore(float $rawKpiScore): float
    {
        // Logic from Leaderboard: min(70, rawScore * 0.7)
        return min(70, $rawKpiScore * 0.7);
    }

    /**
     * Calculate the value_result for a KPI detail.
     *
     * @param float|null $plan
     * @param float|null $actual
     * @param bool $isNegative
     * @return float
     */
    public static function calculateResultValue(?float $plan, ?float $actual, bool $isNegative): float
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
            $ratio = $plan / $denominator;
        } else {
            $ratio = $actual / $plan;
        }

        return $ratio;
    }
}
