<?php

namespace App\Http\Resources\Api\V1;

use App\Services\LeaderboardScoreService;
use App\Support\Scoring\ScoringRuleData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationScoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rule = $this->resource['scoring_rule'] ?? null;

        return [
            'user' => [
                'id' => $this->resource['user']->id,
                'nama_lengkap' => $this->resource['user']->nama_lengkap,
                'username' => $this->resource['user']->username,
            ],
            'periode' => $this->resource['periode'],
            'kpi' => [
                'kpis_count' => $this->resource['kpi']['count'],
                'raw_score' => round($this->resource['kpi']['raw_score'], 2),
                'weighted_score_70pct' => round($this->resource['kpi']['score_70pct'], 2),
            ],
            'attendance' => [
                'has_data' => $this->resource['attendance']['has_data'],
                'work_days' => $this->resource['attendance']['work_days'],
                'achievement_pct' => round($this->resource['attendance']['achievement_pct'], 2),
                'weighted_score_15pct' => round($this->resource['attendance']['score_15pct'], 2),
                'penalty_points' => $this->resource['attendance']['penalty_points'] ?? 0,
            ],
            'review' => [
                'has_data' => $this->resource['review']['has_data'],
                'total_points' => $this->resource['review']['total_points'],
                'max_points' => $this->resource['review']['max_points'] ?? null,
                'rating_percentage' => round($this->resource['review']['rating_percentage'], 2),
                'weighted_score_15pct' => round($this->resource['review']['score_15pct'], 2),
            ],
            'total_performance' => [
                'final_score_100pct' => round($this->resource['total_score'], 2),
                'grade' => $this->resource['grade'],
            ],
            // Versi aturan penilaian periode ini; nama kunci *_70pct / *_15pct dipertahankan, bobot sebenarnya di sini.
            'scoring_rule' => $rule instanceof ScoringRuleData ? LeaderboardScoreService::ruleSummary($rule) : null,
        ];
    }
}
