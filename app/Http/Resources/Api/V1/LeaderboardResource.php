<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaderboardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'rank' => $this->resource['rank'],
            'user' => [
                'id' => $this->resource['user']->id,
                'nama_lengkap' => $this->resource['user']->nama_lengkap,
                'username' => $this->resource['user']->username,
                'area' => $this->resource['user']->area?->name,
                'divisi' => $this->resource['user']->divisi?->name,
                'position' => $this->resource['user']->position?->name,
            ],
            'scores' => [
                'kpi_raw' => round($this->resource['kpi_raw'], 2),
                'kpi_score_70pct' => round($this->resource['kpi_score_70pct'], 2),
                'attendance_score_15pct' => round($this->resource['attendance_score_15pct'], 2),
                'review_score_15pct' => round($this->resource['review_score_15pct'], 2),
                'total_score_100pct' => round($this->resource['total_score'], 2),
            ],
            'grade' => $this->resource['grade'],
        ];
    }
}
