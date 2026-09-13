<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Daily;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;

/** @mixin Daily */
class DailyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Format raw date from attributes if accessor returns timestamp
        $rawDate = $this->getRawOriginal('date');
        $formattedDate = $rawDate ? Date::parse($rawDate)->format('Y-m-d') : null;

        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nama_lengkap' => $this->user->nama_lengkap,
                'username' => $this->user->username,
            ]),
            'date' => $formattedDate,
            'task' => $this->task,
            'time' => $this->time,
            'tipe' => $this->tipe,
            'status' => (int) $this->status,
            'ontime' => (bool) $this->ontime,
            'isplan' => (bool) $this->isplan,
            'isupdate' => (bool) $this->isupdate,
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
            'logs_count' => $this->whenCounted('dailyLog'),
            'logs' => DailyLogResource::collection($this->whenLoaded('dailyLog')),
        ];
    }
}
