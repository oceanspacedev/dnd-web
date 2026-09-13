<?php

namespace App\Http\Resources\Api\V1;

use App\Models\KpiReminderLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KpiReminderLog */
class KpiReminderLogResource extends JsonResource
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
                'email' => $this->user->email,
                'no_hp' => $this->user->no_hp,
            ]),
            'setting' => $this->whenLoaded('setting', fn () => [
                'id' => $this->setting->id,
                'title' => $this->setting->title,
                'type' => $this->setting->type,
            ]),
            'channel' => $this->channel,
            'destination' => $this->recipient,
            'periode' => $this->sent_at?->format('Y-m'),
            'status' => $this->status,
            'error_message' => $this->error_message,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
