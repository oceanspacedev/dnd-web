<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Attendance;
use App\Services\PerformanceScoreCalculator;
use App\Services\ScoringRuleService;
use App\Support\Scoring\AttendanceComponentData;
use App\Support\Scoring\AttendanceCountInput;
use App\Support\Scoring\ScoringRuleData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attendance */
class AttendanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Metrik dihitung dengan versi aturan penilaian yang berlaku untuk periode data ini
     * (ScoringRuleService::forPeriode, memo per periode sehingga koleksi tidak query per baris).
     * Bentuk metrik lama dipertahankan: base dijepit ke 0 sebelum penalti, dan hari kerja 0 => semua 0.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Attendance $attendance */
        $attendance = $this->resource;
        $rule = ScoringRuleService::forPeriode($attendance->periode);
        $components = $this->outputComponents($rule);
        $counts = $this->counts($attendance, array_keys($components));
        $score = PerformanceScoreCalculator::attendance($counts, $attendance->scoringWorkDays(), $rule);

        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nama_lengkap' => $this->user->nama_lengkap,
                'username' => $this->user->username,
            ]),
            'periode' => $this->periode,
            'work_days' => (int) ($this->work_days ?? 0),
            'late_less_30' => (int) ($this->late_less_30 ?? 0),
            'late_more_30' => (int) ($this->late_more_30 ?? 0),
            'sick_days' => (int) ($this->sick_days ?? 0),
            'metrics' => [
                'initial_achievement_pct' => round($score->hasWorkDays ? $score->clampedBasePct() : 0, 2),
                'penalty_points' => $score->hasWorkDays ? $score->penaltyPointsNumber() : 0,
                'final_achievement_pct' => round($score->finalPct, 2),
                // Nama kunci lama dipertahankan; nilainya memakai bobot kehadiran aturan periode ini.
                'attendance_score_15pct' => round($score->score, 2),
                'attendance_score' => round($score->score, 2),
            ],
            // Hitungan per komponen kehadiran (komponen aktif + komponen yang dinilai aturan periode ini).
            'components' => collect($components)
                ->map(fn (AttendanceComponentData $component, string $code): array => [
                    'name' => $component->name,
                    'unit' => $component->unit,
                    'count' => $counts[$code] ?? 0,
                ])
                ->all(),
            'attendance_weight' => $rule->attendanceWeight,
            'scoring_rule' => [
                'id' => $rule->id,
                'name' => $rule->name,
                'effective_from' => $rule->effectiveFrom,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Komponen aktif (urut sort_order) ditambah komponen yang dinilai aturan periode ini.
     *
     * @return array<string, AttendanceComponentData>
     */
    private function outputComponents(ScoringRuleData $rule): array
    {
        $components = AttendanceCountInput::activeComponents()->all();

        foreach ($rule->componentCodes() as $code) {
            $components[$code] ??= ScoringRuleService::component($code)
                ?? new AttendanceComponentData($code, $code, 'hari', null, AttendanceCountInput::storageColumn($code), PHP_INT_MAX, false);
        }

        return $components;
    }

    /**
     * Hitungan per kode. Tabel attendances tanpa kolom component_counts (skema lama/manual; data selalu
     * dimuat dengan select *) dibaca sebagai 0 untuk komponen non-kolom, bukan error.
     *
     * @param  list<string>  $codes
     * @return array<string, int>
     */
    private function counts(Attendance $attendance, array $codes): array
    {
        $hasComponentCounts = ! $attendance->exists
            || $attendance->wasRecentlyCreated
            || array_key_exists('component_counts', $attendance->getAttributes());
        $counts = [];

        foreach ($codes as $code) {
            $counts[$code] = $hasComponentCounts || AttendanceCountInput::storageColumn($code) !== null
                ? $attendance->countFor($code)
                : 0;
        }

        return $counts;
    }
}
