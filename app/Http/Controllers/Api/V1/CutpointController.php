<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCutpointRequest;
use App\Http\Requests\Api\V1\UpdateCutpointRequest;
use App\Http\Resources\Api\V1\CutpointResource;
use App\Models\Cutpoint;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Pemotongan Poin Kedisiplinan (Cutpoints)
 */
class CutpointController extends Controller
{
    /**
     * Get paginated list of cutpoints with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Cutpoint::with('user');

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($periode = $request->query('periode')) {
            $query->where('periode', 'like', "{$periode}%");
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('keterangan', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('nama_lengkap', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $cutpoints = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar pemotongan poin berhasil diambil.',
            'data' => CutpointResource::collection($cutpoints),
            'meta' => [
                'current_page' => $cutpoints->currentPage(),
                'per_page' => $cutpoints->perPage(),
                'total' => $cutpoints->total(),
                'last_page' => $cutpoints->lastPage(),
            ],
        ]);
    }

    /**
     * Get detail of a cutpoint record.
     */
    public function show(int $id): JsonResponse
    {
        $cutpoint = Cutpoint::with('user')->find($id);

        if (! $cutpoint) {
            return response()->json([
                'success' => false,
                'message' => 'Data pemotongan poin tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail pemotongan poin berhasil diambil.',
            'data' => new CutpointResource($cutpoint),
        ]);
    }

    /**
     * Store a new cutpoint record.
     */
    public function store(StoreCutpointRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $cutpoint = Cutpoint::create($validated);
        $cutpoint->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Pemotongan poin berhasil dicatat.',
            'data' => new CutpointResource($cutpoint),
        ], 201);
    }

    /**
     * Update a cutpoint record.
     */
    public function update(UpdateCutpointRequest $request, int $id): JsonResponse
    {
        $cutpoint = Cutpoint::find($id);

        if (! $cutpoint) {
            return response()->json([
                'success' => false,
                'message' => 'Data pemotongan poin tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validated();
        $cutpoint->update($validated);
        $cutpoint->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Data pemotongan poin berhasil diperbarui.',
            'data' => new CutpointResource($cutpoint),
        ]);
    }

    /**
     * Delete a cutpoint record.
     */
    public function destroy(int $id): JsonResponse
    {
        $cutpoint = Cutpoint::find($id);

        if (! $cutpoint) {
            return response()->json([
                'success' => false,
                'message' => 'Data pemotongan poin tidak ditemukan.',
            ], 404);
        }

        $cutpoint->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data pemotongan poin berhasil dihapus.',
        ]);
    }

    /**
     * Get cutpoint history and total points summary for a specific user.
     */
    public function userCutpoints(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $query = Cutpoint::where('user_id', $userId);

        if ($periode = $request->query('periode')) {
            $query->where('periode', 'like', "{$periode}%");
        }

        $cutpoints = $query->orderByDesc('id')->get();
        $totalDeduction = (float) $cutpoints->sum('point');

        return response()->json([
            'success' => true,
            'message' => 'Riwayat pemotongan poin karyawan berhasil diambil.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'nama_lengkap' => $user->nama_lengkap,
                    'username' => $user->username,
                ],
                'total_points_deducted' => $totalDeduction,
                'records_count' => $cutpoints->count(),
                'records' => CutpointResource::collection($cutpoints),
            ],
        ]);
    }
}
