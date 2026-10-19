<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreRequestRequest;
use App\Http\Requests\Api\V1\UpdateRequestRequest;
use App\Http\Resources\Api\V1\RequestResource;
use App\Models\Request as TodoRequest;
use App\Models\User;
use App\Services\DataAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * @tags Pengajuan & Approval Tugas (Requests & Approvals)
 */
class RequestApprovalController extends Controller
{
    /**
     * Admin per badan usaha boleh meninjau/memodifikasi pengajuan bila pengaju terlihat olehnya
     * (badan usaha yang diizinkan, tim approval, atau dirinya sendiri).
     */
    /**
     * Admin badan usaha boleh meninjau/mengubah pengajuan karyawan dalam cakupannya,
     * kecuali pengajuan akun ADMIN lain (hanya super admin).
     */
    private function areaScopedAdminCanWrite(User $actor, TodoRequest $todoRequest): bool
    {
        return DataAccessService::isAreaScopedAdmin($actor)
            && DataAccessService::canWriteForUser($actor, $todoRequest->user_id);
    }

    private function assertCanReview(TodoRequest $todoRequest): void
    {
        $actor = auth()->user();

        abort_if($actor === null, 401);

        if (DataAccessService::hasGlobalAccess($actor) || $this->areaScopedAdminCanWrite($actor, $todoRequest)) {
            return;
        }

        abort_if(
            (int) $todoRequest->approval_id !== (int) $actor->id,
            403,
            'Anda tidak memiliki akses untuk menyetujui pengajuan ini.',
        );
    }

    private function assertCanModify(TodoRequest $todoRequest): void
    {
        $actor = auth()->user();

        abort_if($actor === null, 401);

        if (DataAccessService::hasGlobalAccess($actor) || $this->areaScopedAdminCanWrite($actor, $todoRequest)) {
            return;
        }

        if ((int) $todoRequest->user_id === (int) $actor->id) {
            return;
        }

        abort(403, 'Anda tidak memiliki akses untuk memodifikasi pengajuan ini.');
    }

    /**
     * Pengajuan yang terlihat oleh actor:
     * - super admin: semua;
     * - admin per badan usaha: pengaju yang terlihat (badan usaha, tim, diri sendiri) atau approver = dirinya;
     * - lainnya: milik sendiri atau approver = dirinya.
     */
    private function scopedRequestQuery()
    {
        $actor = auth()->user();

        $query = TodoRequest::with(['user', 'approveId', 'approvedBy']);

        if (! $actor instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if (DataAccessService::hasGlobalAccess($actor)) {
            return $query;
        }

        if (DataAccessService::isAreaScopedAdmin($actor)) {
            return $query->where(function ($q) use ($actor) {
                DataAccessService::scopeOwnedRecords($q, $actor);
                $q->orWhere('approval_id', $actor->id);
            });
        }

        return $query->where(function ($q) use ($actor) {
            $q->where('user_id', $actor->id)->orWhere('approval_id', $actor->id);
        });
    }

    /**
     * Get paginated list of task requests with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->scopedRequestQuery();

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
        $todoRequest = $this->scopedRequestQuery()->find($id);

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

        $validated['user_id'] = $currentUser?->id;
        $validated['jenistodo'] = strtolower($validated['jenistodo']);
        $validated['todo_replace'] = $validated['todo_replace'] ?? '';
        $validated['approval_id'] = $currentUser?->approval_id ?: $currentUser?->id;
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

        $this->assertCanModify($todoRequest);

        if ($todoRequest->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pengajuan dengan status PENDING yang dapat diubah.',
            ], 422);
        }

        $validated = $request->validated();
        unset($validated['approval_id']);
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

        $this->assertCanModify($todoRequest);

        if ($todoRequest->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Pengajuan yang sudah diproses tidak dapat dihapus.',
            ], 422);
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

        if (! $currentUser instanceof User) {
            $query->whereRaw('1 = 0');
        } elseif (DataAccessService::isAreaScopedAdmin($currentUser)) {
            // Admin per badan usaha: pengajuan dari karyawan yang terlihat olehnya, atau yang ditujukan kepadanya.
            $query->where(function ($q) use ($currentUser) {
                DataAccessService::scopeOwnedRecords($q, $currentUser);
                $q->orWhere('approval_id', $currentUser->id);
            });
        } elseif (! DataAccessService::hasGlobalAccess($currentUser)) {
            // Selain super admin: hanya pengajuan yang approval_id-nya mengarah ke user ini.
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

        $this->assertCanReview($todoRequest);

        if ($todoRequest->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pengajuan dengan status PENDING yang dapat disetujui.',
            ], 422);
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

        $this->assertCanReview($todoRequest);

        if ($todoRequest->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pengajuan dengan status PENDING yang dapat ditolak.',
            ], 422);
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
