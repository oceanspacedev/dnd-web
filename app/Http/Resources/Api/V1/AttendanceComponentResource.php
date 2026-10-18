<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AttendanceComponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceComponent */
class AttendanceComponentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,
            'description' => $this->description,
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
            // Komponen bawaan disimpan di kolom lama attendances (late_less_30, late_more_30, sick_days);
            // komponen lain di attendances.component_counts[code].
            'is_builtin' => $this->legacy_column !== null,
        ];
    }
}
