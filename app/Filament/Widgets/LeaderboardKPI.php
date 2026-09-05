<?php

namespace App\Filament\Widgets;

use App\Models\Area;
use App\Models\Divisi;
use App\Models\User;
use App\Services\KpiScoringService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

class LeaderboardKPI extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.leaderboard-kpi';

    protected int|string|array $columnSpan = 'full';

    public $user_id;

    public $month;

    public $area;

    public $division;

    public function mount($user_id = null, $month = null): void
    {
        $this->user_id = $user_id;
        $this->month = $month ?? now()->format('Y-m');
        $this->area = '';
        $this->division = '';
    }

    protected function getAreas()
    {
        return Area::all();
    }

    protected function getDivisions()
    {
        if ($this->area) {
            return Divisi::where('area_id', $this->area)->get();
        }

        return Divisi::all();
    }

    protected function getLeaderboardData()
    {
        $period = is_string($this->month)
            && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $this->month)
                ? $this->month
                : Date::now()->format('Y-m');
        $area = (string) ($this->area ?? '');
        $division = (string) ($this->division ?? '');
        $ttl = max(1, (int) config('kpi.cache_ttl_seconds.leaderboard', 60));

        return Cache::remember(
            "leaderboard:panel:{$period}:{$area}:{$division}",
            $ttl,
            fn (): array => $this->computeLeaderboardData($period),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function computeLeaderboardData(string $period): array
    {
        $periodStart = Date::createFromFormat('!Y-m', $period)->startOfMonth();
        $periodEnd = $periodStart->copy()->addMonth();

        $query = User::query()
            ->select(['id', 'nama_lengkap', 'divisi_id', 'area_id'])
            ->with([
                'divisi:id,name',
                'area:id,name',
                'kpi' => function ($query) use ($periodStart, $periodEnd) {
                    $query->select('id', 'user_id', 'percentage', 'date')
                        ->where('kpi_type_id', 3)
                        ->where('date', '>=', $periodStart)
                        ->where('date', '<', $periodEnd)
                        ->orderBy('date', 'DESC')
                        ->with(['kpi_detail' => function ($query) {
                            $query->select('id', 'kpi_id', 'value_result')
                                ->whereNotNull('value_result')
                                ->where('value_result', '>=', 0);
                        }]);
                },
                'attendance' => function ($query) use ($period) {
                    $query->select('user_id', 'late_less_30', 'late_more_30', 'sick_days', 'work_days', 'periode')
                        ->where('periode', $period);
                },
                'employeeReview' => function ($query) use ($period) {
                    $query->select('user_id', 'responsiveness', 'problem_solver', 'helpfulness', 'initiative', 'periode')
                        ->where('periode', $period);
                },
            ])
            ->withSum([
                'cutpoint as period_cutpoint' => fn ($query) => $query->where('periode', $period),
            ], 'point');

        if ($this->area) {
            $query->where('area_id', $this->area);
        }

        if ($this->division) {
            $query->where('divisi_id', $this->division);
        }

        $users = $query->get();
        $leaderboardData = [];

        foreach ($users as $user) {
            $kpiScore = $this->calculateKPIScore($user);
            $attendanceScore = $this->calculateAttendanceScore($user);
            $activityScore = $this->calculateActivityScore($user);

            $totalScore = ($kpiScore + $attendanceScore + $activityScore);

            $cutpointValue = (int) ($user->period_cutpoint ?? 0);
            $totalScore = max(0, $totalScore - $cutpointValue);

            $leaderboardData[] = [
                'user' => $user,
                'kpiScore' => $kpiScore,
                'attendanceScore' => $attendanceScore,
                'activityScore' => $activityScore,
                'totalScore' => $totalScore,
                'cutpoint' => $cutpointValue,
            ];
        }

        return collect($leaderboardData)->sortByDesc('totalScore')->values()->all();
    }

    protected function calculateKPIScore($user)
    {
        $kpiScore = 0;

        foreach ($user->kpi as $kpi) {
            $result = KpiScoringService::calculateKpiScore($kpi);
            $kpiScore += $result['score'] * 100;
        }

        return KpiScoringService::calculateFinalKpiScore($kpiScore);
    }

    protected function calculateAttendanceScore($user)
    {
        if (! $user->attendance) {
            return 0;
        }

        $attendance = $user->attendance;
        $lateLess30 = $attendance->late_less_30 ?? 0;
        $lateMore30 = $attendance->late_more_30 ?? 0;
        $sickDays = $attendance->sick_days ?? 0;
        $workDays = $attendance->work_days ?? 0;

        if ($workDays <= 0) {
            return 0;
        }

        $initialAttendanceAchv = ($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays * 100;
        $penalty = ($lateLess30 * 1) + ($lateMore30 * 3) + ($sickDays * 5);
        $finalAttendanceAchv = max(0, $initialAttendanceAchv - $penalty);

        return ($finalAttendanceAchv / 100) * 15;
    }

    protected function calculateActivityScore($user)
    {
        if (! $user->employeeReview) {
            return 0;
        }

        $review = $user->employeeReview;
        $responsiveness = $review->responsiveness ?? 0;
        $problemSolver = $review->problem_solver ?? 0;
        $helpfulness = $review->helpfulness ?? 0;
        $initiative = $review->initiative ?? 0;

        return ($responsiveness + $problemSolver + $helpfulness + $initiative) / 20 * 100 * 0.15;
    }
}
