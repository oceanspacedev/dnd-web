<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OveropenResource extends JsonResource
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
            ]),
            'supervisor' => $this->whenLoaded('leader', fn () => [
                'id' => $this->leader->id,
                'nama_lengkap' => $this->leader->nama_lengkap,
                'username' => $this->leader->username,
            ]),
            'week' => (int) $this->week,
            'year' => (int) $this->year,
            'daily' => (int) ($this->daily ?? 0),
            'weekly' => (int) ($this->weekly ?? 0),
            'monthly' => (int) ($this->monthly ?? 0),
            'point' => (float) ($this->point ?? 0),
            'keterangan' => $this->keterangan,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
