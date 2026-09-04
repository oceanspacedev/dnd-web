<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreRequestRequest;
use App\Http\Requests\Api\V1\UpdateRequestRequest;
use App\Http\Resources\Api\V1\RequestResource;
use App\Models\Request as TodoRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * @tags Pengajuan & Approval Tugas (Requests & Approvals)
 */
class RequestApprovalController extends Controller
{
    /**
     * Get paginated list of task requests with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = TodoRequest::with(['user', 'approveId', 'approvedBy']);

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper($status));
        }

        if ($jenis = $request->query('jenistodo')) {
            $query->where('jenistodo', strtolower($jenis));
        }

        if ($approvalId = $request->query('approval_id')) {
            $query->where('approval_id', $approvalId);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('todo_request', 'like', "%{$search}%")
                    ->orWhere('todo_replace', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $requests = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar pengajuan izin tugas berhasil diambil.',
            'data' => RequestResource::collection($requests),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'last_page' => $requests->lastPage(),
            ],
        ]);
    }

    /**
     * Get detail of a task request.
     */
    public function show(int $id): JsonResponse
    {
        $todoRequest = TodoRequest::with(['user', 'approveId', 'approvedBy'])->find($id);

        if (! $todoRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Data pengajuan izin tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail pengajuan izin berhasil diambil.',
            'data' => new RequestResource($todoRequest),
        ]);
    }

    /**
     * Store a new task request.
     */
    public function store(StoreRequestRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $currentUser = auth()->user();

        $validated['user_id'] = $validated['user_id'] ?? $currentUser?->id;
        $validated['jenistodo'] = strtolower($validated['jenistodo']);
        $validated['approval_id'] = $validated['approval_id'] ?? $currentUser?->approval_id;
        $validated['status'] = 'PENDING';

        $todoRequest = TodoRequest::create($validated);
        $todoRequest->load(['user', 'approveId', 'approvedBy']);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan izin tugas berhasil dibuat.',
            'data' => new RequestResource($todoRequest),
        ], 201);
    }

    /**
     * Update a pending task request.
     */
    public function update(UpdateRequestRequest $request, int $id): JsonResponse
    {
        $todoRequest = TodoRequest::find($id);

        if (! $todoRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Data pengajuan izin tidak ditemukan.',
            ], 404);
        }

        if ($todoRequest->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pengajuan dengan status PENDING yang dapat diubah.',
            ], 422);
        }

        $validated = $request->validated();
        if (isset($validated['jenistodo'])) {
            $validated['jenistodo'] = strtolower($validated['jenistodo']);
        }

        $todoRequest->update($validated);
        $todoRequest->load(['user', 'approveId', 'approvedBy']);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan izin tugas berhasil diperbarui.',
            'data' => new RequestResource($todoRequest),
        ]);
    }

    /**
     * Delete a task request.
     */
    public function destroy(int $id): JsonResponse
    {
        $todoRequest = TodoRequest::find($id);

        if (! $todoRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Data pengajuan izin tidak ditemukan.',
            ], 404);
        }

        $todoRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan izin tugas berhasil dihapus.',
        ]);
    }

    /**
     * Get list of requests pending approval for currently authenticated supervisor.
     */
    public function pendingApprovals(Request $request): JsonResponse
    {
        $currentUser = auth()->user();

        $query = TodoRequest::with(['user', 'approveId'])
            ->where('status', 'PENDING');

        // If not superadmin, filter by approval_id pointing to this user
        if ($currentUser && $currentUser->role?->name !== 'ADMIN') {
            $query->where('approval_id', $currentUser->id);
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $requests = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar pengajuan menunggu persetujuan berhasil diambil.',
            'data' => RequestResource::collection($requests),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'last_page' => $requests->lastPage(),
            ],
        ]);
    }

    /**
     * Approve a task request.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $todoRequest = TodoRequest::find($id);

        if (! $todoRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Data pengajuan izin tidak ditemukan.',
            ], 404);
        }

        $todoRequest->update([
            'status' => 'APPROVED',
            'approved_by' => auth()->id(),
            'approved_at' => Date::now(),
        ]);

        $todoRequest->load(['user', 'approveId', 'approvedBy']);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan izin berhasil disetujui.',
            'data' => new RequestResource($todoRequest),
        ]);
    }

    /**
     * Reject a task request.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $todoRequest = TodoRequest::find($id);

        if (! $todoRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Data pengajuan izin tidak ditemukan.',
            ], 404);
        }

        $todoRequest->update([
            'status' => 'REJECTED',
            'approved_by' => auth()->id(),
            'approved_at' => Date::now(),
        ]);

        $todoRequest->load(['user', 'approveId', 'approvedBy']);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan izin berhasil ditolak.',
            'data' => new RequestResource($todoRequest),
        ]);
    }
}
