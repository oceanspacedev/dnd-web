<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\UserJsonImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * @tags Users / Karyawan
 */
class UserController extends Controller
{
    /**
     * Get paginated list of employees with comprehensive filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['role', 'area', 'divisi', 'position', 'approval']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nama_lengkap', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('employee_id', 'like', "%{$search}%");
            });
        }

        if ($roleId = $request->query('role_id')) {
            $query->where('role_id', $roleId);
        }

        if ($areaId = $request->query('area_id')) {
            $query->where('area_id', $areaId);
        }

        if ($divisiId = $request->query('divisi_id')) {
            $query->where('divisi_id', $divisiId);
        }

        if ($positionId = $request->query('position_id')) {
            $query->where('position_id', $positionId);
        }

        if ($approvalId = $request->query('approval_id')) {
            $query->where('approval_id', $approvalId);
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $users = $query->orderBy('nama_lengkap')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar karyawan berhasil diambil.',
            'data' => UserResource::collection($users),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    /**
     * Get detailed information of an employee including subordinates.
     */
    public function show(int $id): JsonResponse
    {
        $user = User::with(['role', 'area', 'divisi', 'position', 'approval'])->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $subordinates = User::where('approval_id', $user->id)
            ->with(['role', 'position'])
            ->get();

        $userData = (new UserResource($user))->toArray(request());
        $userData['subordinates'] = UserResource::collection($subordinates);

        return response()->json([
            'success' => true,
            'message' => 'Detail karyawan berhasil diambil.',
            'data' => $userData,
        ]);
    }

    /**
     * Create a new employee.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['password'] = Hash::make($validated['password']);

        // Default flags to 0 if not specified
        $validated['dr'] = $validated['dr'] ?? false;
        $validated['wn'] = $validated['wn'] ?? false;
        $validated['wr'] = $validated['wr'] ?? false;
        $validated['mn'] = $validated['mn'] ?? false;
        $validated['mr'] = $validated['mr'] ?? false;

        $user = User::create($validated);
        $user->load(['role', 'area', 'divisi', 'position', 'approval']);

        return response()->json([
            'success' => true,
            'message' => 'Karyawan baru berhasil ditambahkan.',
            'data' => new UserResource($user),
        ], 201);
    }

    /**
     * Update an employee.
     */
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validated();

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);
        $user->load(['role', 'area', 'divisi', 'position', 'approval']);

        return response()->json([
            'success' => true,
            'message' => 'Data karyawan berhasil diperbarui.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Delete (soft delete) an employee.
     */
    public function destroy(int $id): JsonResponse
    {
        $currentUser = auth()->user();

        if ($currentUser && $currentUser->id === $id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak dapat menghapus akun Anda sendiri.',
            ], 422);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Karyawan berhasil dinonaktifkan (dihapus).',
        ]);
    }

    /**
     * Get list of potential supervisors for dropdown selection.
     */
    public function supervisors(): JsonResponse
    {
        $supervisors = User::select('id', 'username', 'nama_lengkap', 'role_id', 'position_id')
            ->with(['role', 'position'])
            ->orderBy('nama_lengkap')
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'nama_lengkap' => $u->nama_lengkap,
                    'username' => $u->username,
                    'role' => $u->role ? $u->role->name : null,
                    'position' => $u->position ? $u->position->name : null,
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Daftar atasan berhasil diambil.',
            'data' => $supervisors,
            'meta' => [
                'total' => $supervisors->count(),
            ],
        ]);
    }

    /**
     * Trigger batch JSON employee import.
     */
    public function importJson(Request $request, UserJsonImportService $importService): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200'], // max 50MB
        ], [
            'file.required' => 'File JSON wajib diunggah.',
            'file.max' => 'Ukuran file maksimal 50 MB.',
        ]);

        $file = $request->file('file');
        $storedPath = $file->store('imports/json', 'local');
        $absolutePath = Storage::disk('local')->path($storedPath);

        try {
            $summary = $importService->import($absolutePath);

            return response()->json([
                'success' => true,
                'message' => 'Proses import JSON selesai.',
                'data' => $summary,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengimpor data: ' . $e->getMessage(),
            ], 500);
        }
    }
}
