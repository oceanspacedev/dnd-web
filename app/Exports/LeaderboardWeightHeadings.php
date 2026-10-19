<?php

namespace App\Exports;

use App\Services\LeaderboardScoreService;
use App\Support\Scoring\ScoringRuleData;

/**
 * Judul kolom skor leaderboard yang memuat bobot dari versi aturan penilaian periode export.
 *
 * Teks judul tetap sama ('KPI Score (70%)' dst.); hanya angka bobotnya yang mengikuti aturan. Export tahunan
 * yang melewati perubahan bobot menampilkan semua bobot yang dipakai, mis. 'KPI Score (60/70%)'.
 */
final class LeaderboardWeightHeadings
{
    /**
     * @param  list<ScoringRuleData>  $rules  kosong => aturan lama (70/15/15).
     * @return array{0: string, 1: string, 2: string}
     */
    public static function scoreHeadings(array $rules): array
    {
        if ($rules === []) {
            $rules = [ScoringRuleData::legacy()];
        }

        $label = static fn (callable $weight): string => LeaderboardScoreService::weightLabel(
            array_map(static fn (ScoringRuleData $rule): float => $weight($rule), $rules),
        );

        return [
            'KPI Score ('.$label(static fn (ScoringRuleData $rule): float => $rule->kpiWeight).'%)',
            'Attendance Score ('.$label(static fn (ScoringRuleData $rule): float => $rule->attendanceWeight).'%)',
            'Activity Score ('.$label(static fn (ScoringRuleData $rule): float => $rule->reviewWeight).'%)',
        ];
    }
}
