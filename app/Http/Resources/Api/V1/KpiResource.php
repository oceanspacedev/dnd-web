<?php

namespace App\Http\Resources\Api\V1;

use App\Services\KpiScoringService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KpiResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Calculate score on the fly if details are loaded
        $scoring = null;
        if ($this->relationLoaded('kpi_detail')) {
            $calc = KpiScoringService::calculateKpiScore($this->resource);
            $scoring = [
                'raw_score' => round($calc['score'], 2),
                'actual_count' => round($calc['actualCount'], 2),
                'valid_items_count' => $this->kpi_detail->filter(fn ($d) => $d->value_result !== null && $d->value_result >= 0)->count(),
            ];
        }

        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nama_lengkap' => $this->user->nama_lengkap,
                'username' => $this->user->username,
            ]),
            'kpi_category' => $this->whenLoaded('kpi_category', fn () => [
                'id' => $this->kpi_category->id,
                'name' => $this->kpi_category->name,
            ]),
            'kpi_type' => $this->whenLoaded('kpi_type', fn () => [
                'id' => $this->kpi_type->id,
                'name' => $this->kpi_type->name,
            ]),
            'date' => $this->date,
            'percentage' => (float) $this->percentage,
            'scoring' => $scoring,
            'details' => KpiDetailResource::collection($this->whenLoaded('kpi_detail')),
        ];
    }
}
