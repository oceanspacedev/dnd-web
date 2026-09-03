<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardStatsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'periode' => $this->resource['periode'],
            'company_overview' => [
                'total_active_employees' => $this->resource['total_employees'],
                'average_kpi_score' => round($this->resource['avg_kpi_score'], 2),
                'average_attendance_score' => round($this->resource['avg_attendance_score'], 2),
                'average_review_score' => round($this->resource['avg_review_score'], 2),
                'average_total_score' => round($this->resource['avg_total_score'], 2),
            ],
            'daily_activities_today' => [
                'date' => $this->resource['today'],
                'total_tasks' => $this->resource['daily_total_today'],
                'completed_tasks' => $this->resource['daily_completed_today'],
                'pending_tasks' => $this->resource['daily_pending_today'],
                'completion_rate_pct' => round($this->resource['daily_completion_rate'], 2),
            ],
            'pending_requests_count' => $this->resource['pending_requests_count'],
            'top_performers' => $this->resource['top_performers'],
            'bottom_performers' => $this->resource['bottom_performers'],
        ];
    }
}
