<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attendance */
class AttendanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workDays = (int) ($this->work_days ?? 0);
        $lateLess30 = (int) ($this->late_less_30 ?? 0);
        $lateMore30 = (int) ($this->late_more_30 ?? 0);
        $sickDays = (int) ($this->sick_days ?? 0);

        $initialAchv = 0;
        $penalty = 0;
        $finalAchv = 0;
        $score = 0;

        if ($workDays > 0) {
            $initialAchv = max(0, ($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays * 100);
            $penalty = ($lateLess30 * 1) + ($lateMore30 * 3) + ($sickDays * 5);
            $finalAchv = max(0, $initialAchv - $penalty);
            $score = ($finalAchv / 100) * 15;
        }

        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nama_lengkap' => $this->user->nama_lengkap,
                'username' => $this->user->username,
            ]),
            'periode' => $this->periode,
            'work_days' => $workDays,
            'late_less_30' => $lateLess30,
            'late_more_30' => $lateMore30,
            'sick_days' => $sickDays,
            'metrics' => [
                'initial_achievement_pct' => round($initialAchv, 2),
                'penalty_points' => $penalty,
                'final_achievement_pct' => round($finalAchv, 2),
                'attendance_score_15pct' => round($score, 2),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
