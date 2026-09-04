<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AreaResource;
use App\Http\Resources\Api\V1\DivisiResource;
use App\Http\Resources\Api\V1\PositionResource;
use App\Http\Resources\Api\V1\RoleResource;
use App\Models\Area;
use App\Models\Divisi;
use App\Models\Position;
use App\Models\Role;
use App\Models\TaskCategory;
use App\Models\TaskStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @tags Master Data
 */
class MasterDataController extends Controller
{
    // ==========================================
    // AREA ENDPOINTS
    // ==========================================

    /**
     * Get list of areas.
     */
    public function areas(Request $request): JsonResponse
    {
        $query = Area::withCount('divisi')->orderBy('name');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $areas = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Data area berhasil diambil.',
            'data' => AreaResource::collection($areas),
            'meta' => [
                'total' => $areas->count(),
            ],
        ]);
    }

    /**
     * Get detail of an area.
     */
    public function showArea(int $id): JsonResponse
    {
        $area = Area::with('divisi')->find($id);

        if (! $area) {
            return response()->json([
                'success' => false,
                'message' => 'Area tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail area berhasil diambil.',
            'data' => new AreaResource($area),
        ]);
    }

    /**
     * Create a new area.
     */
    public function storeArea(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:areas,name'],
        ], [
            'name.required' => 'Nama area wajib diisi.',
            'name.unique' => 'Nama area sudah terdaftar.',
        ]);

        $area = Area::create([
            'name' => strtoupper(trim($validated['name'])),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Area berhasil ditambahkan.',
            'data' => new AreaResource($area),
        ], 201);
    }

    /**
     * Update an area.
     */
    public function updateArea(Request $request, int $id): JsonResponse
    {
        $area = Area::find($id);

        if (! $area) {
            return response()->json([
                'success' => false,
                'message' => 'Area tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('areas', 'name')->ignore($area->id)],
        ], [
            'name.required' => 'Nama area wajib diisi.',
            'name.unique' => 'Nama area sudah terdaftar.',
        ]);

        $area->update([
            'name' => strtoupper(trim($validated['name'])),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Area berhasil diperbarui.',
            'data' => new AreaResource($area),
        ]);
    }

    /**
     * Delete an area.
     */
    public function destroyArea(int $id): JsonResponse
    {
        $area = Area::find($id);

        if (! $area) {
            return response()->json([
                'success' => false,
                'message' => 'Area tidak ditemukan.',
            ], 404);
        }

        $area->delete();

        return response()->json([
            'success' => true,
            'message' => 'Area berhasil dihapus.',
        ]);
    }

    // ==========================================
    // DIVISI ENDPOINTS
    // ==========================================

    /**
     * Get list of divisions.
     */
    public function divisis(Request $request): JsonResponse
    {
        $query = Divisi::with('area')->orderBy('name');

        if ($areaId = $request->query('area_id')) {
            $query->where('area_id', $areaId);
        }

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $divisis = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Data divisi berhasil diambil.',
            'data' => DivisiResource::collection($divisis),
            'meta' => [
                'total' => $divisis->count(),
            ],
        ]);
    }

    /**
     * Get detail of a division.
     */
    public function showDivisi(int $id): JsonResponse
    {
        $divisi = Divisi::with('area')->find($id);

        if (! $divisi) {
            return response()->json([
                'success' => false,
                'message' => 'Divisi tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail divisi berhasil diambil.',
            'data' => new DivisiResource($divisi),
        ]);
    }

    /**
     * Create a new division.
     */
    public function storeDivisi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'area_id' => ['required', 'exists:areas,id'],
        ], [
            'name.required' => 'Nama divisi wajib diisi.',
            'area_id.required' => 'Area wajib dipilih.',
            'area_id.exists' => 'Area tidak valid.',
        ]);

        $divisi = Divisi::create([
            'name' => strtoupper(trim($validated['name'])),
            'area_id' => $validated['area_id'],
        ]);

        $divisi->load('area');

        return response()->json([
            'success' => true,
            'message' => 'Divisi berhasil ditambahkan.',
            'data' => new DivisiResource($divisi),
        ], 201);
    }

    /**
     * Update a division.
     */
    public function updateDivisi(Request $request, int $id): JsonResponse
    {
        $divisi = Divisi::find($id);

        if (! $divisi) {
            return response()->json([
                'success' => false,
                'message' => 'Divisi tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'area_id' => ['sometimes', 'required', 'exists:areas,id'],
        ]);

        if (isset($validated['name'])) {
            $validated['name'] = strtoupper(trim($validated['name']));
        }

        $divisi->update($validated);
        $divisi->load('area');

        return response()->json([
            'success' => true,
            'message' => 'Divisi berhasil diperbarui.',
            'data' => new DivisiResource($divisi),
        ]);
    }

    /**
     * Delete a division.
     */
    public function destroyDivisi(int $id): JsonResponse
    {
        $divisi = Divisi::find($id);

        if (! $divisi) {
            return response()->json([
                'success' => false,
                'message' => 'Divisi tidak ditemukan.',
            ], 404);
        }

        $divisi->delete();

        return response()->json([
            'success' => true,
            'message' => 'Divisi berhasil dihapus.',
        ]);
    }

    // ==========================================
    // POSITION ENDPOINTS
    // ==========================================

    /**
     * Get list of positions.
     */
    public function positions(Request $request): JsonResponse
    {
        $query = Position::orderBy('name');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $positions = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Data posisi berhasil diambil.',
            'data' => PositionResource::collection($positions),
            'meta' => [
                'total' => $positions->count(),
            ],
        ]);
    }

    /**
     * Get detail of a position.
     */
    public function showPosition(int $id): JsonResponse
    {
        $position = Position::find($id);

        if (! $position) {
            return response()->json([
                'success' => false,
                'message' => 'Posisi tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail posisi berhasil diambil.',
            'data' => new PositionResource($position),
        ]);
    }

    /**
     * Create a new position.
     */
    public function storePosition(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:positions,name'],
        ], [
            'name.required' => 'Nama posisi wajib diisi.',
            'name.unique' => 'Nama posisi sudah terdaftar.',
        ]);

        $position = Position::create([
            'name' => strtoupper(trim($validated['name'])),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Posisi berhasil ditambahkan.',
            'data' => new PositionResource($position),
        ], 201);
    }

    /**
     * Update a position.
     */
    public function updatePosition(Request $request, int $id): JsonResponse
    {
        $position = Position::find($id);

        if (! $position) {
            return response()->json([
                'success' => false,
                'message' => 'Posisi tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('positions', 'name')->ignore($position->id)],
        ]);

        $position->update([
            'name' => strtoupper(trim($validated['name'])),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Posisi berhasil diperbarui.',
            'data' => new PositionResource($position),
        ]);
    }

    /**
     * Delete a position.
     */
    public function destroyPosition(int $id): JsonResponse
    {
        $position = Position::find($id);

        if (! $position) {
            return response()->json([
                'success' => false,
                'message' => 'Posisi tidak ditemukan.',
            ], 404);
        }

        $position->delete();

        return response()->json([
            'success' => true,
            'message' => 'Posisi berhasil dihapus.',
        ]);
    }

    // ==========================================
    // ROLE ENDPOINTS
    // ==========================================

    /**
     * Get list of roles.
     */
    public function roles(): JsonResponse
    {
        $roles = Role::orderBy('id')->get();

        return response()->json([
            'success' => true,
            'message' => 'Data role berhasil diambil.',
            'data' => RoleResource::collection($roles),
            'meta' => [
                'total' => $roles->count(),
            ],
        ]);
    }

    /**
     * Get detail of a role.
     */
    public function showRole(int $id): JsonResponse
    {
        $role = Role::find($id);

        if (! $role) {
            return response()->json([
                'success' => false,
                'message' => 'Role tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail role berhasil diambil.',
            'data' => new RoleResource($role),
        ]);
    }

    // ==========================================
    // TASK CATEGORY & STATUS (FOR DAILY TASKS)
    // ==========================================

    /**
     * Get list of daily task categories.
     */
    public function taskCategories(): JsonResponse
    {
        $categories = TaskCategory::all()->map(function ($cat) {
            return [
                'id' => $cat->id,
                'name' => $cat->task_category,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Kategori tugas berhasil diambil.',
            'data' => $categories,
            'meta' => [
                'total' => $categories->count(),
            ],
        ]);
    }

    /**
     * Get list of daily task statuses.
     */
    public function taskStatuses(): JsonResponse
    {
        $statuses = TaskStatus::all()->map(function ($status) {
            return [
                'id' => $status->id,
                'name' => $status->task_status,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Status tugas berhasil diambil.',
            'data' => $statuses,
            'meta' => [
                'total' => $statuses->count(),
            ],
        ]);
    }
}
