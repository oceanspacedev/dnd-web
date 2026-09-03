<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\LeaderboardExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LeaderboardExportController;
use App\Http\Resources\Api\V1\DashboardStatsResource;
use App\Http\Resources\Api\V1\KpiChecklistResource;
use App\Http\Resources\Api\V1\LeaderboardResource;
use App\Models\Area;
use App\Models\Attendance;
use App\Models\Daily;
use App\Models\Divisi;
use App\Models\EmployeeReview;
use App\Models\Kpi;
use App\Models\Request as TodoRequest;
use App\Models\User;
use App\Services\KpiScoringService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @tags Leaderboard, Analitik & Dashboard (Analytics & Dashboard)
 */
class AnalyticsController extends Controller
{
    /**
     * Helper to compute evaluation score for all users in a given period.
     */
    private function computeScoresForPeriod(string $periode, ?int $areaId = null, ?int $divisiId = null, ?string $search = null): array
    {
        $parts = explode('-', $periode);
        $year = (int) ($parts[0] ?? date('Y'));
        $month = isset($parts[1]) ? (int) $parts[1] : (int) date('m');

        $userQuery = User::with(['role', 'divisi', 'area', 'position'])
            ->whereNull('deleted_at');

        if ($areaId) {
            $userQuery->where('area_id', $areaId);
        }

        if ($divisiId) {
            $userQuery->where('divisi_id', $divisiId);
        }

        if ($search) {
            $userQuery->where(function ($q) use ($search) {
                $q->where('nama_lengkap', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $users = $userQuery->get();

        // Eager load records for the period
        $userIds = $users->pluck('id')->all();

        $kpis = Kpi::whereIn('user_id', $userIds)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->with('kpi_detail.kpi_description')
            ->get()
            ->groupBy('user_id');

        $attendances = Attendance::whereIn('user_id', $userIds)
            ->where('periode', $periode)
            ->get()
            ->keyBy('user_id');

        $reviews = EmployeeReview::whereIn('user_id', $userIds)
            ->where('periode', $periode)
            ->get()
            ->keyBy('user_id');

        $leaderboard = [];

        foreach ($users as $user) {
            // 1. KPI (Max 70%)
            $userKpis = $kpis->get($user->id, collect());
            $rawKpiScore = 0;
            foreach ($userKpis as $kpi) {
                if ($kpi->kpi_detail) {
                    foreach ($kpi->kpi_detail as $detail) {
                        $rawKpiScore += (float) ($detail->value_result ?? 0);
                    }
                }
            }
            $kpiScore70 = KpiScoringService::calculateFinalKpiScore($rawKpiScore);

            // 2. Attendance (Max 15%)
            $att = $attendances->get($user->id);
            $attScore15 = 0;
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

            // 3. Employee Review (Max 15%)
            $rev = $reviews->get($user->id);
            $revScore15 = 0;
            if ($rev) {
                $totPoints = (int) ($rev->responsiveness ?? 0)
                    + (int) ($rev->problem_solver ?? 0)
                    + (int) ($rev->helpfulness ?? 0)
                    + (int) ($rev->initiative ?? 0);
                $revScore15 = ($totPoints / 20) * 15;
            }

            $totalScore = $kpiScore70 + $attScore15 + $revScore15;

            $grade = match (true) {
                $totalScore >= 85 => 'A (Istimewa / Outstanding)',
                $totalScore >= 75 => 'B (Baik / Good)',
                $totalScore >= 60 => 'C (Cukup / Satisfactory)',
                $totalScore >= 50 => 'D (Kurang / Needs Improvement)',
                default => 'E (Sangat Kurang / Poor)',
            };

            $leaderboard[] = [
                'user' => $user,
                'kpi_raw' => $rawKpiScore,
                'kpi_score_70pct' => $kpiScore70,
                'attendance_score_15pct' => $attScore15,
                'review_score_15pct' => $revScore15,
                'total_score' => $totalScore,
                'grade' => $grade,
            ];
        }

        // Sort by total_score descending
        usort($leaderboard, fn ($a, $b) => $b['total_score'] <=> $a['total_score']);

        // Assign ranks
        foreach ($leaderboard as $idx => &$item) {
            $item['rank'] = $idx + 1;
        }
        unset($item);

        return $leaderboard;
    }

    /**
     * Get paginated leaderboard rankings.
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $periode = $request->query('periode', Carbon::now()->format('Y-m'));
        $areaId = $request->query('area_id') ? (int) $request->query('area_id') : null;
        $divisiId = $request->query('divisi_id') ? (int) $request->query('divisi_id') : null;
        $search = $request->query('search');

        $scores = $this->computeScoresForPeriod($periode, $areaId, $divisiId, $search);

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $page = max(1, (int) $request->query('page', 1));

        $offset = ($page - 1) * $perPage;
        $itemsForCurrentPage = array_slice($scores, $offset, $perPage);

        $paginator = new LengthAwarePaginator(
            $itemsForCurrentPage,
            count($scores),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json([
            'success' => true,
            'message' => 'Peringkat leaderboard berhasil dihitung.',
            'periode' => $periode,
            'data' => LeaderboardResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Export leaderboard to Excel file.
     */
    public function exportLeaderboard(Request $request)
    {
        $exporter = new LeaderboardExportController();
        return $exporter->export($request);
    }

    /**
     * Get company-wide performance dashboard summary.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $periode = $request->query('periode', Carbon::now()->format('Y-m'));
        $scores = $this->computeScoresForPeriod($periode);

        $totalEmployees = count($scores);
        $avgKpi = $totalEmployees > 0 ? array_sum(array_column($scores, 'kpi_score_70pct')) / $totalEmployees : 0;
        $avgAtt = $totalEmployees > 0 ? array_sum(array_column($scores, 'attendance_score_15pct')) / $totalEmployees : 0;
        $avgRev = $totalEmployees > 0 ? array_sum(array_column($scores, 'review_score_15pct')) / $totalEmployees : 0;
        $avgTotal = $totalEmployees > 0 ? array_sum(array_column($scores, 'total_score')) / $totalEmployees : 0;

        // Top 5 & Bottom 5 performers
        $top5 = array_slice($scores, 0, 5);
        $bottom5 = array_slice(array_reverse($scores), 0, 5);

        $formatRankList = function ($list) {
            return array_map(fn ($item) => [
                'rank' => $item['rank'],
                'user_id' => $item['user']->id,
                'nama_lengkap' => $item['user']->nama_lengkap,
                'divisi' => $item['user']->divisi?->name,
                'total_score' => round($item['total_score'], 2),
                'grade' => $item['grade'],
            ], $list);
        };

        // Today's daily tasks
        $today = Carbon::now()->toDateString();
        $dailyToday = Daily::whereDate('date', $today)->get();
        $dailyTotal = $dailyToday->count();
        $dailyCompleted = $dailyToday->where('status', 'Completed')->count();
        $dailyPending = $dailyTotal - $dailyCompleted;
        $dailyRate = $dailyTotal > 0 ? ($dailyCompleted / $dailyTotal) * 100 : 0;

        // Pending approval requests
        $pendingRequests = TodoRequest::where('status', 'PENDING')->count();

        $stats = [
            'periode' => $periode,
            'today' => $today,
            'total_employees' => $totalEmployees,
            'avg_kpi_score' => $avgKpi,
            'avg_attendance_score' => $avgAtt,
            'avg_review_score' => $avgRev,
            'avg_total_score' => $avgTotal,
            'daily_total_today' => $dailyTotal,
            'daily_completed_today' => $dailyCompleted,
            'daily_pending_today' => $dailyPending,
            'daily_completion_rate' => $dailyRate,
            'pending_requests_count' => $pendingRequests,
            'top_performers' => $formatRankList($top5),
            'bottom_performers' => $formatRankList($bottom5),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Statistik dashboard performa perusahaan berhasil diambil.',
            'data' => new DashboardStatsResource($stats),
        ]);
    }

    /**
     * Get performance comparison stats per Division and per Area.
     */
    public function departmentStats(Request $request): JsonResponse
    {
        $periode = $request->query('periode', Carbon::now()->format('Y-m'));
        $scores = $this->computeScoresForPeriod($periode);

        // Group by Division
        $divisiGroups = [];
        $areaGroups = [];

        foreach ($scores as $s) {
            $divName = $s['user']->divisi?->name ?? 'Unassigned';
            $areaName = $s['user']->area?->name ?? 'Unassigned';

            $divisiGroups[$divName][] = $s['total_score'];
            $areaGroups[$areaName][] = $s['total_score'];
        }

        $divisiStats = [];
        foreach ($divisiGroups as $name => $vals) {
            $divisiStats[] = [
                'name' => $name,
                'employee_count' => count($vals),
                'average_score' => round(array_sum($vals) / count($vals), 2),
                'highest_score' => round(max($vals), 2),
                'lowest_score' => round(min($vals), 2),
            ];
        }
        usort($divisiStats, fn ($a, $b) => $b['average_score'] <=> $a['average_score']);

        $areaStats = [];
        foreach ($areaGroups as $name => $vals) {
            $areaStats[] = [
                'name' => $name,
                'employee_count' => count($vals),
                'average_score' => round(array_sum($vals) / count($vals), 2),
                'highest_score' => round(max($vals), 2),
                'lowest_score' => round(min($vals), 2),
            ];
        }
        usort($areaStats, fn ($a, $b) => $b['average_score'] <=> $a['average_score']);

        return response()->json([
            'success' => true,
            'message' => 'Statistik komparasi departemen & area berhasil diambil.',
            'periode' => $periode,
            'data' => [
                'by_division' => $divisiStats,
                'by_area' => $areaStats,
            ],
        ]);
    }

    /**
     * Get monthly KPI Checklist matrix for an employee.
     */
    public function kpiChecklist(Request $request): JsonResponse
    {
        $userId = $request->query('user_id') ? (int) $request->query('user_id') : auth()->id();
        $user = User::with(['divisi', 'area'])->find($userId);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $periode = $request->query('periode', Carbon::now()->format('Y-m'));
        $parts = explode('-', $periode);
        $year = (int) ($parts[0] ?? date('Y'));
        $month = isset($parts[1]) ? (int) $parts[1] : (int) date('m');

        // Check checklist lock days
        $lockDays = max(0, (int) config('kpi.checklist_lock_days', 5));
        $kpiMonth = Carbon::create($year, $month, 1);
        $deadline = (clone $kpiMonth)->endOfMonth()->addDays($lockDays)->endOfDay();
        $isLocked = Carbon::now()->greaterThan($deadline);

        $kpis = Kpi::with([
            'kpi_category',
            'kpi_detail.kpi_description',
            'kpi_detail.children.kpi_description',
        ])
            ->where('user_id', $userId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get();

        $groupedByCategory = [];
        $totalIndicators = 0;
        $completedIndicators = 0;
        $totalKpiScore = 0;

        foreach ($kpis as $kpi) {
            $catName = $kpi->kpi_category?->name ?? 'MAIN JOB';

            if (!isset($groupedByCategory[$catName])) {
                $groupedByCategory[$catName] = [
                    'category' => $catName,
                    'kpi_id' => $kpi->id,
                    'percentage_weight' => (float) ($kpi->percentage ?? 0),
                    'items' => [],
                ];
            }

            if ($kpi->kpi_detail) {
                foreach ($kpi->kpi_detail as $detail) {
                    $totalIndicators++;
                    $actual = (float) ($detail->value_actual ?? 0);
                    $plan = (float) ($detail->value_plan ?? 0);
                    $result = (float) ($detail->value_result ?? 0);
                    $totalKpiScore += $result;

                    $isDone = ($actual >= $plan && $plan > 0) || $result > 0;
                    if ($isDone) {
                        $completedIndicators++;
                    }

                    $groupedByCategory[$catName]['items'][] = [
                        'id' => $detail->id,
                        'description' => $detail->kpi_description?->description ?? 'Indikator KPI',
                        'count_type' => $detail->count_type ?? 'NON',
                        'weight' => (float) ($detail->weight ?? 0),
                        'value_plan' => $plan,
                        'value_actual' => $actual,
                        'value_result' => $result,
                        'status' => $isDone ? 'COMPLETED' : 'PENDING',
                    ];
                }
            }
        }

        $completionRate = $totalIndicators > 0 ? ($completedIndicators / $totalIndicators) * 100 : 0;
        $finalScore70 = KpiScoringService::calculateFinalKpiScore($totalKpiScore);

        $checklistData = [
            'user' => $user,
            'periode' => $periode,
            'is_locked' => $isLocked,
            'deadline' => $deadline->toIso8601String(),
            'total_indicators' => $totalIndicators,
            'completed_indicators' => $completedIndicators,
            'completion_rate_pct' => $completionRate,
            'total_kpi_score' => $totalKpiScore,
            'final_kpi_score_70pct' => $finalScore70,
            'categories' => array_values($groupedByCategory),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Matriks checklist KPI karyawan berhasil diambil.',
            'data' => new KpiChecklistResource($checklistData),
        ]);
    }
}
