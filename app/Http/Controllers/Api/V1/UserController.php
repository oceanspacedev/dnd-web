<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use App\Services\UserJsonImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * @tags Users / Karyawan
 */
class UserController extends Controller
{
    /** Menu Karyawan: karyawan di badan usaha yang boleh diakses. */
    public const SCOPE_EMPLOYEES = 'employees';

    /** Menu Tim Saya: tim berdasarkan approval line. */
    public const SCOPE_TEAM = 'team';

    /**
     * Get paginated list of employees with comprehensive filters.
     *
     * Query `scope` (opsional): `employees` = karyawan di badan usaha Anda (khusus admin),
     * `team` = tim approval line Anda. Tanpa `scope` = seluruh karyawan yang boleh Anda lihat.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $scopeValidator = Validator::make($request->query(), [
            'scope' => ['nullable', 'string', Rule::in([self::SCOPE_EMPLOYEES, self::SCOPE_TEAM])],
        ], [
            'scope.in' => 'Scope harus employees atau team.',
        ]);

        if ($scopeValidator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $scopeValidator->errors(),
            ], 422);
        }

        $query = User::with(['role', 'area', 'divisi', 'position', 'approval']);
        $actor = $request->user();
        $scope = $request->query('scope');

        if ($scope === self::SCOPE_EMPLOYEES) {
            abort_unless(
                DataAccessService::canAccessEmployees($actor),
                403,
                'Anda tidak memiliki akses data karyawan badan usaha.',
            );
        }

        $this->scopeVisibleUsers($query, $actor, is_string($scope) ? $scope : null);

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
        $users->getCollection()
            ->filter(fn (User $user): bool => $user->isAdmin())
            ->load('dataAccessAreas');

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

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', $user);

        $this->loadDataAccessAreas($user);

        $subordinatesQuery = User::where('approval_id', $user->id)
            ->with(['role', 'position']);
        $actor = auth()->user();

        $this->scopeVisibleUsers($subordinatesQuery, $actor instanceof User ? $actor : null);

        $subordinates = $subordinatesQuery->get();

        $userData = (new UserResource($user))->resolve(request());
        $userData['subordinates'] = UserResource::collection($subordinates);

        return response()->json([
            'success' => true,
            'message' => 'Detail karyawan berhasil diambil.',
            'data' => $userData,
        ]);
    }

    /**
     * Create a new employee.
     *
     * Role ADMIN tanpa `data_access` dibuat dengan akses `selected` ke badan usaha karyawan itu sendiri
     * (`area_id`), bukan super admin. Kirim `data_access: all` secara eksplisit untuk membuat super admin.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validated();
        $this->ensureCanAssignRole($request, $validated);
        $dataAccess = $this->defaultNewAdminDataAccess($validated, $this->pullDataAccess($validated));
        $validated['password'] = Hash::make($validated['password']);

        // Default flags to 0 if not specified
        $validated['dr'] = $validated['dr'] ?? false;
        $validated['wn'] = $validated['wn'] ?? false;
        $validated['wr'] = $validated['wr'] ?? false;
        $validated['mn'] = $validated['mn'] ?? false;
        $validated['mr'] = $validated['mr'] ?? false;

        $user = DB::transaction(function () use ($validated, $dataAccess): User {
            $user = User::create($validated);
            $this->applyDataAccess($user, $dataAccess);

            return $user;
        });
        $user->load(['role', 'area', 'divisi', 'position', 'approval']);
        $this->loadDataAccessAreas($user);

        return response()->json([
            'success' => true,
            'message' => 'Karyawan baru berhasil ditambahkan.',
            'data' => new UserResource($user),
        ], 201);
    }

    /**
     * Update an employee.
     *
     * Mempromosikan karyawan menjadi ADMIN tanpa `data_access` memberi akses `selected` ke badan usahanya sendiri.
     */
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('update', $user);

