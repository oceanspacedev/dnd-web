<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'employee_id' => $this->employee_id,
            'username' => $this->username,
            'nama_lengkap' => $this->nama_lengkap,
            'email' => $this->email,
            'no_hp' => $this->no_hp,
            'role' => $this->role ? [
                'id' => $this->role->id,
                'name' => $this->role->name,
            ] : null,
            'area' => $this->area ? [
                'id' => $this->area->id,
                'name' => $this->area->name,
            ] : null,
            'divisi' => $this->divisi ? [
                'id' => $this->divisi->id,
                'name' => $this->divisi->name,
            ] : null,
            'position' => $this->position ? [
                'id' => $this->position->id,
                'name' => $this->position->name,
            ] : null,
            'supervisor' => $this->approval ? [
                'id' => $this->approval->id,
                'nama_lengkap' => $this->approval->nama_lengkap,
                'username' => $this->approval->username,
                'employee_id' => $this->approval->employee_id,
            ] : null,
            'flags' => [
                'dr' => (bool) $this->dr,
                'wn' => (bool) $this->wn,
                'wr' => (bool) $this->wr,
                'mn' => (bool) $this->mn,
                'mr' => (bool) $this->mr,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
