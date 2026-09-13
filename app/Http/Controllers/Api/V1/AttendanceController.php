<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAttendanceRequest;
use App\Http\Requests\Api\V1\UpdateAttendanceRequest;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Models\Attendance;
use App\Models\User;
use App\Services\ApprovalScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Presensi Karyawan (Attendance)
 */
class AttendanceController extends Controller
{
    /**
     * Get paginated list of attendances with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Attendance::class);

        $query = Attendance::with('user');
        $actor = $request->user();

        if (
            $actor?->role?->name !== 'ADMIN'
            && strtolower((string) ($actor?->username ?? '')) !== 'darkini'
        ) {
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

        if ($year = $request->query('year')) {
            $query->where('periode', 'like', "{$year}%");
        }

        if ($search = $request->query('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $attendances = $query->orderByDesc('periode')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar presensi karyawan berhasil diambil.',
            'data' => AttendanceResource::collection($attendances),
            'meta' => [
                'current_page' => $attendances->currentPage(),
                'per_page' => $attendances->perPage(),
                'total' => $attendances->total(),
                'last_page' => $attendances->lastPage(),
            ],
        ]);
    }

    /**
     * Get detail of an attendance record.
     */
    public function show(int $id): JsonResponse
    {
        $attendance = Attendance::with('user')->find($id);

        if (! $attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Data presensi tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', $attendance);

        return response()->json([
            'success' => true,
            'message' => 'Detail data presensi berhasil diambil.',
            'data' => new AttendanceResource($attendance),
        ]);
    }

    /**
     * Store a new attendance record.
     */
    public function store(StoreAttendanceRequest $request): JsonResponse
    {
        $this->authorize('create', Attendance::class);

        $validated = $request->validated();
        $this->authorize('view', new Attendance(['user_id' => (int) $validated['user_id']]));

        $existing = Attendance::where('user_id', $validated['user_id'])
            ->where('periode', $validated['periode'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => "Data presensi untuk karyawan ini pada periode {$validated['periode']} sudah ada.",
                'data' => new AttendanceResource($existing),
            ], 422);
        }

        $attendance = Attendance::create($validated);
        $attendance->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Data presensi berhasil dicatat.',
            'data' => new AttendanceResource($attendance),
        ], 201);
    }

    /**
     * Update an attendance record.
     */
    public function update(UpdateAttendanceRequest $request, int $id): JsonResponse
    {
        $attendance = Attendance::find($id);

        if (! $attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Data presensi tidak ditemukan.',
            ], 404);
        }

        $this->authorize('update', $attendance);

        $validated = $request->validated();
        $attendance->update($validated);
        $attendance->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Data presensi berhasil diperbarui.',
            'data' => new AttendanceResource($attendance),
        ]);
    }

    /**
     * Delete an attendance record.
     */
    public function destroy(int $id): JsonResponse
    {
        $attendance = Attendance::find($id);

        if (! $attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Data presensi tidak ditemukan.',
            ], 404);
        }

        $this->authorize('delete', $attendance);

        $attendance->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data presensi berhasil dihapus.',
        ]);
    }

    /**
     * Get attendance history for a specific user.
     */
    public function userAttendances(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', new Attendance(['user_id' => $userId]));

        $query = Attendance::where('user_id', $userId);

        if ($year = $request->query('year')) {
            $query->where('periode', 'like', "{$year}%");
        }

        $attendances = $query->orderByDesc('periode')->get();

        return response()->json([
            'success' => true,
            'message' => 'Riwayat presensi karyawan berhasil diambil.',
            'data' => AttendanceResource::collection($attendances),
        ]);
    }
}
