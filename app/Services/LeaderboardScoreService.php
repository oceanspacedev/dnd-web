<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Attendance;
use App\Models\Divisi;
use App\Models\EmployeeReview;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class LeaderboardScoreService
{
    /**
     * Ranked API scores for a period. User models are not loaded until hydrateUsers().
     *
     * @return list<array<string, mixed>>
     */
    public function rankedScores(string $periode, ?int $areaId = null, ?int $divisiId = null, ?string $search = null): array
    {
        $rows = Cache::remember(
            $this->cacheKey($periode, $areaId, $divisiId),
            $this->ttl(),
            fn (): array => $this->computeRankedScores($periode, $areaId, $divisiId),
        );

        $search = is_string($search) ? trim($search) : '';

        if ($search === '') {
            return $rows;
        }

        $needle = mb_strtolower($search);
        $filtered = array_values(array_filter(
            $rows,
            function (array $row) use ($needle): bool {
                return str_contains(mb_strtolower((string) $row['nama_lengkap']), $needle)
                    || str_contains(mb_strtolower((string) $row['username']), $needle);
            },
        ));

        foreach ($filtered as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        return $filtered;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function hydrateUsers(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $users = User::query()
            ->select(['id', 'nama_lengkap', 'username', 'area_id', 'divisi_id', 'position_id'])
            ->with(['divisi:id,name', 'area:id,name', 'position:id,name'])
            ->whereIn('id', array_column($rows, 'user_id'))
            ->get()
            ->keyBy('id');

        foreach ($rows as &$row) {
            $row['user'] = $users->get($row['user_id']);
        }
        unset($row);

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['user'] !== null,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{name: string, employee_count: int, average_score: float, highest_score: float, lowest_score: float}>
     */
    public function groupAverages(array $rows, string $group): array
    {
        $idKey = $group === 'area' ? 'area_id' : 'divisi_id';
        $names = $group === 'area'
            ? Area::query()->pluck('name', 'id')
            : Divisi::query()->pluck('name', 'id');

        $buckets = [];
        foreach ($rows as $row) {
            $id = $row[$idKey] ?? null;
            $label = $id ? (string) ($names[$id] ?? 'Unassigned') : 'Unassigned';
            $buckets[$label][] = (float) $row['total_score'];
        }

        $stats = [];
        foreach ($buckets as $name => $values) {
            $stats[] = [
                'name' => $name,
                'employee_count' => count($values),
                'average_score' => round(array_sum($values) / count($values), 2),
                'highest_score' => round(max($values), 2),
                'lowest_score' => round(min($values), 2),
            ];
        }

        usort($stats, fn (array $a, array $b): int => $b['average_score'] <=> $a['average_score']);

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function computeRankedScores(string $periode, ?int $areaId, ?int $divisiId): array
    {
        $periodStart = Date::createFromFormat('!Y-m', $periode)->startOfMonth();
        $periodEnd = $periodStart->copy()->addMonth();

        $users = User::query()
            ->select(['id', 'nama_lengkap', 'username', 'area_id', 'divisi_id', 'position_id'])
            ->whereNull('deleted_at')
            ->when($areaId, fn ($query) => $query->where('area_id', $areaId))
            ->when($divisiId, fn ($query) => $query->where('divisi_id', $divisiId))
            ->get();

        if ($users->isEmpty()) {
            return [];
        }

        /** @var Collection<int, User> $users */
        $userIds = $users->pluck('id')->all();

        $rawKpiScores = DB::table('kpis')
            ->join('kpi_details', 'kpi_details.kpi_id', '=', 'kpis.id')
            ->whereIn('kpis.user_id', $userIds)
            ->whereNull('kpis.deleted_at')
            ->whereNull('kpi_details.deleted_at')
            ->where('kpis.date', '>=', $periodStart)
            ->where('kpis.date', '<', $periodEnd)
            ->selectRaw('kpis.user_id, COALESCE(SUM(kpi_details.value_result), 0) as raw_score')
            ->groupBy('kpis.user_id')
            ->pluck('raw_score', 'kpis.user_id');

        $attendances = Attendance::query()
            ->whereIn('user_id', $userIds)
            ->where('periode', $periode)
            ->get(['user_id', 'late_less_30', 'late_more_30', 'sick_days', 'work_days'])
            ->keyBy('user_id');

        $reviews = EmployeeReview::query()
            ->whereIn('user_id', $userIds)
            ->where('periode', $periode)
            ->get(['user_id', 'responsiveness', 'problem_solver', 'helpfulness', 'initiative'])
            ->keyBy('user_id');

        $leaderboard = [];

        foreach ($users as $user) {
            $rawKpiScore = (float) ($rawKpiScores->get($user->id) ?? 0);
            $kpiScore70 = KpiScoringService::calculateFinalKpiScore($rawKpiScore);

            $att = $attendances->get($user->id);
            $attScore15 = 0.0;
            if ($att && (int) $att->work_days > 0) {
                $workDays = (int) $att->work_days;
                $lateLess = (int) ($att->late_less_30 ?? 0);
                $lateMore = (int) ($att->late_more_30 ?? 0);
                $sick = (int) ($att->sick_days ?? 0);
                $initialAchv = (($workDays - $lateLess - $lateMore - $sick) / $workDays) * 100;
                $penalty = ($lateLess * 1) + ($lateMore * 3) + ($sick * 5);
                $finalAchv = max(0, $initialAchv - $penalty);
                $attScore15 = ($finalAchv / 100) * 15;
            }

            $rev = $reviews->get($user->id);
            $revScore15 = 0.0;
            if ($rev) {
                $totPoints = (int) ($rev->responsiveness ?? 0)
                    + (int) ($rev->problem_solver ?? 0)
                    + (int) ($rev->helpfulness ?? 0)
                    + (int) ($rev->initiative ?? 0);
                $revScore15 = ($totPoints / 20) * 15;
            }

            $totalScore = $kpiScore70 + $attScore15 + $revScore15;

            $leaderboard[] = [
                'user_id' => (int) $user->id,
                'nama_lengkap' => (string) $user->nama_lengkap,
                'username' => (string) $user->username,
                'area_id' => $user->area_id ? (int) $user->area_id : null,
                'divisi_id' => $user->divisi_id ? (int) $user->divisi_id : null,
                'position_id' => $user->position_id ? (int) $user->position_id : null,
                'kpi_raw' => $rawKpiScore,
                'kpi_score_70pct' => $kpiScore70,
                'attendance_score_15pct' => $attScore15,
                'review_score_15pct' => $revScore15,
                'total_score' => $totalScore,
                'grade' => $this->grade($totalScore),
            ];
        }

        usort($leaderboard, fn (array $a, array $b): int => $b['total_score'] <=> $a['total_score']);

        foreach ($leaderboard as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        return $leaderboard;
    }

    private function grade(float $totalScore): string
    {
        return match (true) {
            $totalScore >= 85 => 'A (Istimewa / Outstanding)',
            $totalScore >= 75 => 'B (Baik / Good)',
            $totalScore >= 60 => 'C (Cukup / Satisfactory)',
            $totalScore >= 50 => 'D (Kurang / Needs Improvement)',
            default => 'E (Sangat Kurang / Poor)',
        };
    }

    private function cacheKey(string $periode, ?int $areaId, ?int $divisiId): string
    {
        return 'leaderboard:api:'.$periode.':'.($areaId ?? 'all').':'.($divisiId ?? 'all');
    }

    private function ttl(): int
    {
        return max(1, (int) config('kpi.cache_ttl_seconds.leaderboard', 60));
    }
}
