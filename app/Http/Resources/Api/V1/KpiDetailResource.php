<?php

namespace App\Http\Resources\Api\V1;

use App\Models\KpiDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KpiDetail */
class KpiDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isNegative = (bool) ($this->kpi_description?->is_negative ?? false);

        return [
            'id' => $this->id,
            'kpi_id' => $this->kpi_id,
            'parent_id' => $this->parent_id,
            'kpi_description' => $this->whenLoaded('kpi_description', fn () => [
                'id' => $this->kpi_description->id,
                'description' => $this->kpi_description->description,
                'is_negative' => $isNegative,
            ]),
            'count_type' => $this->count_type,
            'value_plan' => $this->value_plan !== null ? (float) $this->value_plan : null,
            'value_actual' => $this->value_actual !== null ? (float) $this->value_actual : null,
            'value_result' => $this->value_result !== null ? (float) $this->value_result : null,
            // Pencapaian mentah untuk tampilan (bisa > 1.0, mis. 6.0 = 600%); skor tetap memakai value_result (maks 1.0).
            'raw_result' => (float) $this->raw_result,
            'exceeds_target' => (bool) $this->exceeds_target,
            'subtasks' => $this->subtasks,
            'is_extra_task' => (bool) $this->is_extra_task,
            'start' => $this->start,
            'end' => $this->end,
        ];
    }
}
