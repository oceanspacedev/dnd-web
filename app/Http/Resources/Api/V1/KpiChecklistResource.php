<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KpiChecklistResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->resource['user']->id,
                'nama_lengkap' => $this->resource['user']->nama_lengkap,
                'username' => $this->resource['user']->username,
                'divisi' => $this->resource['user']->divisi?->name,
                'area' => $this->resource['user']->area?->name,
            ],
            'periode' => $this->resource['periode'],
            'lock_status' => [
                'is_locked' => $this->resource['is_locked'],
                'deadline' => $this->resource['deadline'],
            ],
            'summary' => [
                'total_indicators' => $this->resource['total_indicators'],
                'completed_indicators' => $this->resource['completed_indicators'],
                'completion_rate_pct' => round($this->resource['completion_rate_pct'], 2),
                'total_kpi_score' => round($this->resource['total_kpi_score'], 2),
                'final_kpi_score_70pct' => round($this->resource['final_kpi_score_70pct'], 2),
            ],
            'categories' => $this->resource['categories'],
        ];
    }
}
