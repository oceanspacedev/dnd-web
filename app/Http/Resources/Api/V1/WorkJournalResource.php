<?php

namespace App\Http\Resources\Api\V1;

use App\Models\WorkJournal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;

/** @mixin WorkJournal */
class WorkJournalResource extends JsonResource
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
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nama_lengkap' => $this->user->nama_lengkap,
                'username' => $this->user->username,
                'divisi' => $this->user->divisi?->name,
                'area' => $this->user->area?->name,
                'position' => $this->user->position?->name,
            ]),
            'date' => $this->date ? Date::parse($this->date)->format('Y-m-d') : null,
            'activity' => $this->activity,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
