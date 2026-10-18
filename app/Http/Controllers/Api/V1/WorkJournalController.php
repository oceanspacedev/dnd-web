<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWorkJournalRequest;
use App\Http\Requests\Api\V1\UpdateWorkJournalRequest;
use App\Http\Resources\Api\V1\WorkJournalResource;
use App\Models\WorkJournal;
use App\Policies\WorkJournalPolicy;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * @tags Jurnal Harian
 */
class WorkJournalController extends Controller
{
    /**
     * Get paginated list of daily work journals with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WorkJournal::class);

        $query = WorkJournal::with(['user.divisi', 'user.area', 'user.position']);

        // Super admin: semua jurnal; admin per badan usaha: badan usaha yang diizinkan, tim approval,
        // dan dirinya sendiri; lainnya: diri sendiri + bawahan approval.
        WorkJournalPolicy::scopeVisible($query, $request->user());

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($date = $request->query('date')) {
            $query->whereDate('date', $date);
        }

        if ($month = $request->query('month')) {
            $parts = explode('-', $month);
            if (count($parts) === 2) {
                $query->whereYear('date', (int) $parts[0])
                    ->whereMonth('date', (int) $parts[1]);
            }
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('activity', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('nama_lengkap', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $journals = $query->orderByDesc('date')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar jurnal harian berhasil diambil.',
            'data' => WorkJournalResource::collection($journals),
            'meta' => [
                'current_page' => $journals->currentPage(),
                'per_page' => $journals->perPage(),
                'total' => $journals->total(),
                'last_page' => $journals->lastPage(),
            ],
        ]);
    }

    /**
     * Get today's work journal for currently authenticated user.
     */
    public function today(Request $request): JsonResponse
    {
        $userId = auth()->id();
        $today = Date::now()->toDateString();

        $journal = WorkJournal::with(['user.divisi', 'user.area', 'user.position'])
            ->where('user_id', $userId)
            ->whereDate('date', $today)
            ->first();

        if (! $journal) {
            return response()->json([
                'success' => true,
                'message' => 'Anda belum mengisi jurnal harian untuk hari ini ('.$today.').',
                'data' => null,
                'has_submitted_today' => false,
                'today' => $today,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Jurnal harian hari ini berhasil diambil.',
            'data' => new WorkJournalResource($journal),
            'has_submitted_today' => true,
            'today' => $today,
        ]);
    }

    /**
     * Get journals of subordinates / team members for currently authenticated supervisor.
     *
     * Endpoint "Tim Saya": hanya tim approval line (bawahan langsung + satu level) untuk semua role,
     * termasuk admin. Jurnal karyawan per badan usaha tersedia lewat index.
     */
    public function team(Request $request): JsonResponse
    {
        $currentUser = auth()->user();
        $query = WorkJournal::with(['user.divisi', 'user.area', 'user.position']);

        // Admin tetap melihat seluruh jurnal dalam cakupan akses datanya (super admin: semua, seperti sebelumnya).
        if (DataAccessService::isAdmin($currentUser)) {
            return $this->paginateTeamJournals(
                DataAccessService::scopeOwnedRecords($query, $currentUser, 'work_journals.user_id'),
                $request,
            );
        }

        $subordinateIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $currentUser->id);

        if ($subordinateIds === []) {
            // User has no subordinates
            return response()->json([
                'success' => true,
                'message' => 'Anda belum memiliki bawahan langsung yang terdaftar.',
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 15,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ]);
        }

        return $this->paginateTeamJournals($query->whereIn('user_id', $subordinateIds), $request);
    }

    /**
     * @param  Builder<WorkJournal>  $query
     */
    private function paginateTeamJournals($query, Request $request): JsonResponse
    {
        if ($date = $request->query('date')) {
            $query->whereDate('date', $date);
        }

        if ($month = $request->query('month')) {
            $parts = explode('-', $month);
            if (count($parts) === 2) {
                $query->whereYear('date', (int) $parts[0])
                    ->whereMonth('date', (int) $parts[1]);
            }
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('activity', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('nama_lengkap', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $journals = $query->orderByDesc('date')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar jurnal harian tim bawahan berhasil diambil.',
            'data' => WorkJournalResource::collection($journals),
            'meta' => [
                'current_page' => $journals->currentPage(),
                'per_page' => $journals->perPage(),
                'total' => $journals->total(),
                'last_page' => $journals->lastPage(),
            ],
        ]);
    }

    /**
     * Get detail of a specific work journal.
     */
    public function show(int $id): JsonResponse
    {
        $journal = WorkJournal::with(['user.divisi', 'user.area', 'user.position'])->find($id);

        if (! $journal) {
            return response()->json([
                'success' => false,
                'message' => 'Data jurnal harian tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', $journal);

        return response()->json([
            'success' => true,
            'message' => 'Detail jurnal harian berhasil diambil.',
            'data' => new WorkJournalResource($journal),
        ]);
    }

    /**
     * Store a new daily work journal.
     */
    public function store(StoreWorkJournalRequest $request): JsonResponse
    {
        $this->authorize('create', WorkJournal::class);

        $validated = $request->validated();
        $currentUser = auth()->user();
        if (! DataAccessService::isAdmin($currentUser)) {
            $validated['user_id'] = $currentUser?->id;
        } else {
            // Admin boleh mencatat jurnal karyawan lain; admin per badan usaha hanya dalam cakupannya dan
            // bukan akun ADMIN lain (sudah divalidasi StoreWorkJournalRequest, dicek ulang lewat policy).
            $validated['user_id'] = (int) ($validated['user_id'] ?? $currentUser?->id);
            $this->authorize('update', new WorkJournal(['user_id' => $validated['user_id']]));
        }
        $validated['date'] = $validated['date'] ?? Date::now()->toDateString();

        $journal = WorkJournal::create($validated);
        $journal->load(['user.divisi', 'user.area', 'user.position']);

        return response()->json([
            'success' => true,
            'message' => 'Jurnal harian berhasil disimpan.',
            'data' => new WorkJournalResource($journal),
        ], 201);
    }

    /**
     * Update an existing work journal.
     */
    public function update(UpdateWorkJournalRequest $request, int $id): JsonResponse
    {
        $journal = WorkJournal::find($id);

        if (! $journal) {
            return response()->json([
                'success' => false,
                'message' => 'Data jurnal harian tidak ditemukan.',
            ], 404);
        }

        $this->authorize('update', $journal);

        $validated = $request->validated();
        $journal->update($validated);
        $journal->load(['user.divisi', 'user.area', 'user.position']);

        return response()->json([
            'success' => true,
            'message' => 'Jurnal harian berhasil diperbarui.',
            'data' => new WorkJournalResource($journal),
        ]);
    }

    /**
     * Delete a work journal.
     */
    public function destroy(int $id): JsonResponse
    {
        $journal = WorkJournal::find($id);

        if (! $journal) {
            return response()->json([
                'success' => false,
                'message' => 'Data jurnal harian tidak ditemukan.',
            ], 404);
        }

        $this->authorize('delete', $journal);

        $journal->delete();

        return response()->json([
            'success' => true,
            'message' => 'Jurnal harian berhasil dihapus.',
        ]);
    }
}
