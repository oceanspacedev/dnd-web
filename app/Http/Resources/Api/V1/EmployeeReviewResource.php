<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EmployeeReview;
use App\Services\LeaderboardScoreService;
use App\Services\PerformanceScoreCalculator;
use App\Services\ScoringRuleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeReview */
class EmployeeReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resp = (int) ($this->responsiveness ?? 0);
        $prob = (int) ($this->problem_solver ?? 0);
        $help = (int) ($this->helpfulness ?? 0);
        $init = (int) ($this->initiative ?? 0);

        $totalPoints = $resp + $prob + $help + $init;

        // Poin maksimal & bobot penilaian dari versi aturan periode review ini (memo per request, tanpa
        // query per baris); kunci review_score_15pct dipertahankan untuk klien lama.
        $rule = ScoringRuleService::forPeriode($this->periode);
        $maxPoints = $rule->reviewMaxPoints;
        $score15 = PerformanceScoreCalculator::review($totalPoints, $rule);

        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nama_lengkap' => $this->user->nama_lengkap,
                'username' => $this->user->username,
            ]),
            'periode' => $this->periode,
            'ratings' => [
                'responsiveness' => $resp,
                'problem_solver' => $prob,
                'helpfulness' => $help,
                'initiative' => $init,
                'total_points' => $totalPoints,
                'max_points' => $maxPoints,
            ],
            'metrics' => [
                'rating_percentage' => round(PerformanceScoreCalculator::reviewPercentage($totalPoints, $rule), 2),
                'review_score_15pct' => round($score15, 2),
                'review_weight' => $rule->reviewWeight,
            ],
            'scoring_rule_id' => $rule->id,
            // Ringkasan aturan dengan bentuk yang sama seperti endpoint lain (scoring_rule_id dipertahankan).
            'scoring_rule' => LeaderboardScoreService::ruleSummary($rule),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