        $validated = $request->validated();
        $this->ensureCanAssignRole($request, $validated);
        $dataAccess = $this->pullDataAccess($validated);

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        DB::transaction(function () use ($user, $validated, $dataAccess): void {
            $user->update($validated);
            $this->applyDataAccess($user, $dataAccess);
        });
        $user->load(['role', 'area', 'divisi', 'position', 'approval']);
        $this->loadDataAccessAreas($user);

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

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Karyawan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('delete', $user);

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
        $this->authorize('viewAny', User::class);

        $actor = auth()->user();
        $supervisorsQuery = User::select('id', 'username', 'nama_lengkap', 'role_id', 'position_id')
            ->with(['role', 'position'])
            ->orderBy('nama_lengkap');

        // Opsi approval line mengikuti akses data actor, termasuk dirinya sendiri.
        $this->scopeVisibleUsers($supervisorsQuery, $actor instanceof User ? $actor : null, includeSelf: true);

        $supervisors = $supervisorsQuery
            ->get()
            ->map(function (User $u): array {
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
    public function importJson(Request $request): JsonResponse
    {
        // Import menyentuh seluruh badan usaha: khusus admin dengan akses seluruh badan usaha.
        abort_unless(DataAccessService::hasGlobalAccess($request->user()), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:51200'], // max 50MB
        ], [
            'file.required' => 'File JSON wajib diunggah.',
            'file.max' => 'Ukuran file maksimal 50 MB.',
        ]);

        $file = $request->file('file');

        try {
            if (! $file instanceof UploadedFile) {
                throw new \RuntimeException('File upload tidak valid.');
            }

            $content = $file->get();

            if (! is_string($content)) {
                throw new \RuntimeException('File upload tidak dapat dibaca.');
            }

            $summary = UserJsonImportService::importFromContent($content);

            return response()->json([
                'success' => true,
                'message' => 'Proses import JSON selesai.',
                'data' => $summary,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengimpor data: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Batasi query users ke karyawan yang boleh dilihat actor.
     *
     * - Super admin: tanpa batas (scope `team` tetap hanya tim approval line).
     * - Admin badan usaha: badan usaha yang diizinkan + tim
     *   (`employees` = badan usaha saja, `team` = tim saja).
     * - Non-admin: tim approval line (aturan lama).
     * Selain super admin, akun ADMIN tidak ditampilkan, termasuk akun sendiri: UserPolicy hanya
     * mengizinkan super admin membuka akun ADMIN, jadi setiap baris di daftar tetap bisa dibuka.
     * Profil sendiri tersedia lewat /auth/me; $includeSelf hanya untuk opsi approval line.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function scopeVisibleUsers(Builder $query, ?User $actor, ?string $scope = null, bool $includeSelf = false): Builder
    {
        if (! $actor instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $actorId = (int) $actor->id;

        if (DataAccessService::hasGlobalAccess($actor)) {
            return $scope === self::SCOPE_TEAM
                ? $query->whereIn('users.id', ApprovalScopeService::getManagedUserIdsOneLevelDown($actorId))
                : $query;
        }

        if ($scope === self::SCOPE_EMPLOYEES) {
            DataAccessService::scopeEmployees($query, $actor, 'users.area_id');
        } elseif ($scope === self::SCOPE_TEAM || ! DataAccessService::isAdmin($actor)) {
            $teamIds = ApprovalScopeService::getManagedUserIdsOneLevelDown($actorId);

            if ($includeSelf) {
                $teamIds[] = $actorId;
            }

            $query->whereIn('users.id', array_values(array_unique($teamIds)));
        } else {
            DataAccessService::scopeOwnedRecords($query, $actor, 'users.id', includeSelf: $includeSelf);
        }

        return $query->where(function (Builder $visible) use ($actorId, $includeSelf): void {
            $visible->whereHas('role', fn (Builder $roleQuery) => $roleQuery->where('name', '!=', User::ROLE_ADMIN));

            if ($includeSelf) {
                $visible->orWhere('users.id', $actorId);
            }
        });
    }

    /**
     * Ambil field akses data dari payload; diterapkan terpisah lewat applyDataAccess().
     *
     * @param  array<string, mixed>  $validated
     * @return array{data_access: string|null, area_ids: array<int>|null}|null
     */
    private function pullDataAccess(array &$validated): ?array
    {
        $sent = array_key_exists('data_access', $validated) || array_key_exists('data_access_area_ids', $validated);

        $dataAccess = [
            'data_access' => $validated['data_access'] ?? null,
            'area_ids' => isset($validated['data_access_area_ids'])
                ? array_values(array_unique(array_map('intval', $validated['data_access_area_ids'])))
                : null,
        ];

        unset($validated['data_access'], $validated['data_access_area_ids']);

        return $sent ? $dataAccess : null;
    }

    /**
     * Fail closed: ADMIN baru tanpa data_access tidak menjadi super admin (default kolom 'all'),
     * tetapi 'selected' dengan badan usaha karyawan itu sendiri. data_access eksplisit dari super admin tetap dipakai.
     * Promosi lewat update ditangani model User.
     *
     * @param  array<string, mixed>  $validated
     * @param  array{data_access: string|null, area_ids: array<int>|null}|null  $requested
     * @return array{data_access: string|null, area_ids: array<int>|null}|null
     */
    private function defaultNewAdminDataAccess(array $validated, ?array $requested): ?array
    {
        if (filled($requested['data_access'] ?? null) || ! User::isAdminRoleId($validated['role_id'] ?? null)) {
            return $requested;
        }

        $areaIds = $requested['area_ids'] ?? [];

        if ($areaIds === [] && filled($validated['area_id'] ?? null)) {
            $areaIds = [(int) $validated['area_id']];
        }

        return [
            'data_access' => User::DATA_ACCESS_SELECTED,
            'area_ids' => $areaIds,
        ];
    }

    /**
     * Simpan akses data badan usaha. Hanya berlaku untuk role ADMIN; role lain selalu
     * kembali ke 'all' tanpa daftar badan usaha. Hak mengirim field ini dicek di FormRequest.
     *
     * @param  array{data_access: string|null, area_ids: array<int>|null}|null  $requested
     */
    private function applyDataAccess(User $user, ?array $requested): void
    {
        $user->load('role');

        if (! $user->isAdmin()) {
            if ($user->data_access === User::DATA_ACCESS_SELECTED) {
                $user->forceFill(['data_access' => User::DATA_ACCESS_ALL])->save();
                $user->dataAccessAreas()->detach();
                DataAccessService::clearMemo();
            }

            return;
        }

        if ($requested === null) {
            return;
        }

        $mode = $requested['data_access']
            ?? ($user->data_access === User::DATA_ACCESS_SELECTED ? User::DATA_ACCESS_SELECTED : User::DATA_ACCESS_ALL);

        $user->forceFill(['data_access' => $mode])->save();

        if ($mode === User::DATA_ACCESS_ALL) {
            $user->dataAccessAreas()->detach();
        } elseif ($requested['area_ids'] !== null) {
            $user->dataAccessAreas()->sync($requested['area_ids']);
        }

        DataAccessService::clearMemo();
    }

    private function loadDataAccessAreas(User $user): void
    {
        if ($user->isAdmin()) {
            $user->load('dataAccessAreas');
        }
    }

    /**
     * Role ADMIN hanya boleh diberikan oleh admin dengan akses seluruh badan usaha.
     *
     * @param  array<string, mixed>  $validated
     */
    private function ensureCanAssignRole(Request $request, array $validated): void
    {
        if (
            ! array_key_exists('role_id', $validated)
            || DataAccessService::hasGlobalAccess($request->user())
        ) {
            return;
        }

        abort_if(
            Role::query()->whereKey($validated['role_id'])->value('name') === User::ROLE_ADMIN,
            403,
            'Hanya admin dengan akses seluruh badan usaha yang dapat memberikan role ADMIN.',
        );
    }
}
