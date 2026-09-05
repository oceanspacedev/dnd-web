<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\LeaderboardExportController;
use App\Http\Resources\Api\V1\DashboardStatsResource;
use App\Http\Resources\Api\V1\KpiChecklistResource;
use App\Http\Resources\Api\V1\LeaderboardResource;
use App\Models\Daily;
use App\Models\Kpi;
use App\Models\Request as TodoRequest;
use App\Models\User;
use App\Services\KpiScoringService;
use App\Services\LeaderboardScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Date;

/**
 * @tags Leaderboard, Analitik & Dashboard (Analytics & Dashboard)
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly LeaderboardScoreService $leaderboardScores) {}

    /**
     * Get paginated leaderboard rankings.
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $periode = $this->validatedPeriod($request);
        $areaId = $request->query('area_id') ? (int) $request->query('area_id') : null;
        $divisiId = $request->query('divisi_id') ? (int) $request->query('divisi_id') : null;
        $search = $request->query('search');

        $scores = $this->leaderboardScores->rankedScores($periode, $areaId, $divisiId, $search);

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $page = max(1, (int) $request->query('page', 1));

        $offset = ($page - 1) * $perPage;
        $itemsForCurrentPage = $this->leaderboardScores->hydrateUsers(array_slice($scores, $offset, $perPage));

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
        if (! $request->filled('month') && $request->filled('periode')) {
            $request->merge(['month' => $request->input('periode')]);
        }

        $exporter = new LeaderboardExportController;

        return $exporter->export($request);
    }

    /**
     * Get company-wide performance dashboard summary.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $periode = $this->validatedPeriod($request);
        $scores = $this->leaderboardScores->rankedScores($periode);

        $totalEmployees = count($scores);
        $avgKpi = $totalEmployees > 0 ? array_sum(array_column($scores, 'kpi_score_70pct')) / $totalEmployees : 0;
        $avgAtt = $totalEmployees > 0 ? array_sum(array_column($scores, 'attendance_score_15pct')) / $totalEmployees : 0;
        $avgRev = $totalEmployees > 0 ? array_sum(array_column($scores, 'review_score_15pct')) / $totalEmployees : 0;
        $avgTotal = $totalEmployees > 0 ? array_sum(array_column($scores, 'total_score')) / $totalEmployees : 0;

        $top5 = $this->leaderboardScores->hydrateUsers(array_slice($scores, 0, 5));
        $bottom5 = $this->leaderboardScores->hydrateUsers(array_slice(array_reverse($scores), 0, 5));

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
        $todayStart = Date::now()->startOfDay();
        $todayEnd = $todayStart->copy()->addDay();
        $today = $todayStart->toDateString();
        $dailyStats = Daily::query()
            ->where('date', '>=', $todayStart)
            ->where('date', '<', $todayEnd)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) as completed')
            ->first();
        $dailyTotal = (int) ($dailyStats->total ?? 0);
        $dailyCompleted = (int) ($dailyStats->completed ?? 0);
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
        $periode = $this->validatedPeriod($request);
        $scores = $this->leaderboardScores->rankedScores($periode);
        $divisiStats = $this->leaderboardScores->groupAverages($scores, 'divisi');
        $areaStats = $this->leaderboardScores->groupAverages($scores, 'area');

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

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', new Kpi(['user_id' => $userId]));

        $periode = $this->validatedPeriod($request);
        $periodDate = Date::createFromFormat('!Y-m', $periode);
        $year = $periodDate->year;
        $month = $periodDate->month;

        // Check checklist lock days
        $lockDays = max(0, (int) config('kpi.checklist_lock_days', 5));
        $kpiMonth = Date::create($year, $month, 1);
        $deadline = (clone $kpiMonth)->endOfMonth()->addDays($lockDays)->endOfDay();
        $isLocked = Date::now()->greaterThan($deadline);

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

            if (! isset($groupedByCategory[$catName])) {
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

    private function validatedPeriod(Request $request): string
    {
        $validated = $request->validate([
            'periode' => ['sometimes', 'string', 'date_format:Y-m'],
        ]);

        return $validated['periode'] ?? Date::now()->format('Y-m');
    }
}
