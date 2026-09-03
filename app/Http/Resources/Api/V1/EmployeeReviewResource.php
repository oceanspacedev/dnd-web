<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        $maxPoints = 20;
        $score15 = ($totalPoints / $maxPoints) * 15;

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
                'rating_percentage' => round(($totalPoints / $maxPoints) * 100, 2),
                'review_score_15pct' => round($score15, 2),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
