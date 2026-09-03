<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CutpointResource extends JsonResource
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
            'point' => (float) ($this->point ?? 0),
            'periode' => $this->periode,
            'keterangan' => $this->keterangan,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
