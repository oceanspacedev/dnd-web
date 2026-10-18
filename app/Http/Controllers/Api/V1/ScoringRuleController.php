<?php

namespace App\Http\Controllers\Api\V1;

use App\Filament\Resources\ScoringRules\ScoringRulePresenter;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttendanceComponentResource;
use App\Http\Resources\Api\V1\ScoringRuleResource;
use App\Models\AttendanceComponent;
use App\Models\ScoringRule;
use App\Services\ScoringRuleService;
use App\Support\Periode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aturan penilaian kinerja berversi dan master komponen kehadiran (hanya baca).
 *
 * Terbuka untuk semua user yang login seperti master data lain; pengelolaannya hanya lewat panel
 * oleh super admin (Settings > Aturan Penilaian / Komponen Kehadiran).
 *
 * @tags Master Data
 */
class ScoringRuleController extends Controller
{
    /**
     * Get list of scoring rule versions (newest first).
     */
    public function index(): JsonResponse
    {
        $rules = ScoringRule::query()->orderByDesc('effective_from')->get();
        $current = ScoringRuleService::forPeriode(Periode::current());

        return response()->json([
            'success' => true,
            'message' => 'Data aturan penilaian berhasil diambil.',
            'data' => ScoringRuleResource::collection($rules),
            'meta' => [
                'total' => $rules->count(),
                'current_periode' => Periode::current(),
                'current_rule_id' => $current->id,
            ],
        ]);
    }

    /**
     * Resolve the scoring rule that applies to a periode (Y-m, default bulan berjalan).
     */
    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'periode' => ['sometimes', 'nullable', 'string', 'date_format:Y-m'],
        ]);

        $periode = $validated['periode'] ?? Periode::current();
        $rule = ScoringRuleService::forPeriode($periode);

        return response()->json([
            'success' => true,
            'message' => 'Aturan penilaian untuk periode '.ScoringRulePresenter::periodeLabel($periode).' berhasil diambil.',
            'data' => new ScoringRuleResource($rule),
            'meta' => [
                'periode' => $periode,
            ],
        ]);
    }

    /**
     * Get list of attendance components (optionally ?active=1 / ?active=0).
     */
    public function components(Request $request): JsonResponse
    {
        $request->validate([
            'active' => ['sometimes', 'boolean'],
        ]);

        $components = AttendanceComponent::query()
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Data komponen kehadiran berhasil diambil.',
            'data' => AttendanceComponentResource::collection($components),
            'meta' => [
                'total' => $components->count(),
            ],
        ]);
    }
}
