<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Weekly;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Weekly */
class WeeklyResource extends JsonResource
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
            'task' => $this->task,
            'week' => (int) $this->week,
            'year' => (int) $this->year,
            'tipe' => $this->tipe,
            'task_category' => $this->whenLoaded('taskcategory', fn () => [
                'id' => $this->taskcategory->id,
                'name' => $this->taskcategory->task_category,
            ]),
            'task_status' => $this->whenLoaded('taskstatus', fn () => [
                'id' => $this->taskstatus->id,
                'name' => $this->taskstatus->task_status,
            ]),
            'tagged_user' => $this->whenLoaded('tag', fn () => [
                'id' => $this->tag->id,
                'nama_lengkap' => $this->tag->nama_lengkap,
            ]),
            'added_by' => $this->whenLoaded('add', fn () => [
                'id' => $this->add->id,
                'nama_lengkap' => $this->add->nama_lengkap,
            ]),
            'value_plan' => $this->value_plan !== null ? (float) $this->value_plan : null,
            'value_actual' => $this->value_actual !== null ? (float) $this->value_actual : null,
            'status_result' => $this->status_result,
            'value' => $this->value,
            'is_add' => (bool) $this->is_add,
            'is_update' => (bool) $this->is_update,
        ];
    }
}
