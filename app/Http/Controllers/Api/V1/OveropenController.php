<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOveropenRequest;
use App\Http\Requests\Api\V1\UpdateOveropenRequest;
use App\Http\Resources\Api\V1\OveropenResource;
use App\Models\Overopen;
use App\Services\ApprovalScopeService;
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
        $actor = $request->user();

        if ($actor?->role?->name !== 'ADMIN') {
            $visibleUserIds = array_merge(
                [(int) ($actor?->id ?? 0)],
                ApprovalScopeService::getManagedUserIdsOneLevelDown((int) ($actor?->id ?? 0)),
            );
            $query->whereIn('user_id', array_values(array_unique($visibleUserIds)));
        }

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

        if (! $overopen) {
            return response()->json([
                'success' => false,
                'message' => 'Data overopen tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', $overopen);

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
        $this->authorize('create', Overopen::class);

        $validated = $request->validated();
        $currentUser = auth()->user();

        $this->authorize('view', new Overopen(['user_id' => (int) $validated['user_id']]));
        $validated['atasan'] = $currentUser?->role?->name === 'ADMIN'
            ? ($validated['atasan'] ?? $currentUser?->id)
            : $currentUser?->id;

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

        if (! $overopen) {
            return response()->json([
                'success' => false,
                'message' => 'Data overopen tidak ditemukan.',
            ], 404);
        }

        $this->authorize('update', $overopen);

        $validated = $request->validated();
        unset($validated['user_id']);
        if (auth()->user()?->role?->name !== 'ADMIN') {
            $validated['atasan'] = auth()->id();
        }
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

        if (! $overopen) {
            return response()->json([
                'success' => false,
                'message' => 'Data overopen tidak ditemukan.',
            ], 404);
        }

        $this->authorize('delete', $overopen);

        $overopen->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data overopen berhasil dihapus.',
        ]);
    }
}
