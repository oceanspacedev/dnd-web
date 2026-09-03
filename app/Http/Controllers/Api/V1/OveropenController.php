<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOveropenRequest;
use App\Http\Requests\Api\V1\UpdateOveropenRequest;
use App\Http\Resources\Api\V1\OveropenResource;
use App\Models\Overopen;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Overopen Deadline (Overopens)
 */
class OveropenController extends Controller
{
    /**
     * Get paginated list of overopens with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Overopen::with(['user', 'leader']);

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($atasan = $request->query('atasan')) {
            $query->where('atasan', $atasan);
        }

        if ($week = $request->query('week')) {
            $query->where('week', $week);
        }

        if ($year = $request->query('year')) {
            $query->where('year', $year);
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $overopens = $query->orderByDesc('year')->orderByDesc('week')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar data overopen berhasil diambil.',
            'data' => OveropenResource::collection($overopens),
            'meta' => [
                'current_page' => $overopens->currentPage(),
                'per_page' => $overopens->perPage(),
                'total' => $overopens->total(),
                'last_page' => $overopens->lastPage(),
            ],
        ]);
    }

    /**
     * Get detail of an overopen record.
     */
    public function show(int $id): JsonResponse
    {
        $overopen = Overopen::with(['user', 'leader'])->find($id);

        if (!$overopen) {
            return response()->json([
                'success' => false,
                'message' => 'Data overopen tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail data overopen berhasil diambil.',
            'data' => new OveropenResource($overopen),
        ]);
    }

    /**
     * Store a new overopen record.
     */
    public function store(StoreOveropenRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $currentUser = auth()->user();

        $validated['atasan'] = $validated['atasan'] ?? $currentUser?->approval_id ?? $currentUser?->id;

        $overopen = Overopen::create($validated);
        $overopen->load(['user', 'leader']);

        return response()->json([
            'success' => true,
            'message' => 'Data overopen berhasil dicatat.',
            'data' => new OveropenResource($overopen),
        ], 201);
    }

    /**
     * Update an overopen record.
     */
    public function update(UpdateOveropenRequest $request, int $id): JsonResponse
    {
        $overopen = Overopen::find($id);

        if (!$overopen) {
            return response()->json([
                'success' => false,
                'message' => 'Data overopen tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validated();
        $overopen->update($validated);
        $overopen->load(['user', 'leader']);

        return response()->json([
            'success' => true,
            'message' => 'Data overopen berhasil diperbarui.',
            'data' => new OveropenResource($overopen),
        ]);
    }

    /**
     * Delete an overopen record.
     */
    public function destroy(int $id): JsonResponse
    {
        $overopen = Overopen::find($id);

        if (!$overopen) {
            return response()->json([
                'success' => false,
                'message' => 'Data overopen tidak ditemukan.',
            ], 404);
        }

        $overopen->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data overopen berhasil dihapus.',
        ]);
    }
}
