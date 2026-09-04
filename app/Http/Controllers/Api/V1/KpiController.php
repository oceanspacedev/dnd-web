<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreKpiDetailRequest;
use App\Http\Requests\Api\V1\StoreKpiRequest;
use App\Http\Requests\Api\V1\UpdateKpiDetailRequest;
use App\Http\Requests\Api\V1\UpdateKpiRequest;
use App\Http\Resources\Api\V1\KpiDetailResource;
use App\Http\Resources\Api\V1\KpiResource;
use App\Models\Kpi;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\KpiDetail;
use App\Models\KpiType;
use App\Models\User;
use App\Services\KpiScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags KPIs & Scoring
 */
class KpiController extends Controller
{
    /**
     * Get paginated list of KPIs with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Kpi::with(['user', 'kpi_category', 'kpi_type', 'kpi_detail.kpi_description']);

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($year = $request->query('year')) {
            $query->whereYear('date', $year);
        }

        if ($month = $request->query('month')) {
            $query->whereMonth('date', $month);
        }

        if ($categoryId = $request->query('kpi_category_id')) {
            $query->where('kpi_category_id', $categoryId);
        }

        if ($typeId = $request->query('kpi_type_id')) {
            $query->where('kpi_type_id', $typeId);
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $kpis = $query->orderByDesc('date')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar KPI berhasil diambil.',
            'data' => KpiResource::collection($kpis),
            'meta' => [
                'current_page' => $kpis->currentPage(),
                'per_page' => $kpis->perPage(),
                'total' => $kpis->total(),
                'last_page' => $kpis->lastPage(),
            ],
        ]);
    }

    /**
     * Get detailed information of a KPI along with score calculation.
     */
    public function show(int $id): JsonResponse
    {
        $kpi = Kpi::with(['user', 'kpi_category', 'kpi_type', 'kpi_detail.kpi_description'])->find($id);

        if (! $kpi) {
            return response()->json([
                'success' => false,
                'message' => 'KPI tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail KPI berhasil diambil.',
            'data' => new KpiResource($kpi),
        ]);
    }

    /**
     * Create a new KPI along with optional initial detail rows.
     */
    public function store(StoreKpiRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $kpi = Kpi::create([
            'user_id' => $validated['user_id'],
            'kpi_category_id' => $validated['kpi_category_id'],
            'kpi_type_id' => $validated['kpi_type_id'],
            'date' => $validated['date'],
            'percentage' => $validated['percentage'],
        ]);

        // Create detail items if provided
        if (! empty($validated['details']) && is_array($validated['details'])) {
            foreach ($validated['details'] as $detailData) {
                $description = KpiDescription::find($detailData['kpi_description_id']);
                $isNegative = (bool) ($description?->is_negative ?? false);

                $plan = isset($detailData['value_plan']) ? (float) $detailData['value_plan'] : null;
                $actual = isset($detailData['value_actual']) ? (float) $detailData['value_actual'] : null;
                $result = KpiScoringService::calculateResultValue($plan, $actual, $isNegative);

                KpiDetail::create([
                    'kpi_id' => $kpi->id,
                    'kpi_description_id' => $detailData['kpi_description_id'],
                    'count_type' => $detailData['count_type'] ?? null,
                    'value_plan' => $plan,
                    'value_actual' => $actual,
                    'value_result' => $result,
                    'subtasks' => $detailData['subtasks'] ?? null,
                    'is_extra_task' => $detailData['is_extra_task'] ?? false,
                    'start' => $detailData['start'] ?? null,
                    'end' => $detailData['end'] ?? null,
                ]);
            }
        }

        $kpi->load(['user', 'kpi_category', 'kpi_type', 'kpi_detail.kpi_description']);

        return response()->json([
            'success' => true,
            'message' => 'KPI berhasil dibuat.',
            'data' => new KpiResource($kpi),
        ], 201);
    }

    /**
     * Update a KPI.
     */
    public function update(UpdateKpiRequest $request, int $id): JsonResponse
    {
        $kpi = Kpi::find($id);

        if (! $kpi) {
            return response()->json([
                'success' => false,
                'message' => 'KPI tidak ditemukan.',
            ], 404);
        }

        $kpi->update($request->validated());
        $kpi->load(['user', 'kpi_category', 'kpi_type', 'kpi_detail.kpi_description']);

        return response()->json([
            'success' => true,
            'message' => 'KPI berhasil diperbarui.',
            'data' => new KpiResource($kpi),
        ]);
    }

    /**
     * Delete a KPI.
     */
    public function destroy(int $id): JsonResponse
    {
        $kpi = Kpi::find($id);

        if (! $kpi) {
            return response()->json([
                'success' => false,
                'message' => 'KPI tidak ditemukan.',
            ], 404);
        }

        $kpi->kpi_detail()->delete();
        $kpi->delete();

        return response()->json([
            'success' => true,
            'message' => 'KPI berhasil dihapus.',
        ]);
    }

    // ==========================================
    // KPI DETAIL ENDPOINTS
    // ==========================================

    /**
     * Add a detail row to a KPI.
     */
    public function storeDetail(StoreKpiDetailRequest $request, int $kpiId): JsonResponse
    {
        $kpi = Kpi::find($kpiId);

        if (! $kpi) {
            return response()->json([
                'success' => false,
                'message' => 'KPI tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validated();
        $description = KpiDescription::find($validated['kpi_description_id']);
        $isNegative = (bool) ($description?->is_negative ?? false);

        $plan = (float) $validated['value_plan'];
        $actual = isset($validated['value_actual']) ? (float) $validated['value_actual'] : null;
        $result = KpiScoringService::calculateResultValue($plan, $actual, $isNegative);

        $detail = KpiDetail::create([
            'kpi_id' => $kpi->id,
            'kpi_description_id' => $validated['kpi_description_id'],
            'count_type' => $validated['count_type'] ?? null,
            'value_plan' => $plan,
            'value_actual' => $actual,
            'value_result' => $result,
            'subtasks' => $validated['subtasks'] ?? null,
            'is_extra_task' => $validated['is_extra_task'] ?? false,
            'start' => $validated['start'] ?? null,
            'end' => $validated['end'] ?? null,
        ]);

        $detail->load('kpi_description');

        return response()->json([
            'success' => true,
            'message' => 'Indikator detail KPI berhasil ditambahkan.',
            'data' => new KpiDetailResource($detail),
        ], 201);
    }

    /**
     * Update a KPI detail row (e.g. submit actual value).
     */
    public function updateDetail(UpdateKpiDetailRequest $request, int $id): JsonResponse
    {
        $detail = KpiDetail::with('kpi_description')->find($id);

        if (! $detail) {
            return response()->json([
                'success' => false,
                'message' => 'Detail KPI tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validated();

        $descId = $validated['kpi_description_id'] ?? $detail->kpi_description_id;
        $description = KpiDescription::find($descId);
        $isNegative = (bool) ($description?->is_negative ?? false);

        $plan = isset($validated['value_plan']) ? (float) $validated['value_plan'] : (float) $detail->value_plan;
        $actual = array_key_exists('value_actual', $validated)
            ? ($validated['value_actual'] !== null ? (float) $validated['value_actual'] : null)
            : ($detail->value_actual !== null ? (float) $detail->value_actual : null);

        $result = KpiScoringService::calculateResultValue($plan, $actual, $isNegative);

        $detail->update(array_merge($validated, [
            'value_plan' => $plan,
            'value_actual' => $actual,
            'value_result' => $result,
        ]));

        $detail->load('kpi_description');

        return response()->json([
            'success' => true,
            'message' => 'Detail KPI berhasil diperbarui.',
            'data' => new KpiDetailResource($detail),
        ]);
    }

    /**
     * Delete a KPI detail row.
     */
    public function destroyDetail(int $id): JsonResponse
    {
        $detail = KpiDetail::find($id);

        if (! $detail) {
            return response()->json([
                'success' => false,
                'message' => 'Detail KPI tidak ditemukan.',
            ], 404);
        }

        $detail->delete();

        return response()->json([
            'success' => true,
            'message' => 'Detail KPI berhasil dihapus.',
        ]);
    }

    // ==========================================
    // MASTER REFERENCE ENDPOINTS
    // ==========================================

    /**
     * Get list of KPI categories.
     */
    public function categories(): JsonResponse
    {
        $categories = KpiCategory::orderBy('name')->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Kategori KPI berhasil diambil.',
            'data' => $categories,
        ]);
    }

    /**
     * Get list of KPI types.
     */
    public function types(): JsonResponse
    {
        $types = KpiType::orderBy('name')->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Tipe KPI berhasil diambil.',
            'data' => $types,
        ]);
    }

    /**
     * Get list of KPI descriptions / indicator catalog.
     */
    public function descriptions(Request $request): JsonResponse
    {
        $query = KpiDescription::with('kpi_category')->orderBy('description');

        if ($categoryId = $request->query('category_id')) {
            $query->where('kpi_category_id', $categoryId);
        }

        $descriptions = $query->get()->map(function ($d) {
            return [
                'id' => $d->id,
                'description' => $d->description,
                'kpi_category' => $d->kpi_category ? [
                    'id' => $d->kpi_category->id,
                    'name' => $d->kpi_category->name,
                ] : null,
                'is_negative' => (bool) $d->is_negative,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Daftar deskripsi indikator KPI berhasil diambil.',
            'data' => $descriptions,
        ]);
    }

    // ==========================================
    // KPI PERFORMANCE SUMMARY
    // ==========================================

    /**
     * Get cumulative KPI performance summary for a specific user.
     */
    public function userSummary(Request $request, int $userId): JsonResponse
    {
        $user = User::with(['role', 'position'])->find($userId);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $query = Kpi::with(['kpi_category', 'kpi_type', 'kpi_detail.kpi_description'])
            ->where('user_id', $userId);

        if ($year = $request->query('year')) {
            $query->whereYear('date', $year);
        }

        if ($month = $request->query('month')) {
            $query->whereMonth('date', $month);
        }

        $kpis = $query->get();

        $totalPercentage = (float) $kpis->sum('percentage');
        $rawScoreSum = 0;
        $kpiSummaries = [];

        foreach ($kpis as $kpi) {
            $calc = KpiScoringService::calculateKpiScore($kpi);
            $rawScoreSum += $calc['score'];

            $kpiSummaries[] = [
                'kpi_id' => $kpi->id,
                'category' => $kpi->kpi_category?->name,
                'type' => $kpi->kpi_type?->name,
                'date' => $kpi->date,
                'percentage' => (float) $kpi->percentage,
                'score' => round($calc['score'], 2),
                'actual_count' => round($calc['actualCount'], 2),
            ];
        }

        $finalScore = KpiScoringService::calculateFinalKpiScore($rawScoreSum);

        return response()->json([
            'success' => true,
            'message' => 'Ringkasan performa KPI berhasil diambil.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'nama_lengkap' => $user->nama_lengkap,
                    'role' => $user->role?->name,
                    'position' => $user->position?->name,
                ],
                'summary' => [
                    'total_kpis' => $kpis->count(),
                    'total_percentage' => $totalPercentage,
                    'raw_kpi_score' => round($rawScoreSum, 2),
                    'final_kpi_score_capped' => round($finalScore, 2), // capped at 70 (formula leaderboard)
                ],
                'kpi_items' => $kpiSummaries,
            ],
        ]);
    }
}
