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
use App\Policies\EmployeeReviewPolicy;
use App\Services\KpiScoringService;
use App\Services\LeaderboardScoreService;
use App\Services\PerformanceScoreCalculator;
use App\Services\ScoringRuleService;
use App\Support\Periode;
use Illuminate\Database\Eloquent\Builder;
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

        // Super admin: semua data; admin per badan usaha: badan usaha yang diizinkan, tim approval,
        // dan dirinya sendiri; atasan: bawahan approval.
        EmployeeReviewPolicy::scopeVisible($query, $request->user());

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

        // Satu id yang sama untuk cek akses, cek duplikat, dan simpan. Target dicek dengan hak tulis
        // (admin per badan usaha tidak boleh menilai akun ADMIN, termasuk dirinya sendiri).
        $userId = (int) $validated['user_id'];
        $validated['user_id'] = $userId;
        $this->authorize('update', new EmployeeReview(['user_id' => $userId]));

        $existing = EmployeeReview::where('user_id', $userId)
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
     * Get comprehensive 100% evaluation score for a user in a specific period (KPI + Attendance + Review).
     *
     * Bobot (default 70/15/15), poin maksimal penilaian, dan aturan kehadiran mengikuti versi aturan penilaian
     * periode yang diminta; kunci respons *_70pct / *_15pct dipertahankan untuk klien lama.
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
        // Periode bulanan yang terbaca ('Y-m', 'Y-n', 'Y-m-d', ...) dalam bentuk 'Y-m'; null mis. bila hanya tahun.
        $monthPeriode = Periode::normalize($periode);
        $parts = explode('-', $monthPeriode ?? $periode);
        $year = (int) ($parts[0] ?? date('Y'));
        $month = isset($parts[1]) ? (int) $parts[1] : null;

        // 1. Data periode. Periode bulanan dicocokkan persis dengan bentuk 'Y-m'-nya, seperti leaderboard (prefix
        //    LIKE '2026-1%' akan memilih baris Oktober-Desember, masing-masing dengan aturannya sendiri, untuk
        //    permintaan Januari). Selain itu (mis. hanya tahun) tetap LIKE prefix seperti sebelumnya.
        $kpiQuery = Kpi::where('user_id', $userId)->whereYear('date', $year)->with('kpi_detail.kpi_description');
        if ($month !== null) {
            $kpiQuery->whereMonth('date', $month);
        }
        $kpis = $kpiQuery->get();

        $matchPeriode = fn (Builder $query): Builder => $monthPeriode !== null
            ? $query->where('periode', $monthPeriode)
            : $query->where('periode', 'like', "{$periode}%");

        $attendance = $matchPeriode(Attendance::where('user_id', $userId))->first();

        $review = $matchPeriode(EmployeeReview::where('user_id', $userId))->first();

        // 2. Versi aturan periode yang diminta (dinormalisasi). Periode yang bukan 'Y-m' (mis. hanya tahun) memakai
        //    periode data terakhir, bukan tanggal hari ini: KPI dulu (agar bobot KPI tidak bergantung pada ada/tidaknya
        //    baris kehadiran), lalu kehadiran/penilaian; tanpa data => bulan berjalan. Tahun 'YYYY' menjepit hasilnya
        //    ke tahun itu. Kehadiran dan penilaian tetap memakai aturan periodenya sendiri (langkah 4 dan 5).
        $rulePeriode = $monthPeriode
            ?? LeaderboardScoreService::dataRulePeriode(
                [$kpis->pluck('date'), [$attendance?->periode, $review?->periode]],
                is_string($periode) ? $periode : null,
            );
        $rule = ScoringRuleService::forPeriode($rulePeriode);

        // 3. KPI (maks = bobot KPI aturan)
        $rawKpiScore = 0;
        foreach ($kpis as $kpi) {
            $kpiScoreData = KpiScoringService::calculateKpiScore($kpi);
            $rawKpiScore += $kpiScoreData['score'];
        }
        $kpiScore70 = KpiScoringService::calculateFinalKpiScore($rawKpiScore, $rule);

        // 4. Kehadiran (maks = bobot kehadiran aturan); baris kehadiran memakai aturan periodenya sendiri.
        $attendanceScore15 = 0;
        $attendanceAchvPct = 0;
        $workDays = 0;
        $attendancePenalty = 0;
        if ($attendance !== null) {
            $attendanceResult = PerformanceScoreCalculator::attendanceFor(
                $attendance,
                ScoringRuleService::forPeriode(Periode::normalize($attendance->periode) ?? $rulePeriode),
            );

            if ($attendanceResult->hasWorkDays) {
                $workDays = $attendance->scoringWorkDays();
                $attendanceAchvPct = $attendanceResult->finalPct;
                $attendanceScore15 = $attendanceResult->score;
                $attendancePenalty = $attendanceResult->penaltyPointsNumber();
            }
        }

        // 5. Penilaian (maks = bobot penilaian aturan, poin maksimal dari aturan)
        $reviewScore15 = 0;
        $reviewTotalPoints = 0;
        $reviewRatingPct = 0;
        $reviewMaxPoints = $rule->reviewMaxPoints;
        if ($review) {
            $reviewRule = ScoringRuleService::forPeriode(Periode::normalize($review->periode) ?? $rulePeriode);
            $reviewTotalPoints = (int) $review->responsiveness + (int) $review->problem_solver + (int) $review->helpfulness + (int) $review->initiative;
            $reviewRatingPct = PerformanceScoreCalculator::reviewPercentage($reviewTotalPoints, $reviewRule);
            $reviewScore15 = PerformanceScoreCalculator::review($reviewTotalPoints, $reviewRule);
            $reviewMaxPoints = $reviewRule->reviewMaxPoints;
        }

        // 6. Combined Total Score (Max 100%)
        $totalScore = $kpiScore70 + $attendanceScore15 + $reviewScore15;

        // 7. Letter Grade Determination
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
                'penalty_points' => $attendancePenalty,
            ],
            'review' => [
                'has_data' => $review !== null,
                'total_points' => $reviewTotalPoints,
                'max_points' => $reviewMaxPoints,
                'rating_percentage' => $reviewRatingPct,
                'score_15pct' => $reviewScore15,
            ],
            'total_score' => $totalScore,
            'grade' => $grade,
            'scoring_rule' => $rule,
        ];

        return response()->json([
            'success' => true,
            'message' => 'Evaluasi total performa karyawan berhasil dihitung.',
            'data' => new EvaluationScoreResource($evaluationData),
        ]);
    }
}
