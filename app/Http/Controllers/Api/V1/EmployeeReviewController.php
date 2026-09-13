<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEmployeeReviewRequest;
use App\Http\Requests\Api\V1\UpdateEmployeeReviewRequest;
use App\Http\Resources\Api\V1\EmployeeReviewResource;
use App\Http\Resources\Api\V1\EvaluationScoreResource;
use App\Models\Attendance;
use App\Models\EmployeeReview;
use App\Models\Kpi;
use App\Models\User;
use App\Services\ApprovalScopeService;
use App\Services\KpiScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * @tags Review & Evaluasi Karyawan (Review & Evaluation)
 */
class EmployeeReviewController extends Controller
{
    /**
     * Get paginated list of employee reviews with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmployeeReview::class);

        $query = EmployeeReview::with('user');
        $actor = $request->user();

        if ($actor?->role?->name !== 'ADMIN') {
            $query->whereIn(
                'user_id',
                ApprovalScopeService::getManagedUserIdsOneLevelDown((int) ($actor?->id ?? 0)),
            );
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($periode = $request->query('periode')) {
            $query->where('periode', 'like', "{$periode}%");
        }

        if ($search = $request->query('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $reviews = $query->orderByDesc('periode')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar review karyawan berhasil diambil.',
            'data' => EmployeeReviewResource::collection($reviews),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'last_page' => $reviews->lastPage(),
            ],
        ]);
    }

    /**
     * Get detail of an employee review.
     */
    public function show(int $id): JsonResponse
    {
        $review = EmployeeReview::with('user')->find($id);

        if (! $review) {
            return response()->json([
                'success' => false,
                'message' => 'Data review karyawan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', $review);

        return response()->json([
            'success' => true,
            'message' => 'Detail review karyawan berhasil diambil.',
            'data' => new EmployeeReviewResource($review),
        ]);
    }

    /**
     * Store a new employee review.
     */
    public function store(StoreEmployeeReviewRequest $request): JsonResponse
    {
        $this->authorize('create', EmployeeReview::class);

        $validated = $request->validated();
        $this->authorize('view', new EmployeeReview(['user_id' => (int) $validated['user_id']]));

        $existing = EmployeeReview::where('user_id', $validated['user_id'])
            ->where('periode', $validated['periode'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => "Data review untuk karyawan ini pada periode {$validated['periode']} sudah ada.",
                'data' => new EmployeeReviewResource($existing),
            ], 422);
        }

        $review = EmployeeReview::create($validated);
        $review->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Penilaian review karyawan berhasil disimpan.',
            'data' => new EmployeeReviewResource($review),
        ], 201);
    }

    /**
     * Update an employee review.
     */
    public function update(UpdateEmployeeReviewRequest $request, int $id): JsonResponse
    {
        $review = EmployeeReview::find($id);

        if (! $review) {
            return response()->json([
                'success' => false,
                'message' => 'Data review karyawan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('update', $review);

        $validated = $request->validated();
        $review->update($validated);
        $review->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Data review karyawan berhasil diperbarui.',
            'data' => new EmployeeReviewResource($review),
        ]);
    }

    /**
     * Delete an employee review.
     */
    public function destroy(int $id): JsonResponse
    {
        $review = EmployeeReview::find($id);

        if (! $review) {
            return response()->json([
                'success' => false,
                'message' => 'Data review karyawan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('delete', $review);

        $review->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data review karyawan berhasil dihapus.',
        ]);
    }

    /**
     * Get review history for a specific user.
     */
    public function userReviews(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', new EmployeeReview(['user_id' => $userId]));

        $reviews = EmployeeReview::where('user_id', $userId)
            ->orderByDesc('periode')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Riwayat review karyawan berhasil diambil.',
            'data' => EmployeeReviewResource::collection($reviews),
        ]);
    }

    /**
     * Get comprehensive 100% evaluation score for a user in a specific period (KPI 70% + Attendance 15% + Review 15%).
     */
    public function integratedScore(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', new EmployeeReview(['user_id' => $userId]));

        $periode = $request->query('periode', Date::now()->format('Y-m'));
        $parts = explode('-', $periode);
        $year = (int) ($parts[0] ?? date('Y'));
        $month = isset($parts[1]) ? (int) $parts[1] : null;

        // 1. KPI Calculation (Max 70%)
        $kpiQuery = Kpi::where('user_id', $userId)->whereYear('date', $year)->with('kpi_detail.kpi_description');
        if ($month !== null) {
            $kpiQuery->whereMonth('date', $month);
        }
        $kpis = $kpiQuery->get();

        $rawKpiScore = 0;
        foreach ($kpis as $kpi) {
            $kpiScoreData = KpiScoringService::calculateKpiScore($kpi);
            $rawKpiScore += $kpiScoreData['score'];
        }
        $kpiScore70 = KpiScoringService::calculateFinalKpiScore($rawKpiScore);

        // 2. Attendance Calculation (Max 15%)
        $attendance = Attendance::where('user_id', $userId)
            ->where('periode', 'like', "{$periode}%")
            ->first();

        $attendanceScore15 = 0;
        $attendanceAchvPct = 0;
        $workDays = 0;
        if ($attendance && $attendance->work_days > 0) {
            $workDays = (int) $attendance->work_days;
            $lateLess30 = (int) ($attendance->late_less_30 ?? 0);
            $lateMore30 = (int) ($attendance->late_more_30 ?? 0);
            $sickDays = (int) ($attendance->sick_days ?? 0);

            $initialAchv = max(0, ($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays * 100);
            $penalty = ($lateLess30 * 1) + ($lateMore30 * 3) + ($sickDays * 5);
            $attendanceAchvPct = max(0, $initialAchv - $penalty);
            $attendanceScore15 = ($attendanceAchvPct / 100) * 15;
        }

        // 3. Employee Review Calculation (Max 15%)
        $review = EmployeeReview::where('user_id', $userId)
            ->where('periode', 'like', "{$periode}%")
            ->first();

        $reviewScore15 = 0;
        $reviewTotalPoints = 0;
        $reviewRatingPct = 0;
        if ($review) {
            $reviewTotalPoints = (int) $review->responsiveness + (int) $review->problem_solver + (int) $review->helpfulness + (int) $review->initiative;
            $reviewRatingPct = ($reviewTotalPoints / 20) * 100;
            $reviewScore15 = ($reviewTotalPoints / 20) * 15;
        }

        // 4. Combined Total Score (Max 100%)
        $totalScore = $kpiScore70 + $attendanceScore15 + $reviewScore15;

        // 5. Letter Grade Determination
        if ($totalScore >= 90) {
            $grade = 'A (Sangat Memuaskan / Outstanding)';
        } elseif ($totalScore >= 80) {
            $grade = 'B (Baik / Good)';
        } elseif ($totalScore >= 70) {
            $grade = 'C (Cukup / Satisfactory)';
        } elseif ($totalScore >= 60) {
            $grade = 'D (Kurang / Needs Improvement)';
        } else {
            $grade = 'E (Sangat Kurang / Poor)';
        }

        $evaluationData = [
            'user' => $user,
            'periode' => $periode,
            'kpi' => [
                'count' => $kpis->count(),
                'raw_score' => $rawKpiScore,
                'score_70pct' => $kpiScore70,
            ],
            'attendance' => [
                'has_data' => $attendance !== null,
                'work_days' => $workDays,
                'achievement_pct' => $attendanceAchvPct,
                'score_15pct' => $attendanceScore15,
            ],
            'review' => [
                'has_data' => $review !== null,
                'total_points' => $reviewTotalPoints,
                'rating_percentage' => $reviewRatingPct,
                'score_15pct' => $reviewScore15,
            ],
            'total_score' => $totalScore,
            'grade' => $grade,
        ];

        return response()->json([
            'success' => true,
            'message' => 'Evaluasi total performa karyawan berhasil dihitung.',
            'data' => new EvaluationScoreResource($evaluationData),
        ]);
    }
}
