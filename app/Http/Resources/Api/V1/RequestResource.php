<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;

/** @mixin \App\Models\Request */
class RequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rawCreatedAt = $this->getRawOriginal('created_at');
        $rawApprovedAt = $this->getRawOriginal('approved_at');

        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nama_lengkap' => $this->user->nama_lengkap,
                'username' => $this->user->username,
            ]),
            'jenistodo' => $this->jenistodo,
            'todo_request' => $this->todo_request,
            'todo_replace' => $this->todo_replace,
            'target_supervisor' => $this->whenLoaded('approveId', fn () => [
                'id' => $this->approveId->id,
                'nama_lengkap' => $this->approveId->nama_lengkap,
                'username' => $this->approveId->username,
            ]),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => [
                'id' => $this->approvedBy->id,
                'nama_lengkap' => $this->approvedBy->nama_lengkap,
                'username' => $this->approvedBy->username,
            ]),
            'approved_at' => $rawApprovedAt ? Date::parse($rawApprovedAt)->toIso8601String() : null,
            'status' => $this->status ?? 'PENDING',
            'created_at' => $rawCreatedAt ? Date::parse($rawCreatedAt)->toIso8601String() : null,
        ];
    }
}
