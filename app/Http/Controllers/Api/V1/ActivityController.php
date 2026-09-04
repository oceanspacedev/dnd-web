<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDailyLogRequest;
use App\Http\Requests\Api\V1\StoreDailyRequest;
use App\Http\Requests\Api\V1\StoreMonthlyRequest;
use App\Http\Requests\Api\V1\StoreWeeklyRequest;
use App\Http\Requests\Api\V1\UpdateDailyRequest;
use App\Http\Requests\Api\V1\UpdateMonthlyRequest;
use App\Http\Requests\Api\V1\UpdateWeeklyRequest;
use App\Http\Resources\Api\V1\DailyLogResource;
use App\Http\Resources\Api\V1\DailyResource;
use App\Http\Resources\Api\V1\MonthlyResource;
use App\Http\Resources\Api\V1\WeeklyResource;
use App\Models\Daily;
use App\Models\DailyLog;
use App\Models\Monthly;
use App\Models\User;
use App\Models\Weekly;
use App\Services\ApprovalScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * @tags Aktivitas Kerja (Daily, Weekly, Monthly)
 */
class ActivityController extends Controller
{
    // ==========================================
    // 1. DAILY TASKS (AKTIVITAS HARIAN)
    // ==========================================

    /**
     * Get paginated list of daily tasks with filters.
     */
    public function dailies(Request $request): JsonResponse
    {
        $query = Daily::with(['user', 'taskcategory', 'taskstatus', 'tag', 'add'])
            ->withCount('dailyLog');
        $this->applyVisibleUserScope($query, $request->user());

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($date = $request->query('date')) {
            $query->whereDate('date', $date);
        }

        if ($startDate = $request->query('start_date')) {
            $query->whereDate('date', '>=', $startDate);
        }

        if ($endDate = $request->query('end_date')) {
            $query->whereDate('date', '<=', $endDate);
        }

        if ($categoryId = $request->query('task_category_id')) {
            $query->where('task_category_id', $categoryId);
        }

        if ($statusId = $request->query('task_status_id')) {
            $query->where('task_status_id', $statusId);
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($search = $request->query('search')) {
            $query->where('task', 'like', "%{$search}%");
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $dailies = $query->orderByDesc('date')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar tugas harian berhasil diambil.',
            'data' => DailyResource::collection($dailies),
            'meta' => [
                'current_page' => $dailies->currentPage(),
                'per_page' => $dailies->perPage(),
                'total' => $dailies->total(),
                'last_page' => $dailies->lastPage(),
            ],
        ]);
    }

    /**
     * Get detail of a daily task.
     */
    public function showDaily(int $id): JsonResponse
    {
        $daily = Daily::with(['user', 'taskcategory', 'taskstatus', 'tag', 'add', 'dailyLog.user'])->find($id);

        if (! $daily) {
            return response()->json([
                'success' => false,
                'message' => 'Tugas harian tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', $daily);

        return response()->json([
            'success' => true,
            'message' => 'Detail tugas harian berhasil diambil.',
            'data' => new DailyResource($daily),
        ]);
    }

    /**
     * Create a new daily task.
     */
    public function storeDaily(StoreDailyRequest $request): JsonResponse
    {
        $this->authorize('create', Daily::class);

        $validated = $request->validated();
        $currentUser = auth()->user();

        $validated['user_id'] = $validated['user_id'] ?? $currentUser?->id;
        $this->authorize('view', new Daily(['user_id' => (int) $validated['user_id']]));
        $validated['add_id'] = $currentUser?->id;
        $validated['tipe'] = $validated['tipe'] ?? 'daily';
        $validated['ontime'] = $validated['ontime'] ?? true;
        $validated['isplan'] = $validated['isplan'] ?? true;
        $validated['isupdate'] = false;
        $validated['status'] = $validated['status'] ?? 0;

        $daily = Daily::create($validated);
        $daily->load(['user', 'taskcategory', 'taskstatus', 'tag', 'add']);

        return response()->json([
            'success' => true,
            'message' => 'Tugas harian berhasil ditambahkan.',
            'data' => new DailyResource($daily),
        ], 201);
    }

    /**
     * Update a daily task.
     */
    public function updateDaily(UpdateDailyRequest $request, int $id): JsonResponse
    {
        $daily = Daily::find($id);

        if (! $daily) {
            return response()->json([
                'success' => false,
                'message' => 'Tugas harian tidak ditemukan.',
            ], 404);
        }

        $this->authorize('update', $daily);

        $validated = $request->validated();
        $validated['isupdate'] = true;

        $daily->update($validated);
        $daily->load(['user', 'taskcategory', 'taskstatus', 'tag', 'add', 'dailyLog.user']);

        return response()->json([
            'success' => true,
            'message' => 'Tugas harian berhasil diperbarui.',
            'data' => new DailyResource($daily),
        ]);
    }

    /**
     * Delete a daily task.
     */
    public function destroyDaily(int $id): JsonResponse
    {
        $daily = Daily::find($id);

        if (! $daily) {
            return response()->json([
                'success' => false,
                'message' => 'Tugas harian tidak ditemukan.',
            ], 404);
        }

        $this->authorize('delete', $daily);

        $daily->dailyLog()->delete();
        $daily->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tugas harian berhasil dihapus.',
        ]);
    }

    // ==========================================
    // 2. DAILY LOGS (RIWAYAT AKTIVITAS)
    // ==========================================

    /**
     * Get logs for a specific daily task.
     */
    public function dailyLogs(int $dailyId): JsonResponse
    {
        $daily = Daily::find($dailyId);

        if (! $daily) {
            return response()->json([
                'success' => false,
                'message' => 'Tugas harian tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', $daily);

        $logs = DailyLog::with('user')
            ->where('task_id', $dailyId)->oldest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Riwayat log aktivitas berhasil diambil.',
            'data' => DailyLogResource::collection($logs),
        ]);
    }

    /**
     * Add an activity log entry to a daily task.
     */
    public function storeDailyLog(StoreDailyLogRequest $request, int $dailyId): JsonResponse
    {
        $daily = Daily::find($dailyId);

        if (! $daily) {
            return response()->json([
                'success' => false,
                'message' => 'Tugas harian tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', $daily);

        $log = DailyLog::create([
            'user_id' => auth()->id(),
            'task_id' => $dailyId,
            'activity' => $request->validated()['activity'],
        ]);

        $log->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Log aktivitas berhasil dicatat.',
            'data' => new DailyLogResource($log),
        ], 201);
    }

    // ==========================================
    // 3. WEEKLY TASKS (AKTIVITAS MINGGUAN)
    // ==========================================

    /**
     * Get paginated list of weekly tasks with filters.
     */
    public function weeklies(Request $request): JsonResponse
    {
        $query = Weekly::with(['user', 'taskcategory', 'taskstatus', 'tag', 'add']);
        $this->applyVisibleUserScope($query, $request->user());

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($week = $request->query('week')) {
            $query->where('week', $week);
        }

        if ($year = $request->query('year')) {
            $query->where('year', $year);
        }

        if ($categoryId = $request->query('task_category_id')) {
            $query->where('task_category_id', $categoryId);
        }

        if ($statusId = $request->query('task_status_id')) {
            $query->where('task_status_id', $statusId);
        }

        if ($search = $request->query('search')) {
            $query->where('task', 'like', "%{$search}%");
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $weeklies = $query->orderByDesc('year')->orderByDesc('week')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar tugas mingguan berhasil diambil.',
            'data' => WeeklyResource::collection($weeklies),
            'meta' => [
                'current_page' => $weeklies->currentPage(),
                'per_page' => $weeklies->perPage(),
                'total' => $weeklies->total(),
                'last_page' => $weeklies->lastPage(),
            ],
        ]);
    }

    /**
     * Get detail of a weekly task.
     */
    public function showWeekly(int $id): JsonResponse
    {
        $weekly = Weekly::with(['user', 'taskcategory', 'taskstatus', 'tag', 'add'])->find($id);

        if (! $weekly) {
            return response()->json([
                'success' => false,
                'message' => 'Tugas mingguan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', $weekly);

        return response()->json([
            'success' => true,
            'message' => 'Detail tugas mingguan berhasil diambil.',
            'data' => new WeeklyResource($weekly),
        ]);
    }

    /**
     * Create a new weekly task.
     */
    public function storeWeekly(StoreWeeklyRequest $request): JsonResponse
    {
        $this->authorize('create', Weekly::class);

        $validated = $request->validated();
        $currentUser = auth()->user();

        $validated['user_id'] = $validated['user_id'] ?? $currentUser?->id;
        $this->authorize('view', new Weekly(['user_id' => (int) $validated['user_id']]));
        $validated['add_id'] = $currentUser?->id;
        $validated['tipe'] = $validated['tipe'] ?? 'weekly';
        $validated['is_add'] = true;
        $validated['is_update'] = false;

        $weekly = Weekly::create($validated);
        $weekly->load(['user', 'taskcategory', 'taskstatus', 'tag', 'add']);

        return response()->json([
            'success' => true,
            'message' => 'Tugas mingguan berhasil ditambahkan.',
            'data' => new WeeklyResource($weekly),
        ], 201);
    }

    /**
     * Update a weekly task.
     */
    public function updateWeekly(UpdateWeeklyRequest $request, int $id): JsonResponse
    {
        $weekly = Weekly::find($id);

        if (! $weekly) {
            return response()->json([
                'success' => false,
                'message' => 'Tugas mingguan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('update', $weekly);

        $validated = $request->validated();
        $validated['is_update'] = true;

        $weekly->update($validated);
        $weekly->load(['user', 'taskcategory', 'taskstatus', 'tag', 'add']);

        return response()->json([
            'success' => true,
            'message' => 'Tugas mingguan berhasil diperbarui.',
            'data' => new WeeklyResource($weekly),
        ]);
    }

    /**
     * Delete a weekly task.
     */
    public function destroyWeekly(int $id): JsonResponse
    {
        $weekly = Weekly::find($id);

        if (! $weekly) {
            return response()->json([
                'success' => false,
                'message' => 'Tugas mingguan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('delete', $weekly);

        $weekly->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tugas mingguan berhasil dihapus.',
        ]);
    }

    // ==========================================
    // 4. MONTHLY TASKS (AKTIVITAS BULANAN)
    // ==========================================

    /**
     * Get paginated list of monthly tasks with filters.
     */
    public function monthlies(Request $request): JsonResponse
    {
        $query = Monthly::with(['user', 'tag', 'add']);
        $this->applyVisibleUserScope($query, $request->user());

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($year = $request->query('year')) {
            $query->whereYear('date', $year);
        }

        if ($month = $request->query('month')) {
            $query->whereMonth('date', $month);
        }

        if ($search = $request->query('search')) {
            $query->where('task', 'like', "%{$search}%");
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $monthlies = $query->orderByDesc('date')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Daftar tugas bulanan berhasil diambil.',
            'data' => MonthlyResource::collection($monthlies),
            'meta' => [
                'current_page' => $monthlies->currentPage(),
                'per_page' => $monthlies->perPage(),
                'total' => $monthlies->total(),
                'last_page' => $monthlies->lastPage(),
            ],
        ]);
    }

    /**
     * Get detail of a monthly task.
     */
    public function showMonthly(int $id): JsonResponse
    {
        $monthly = Monthly::with(['user', 'tag', 'add'])->find($id);

        if (! $monthly) {
            return response()->json([
                'success' => false,
                'message' => 'Tugas bulanan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('view', $monthly);

        return response()->json([
            'success' => true,
            'message' => 'Detail tugas bulanan berhasil diambil.',
            'data' => new MonthlyResource($monthly),
        ]);
    }

    /**
     * Create a new monthly task.
     */
    public function storeMonthly(StoreMonthlyRequest $request): JsonResponse
    {
        $this->authorize('create', Monthly::class);

        $validated = $request->validated();
        $currentUser = auth()->user();

        $validated['user_id'] = $validated['user_id'] ?? $currentUser?->id;
        $this->authorize('view', new Monthly(['user_id' => (int) $validated['user_id']]));
        $validated['add_id'] = $currentUser?->id;
        $validated['tipe'] = $validated['tipe'] ?? 'monthly';
        $validated['is_add'] = true;
        $validated['is_update'] = false;

        $monthly = Monthly::create($validated);
        $monthly->load(['user', 'tag', 'add']);

        return response()->json([
            'success' => true,
            'message' => 'Tugas bulanan berhasil ditambahkan.',
            'data' => new MonthlyResource($monthly),
        ], 201);
    }

    /**
     * Update a monthly task.
     */
    public function updateMonthly(UpdateMonthlyRequest $request, int $id): JsonResponse
    {
        $monthly = Monthly::find($id);

        if (! $monthly) {
            return response()->json([
                'success' => false,
                'message' => 'Tugas bulanan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('update', $monthly);

        $validated = $request->validated();
        $validated['is_update'] = true;

        $monthly->update($validated);
        $monthly->load(['user', 'tag', 'add']);

        return response()->json([
            'success' => true,
            'message' => 'Tugas bulanan berhasil diperbarui.',
            'data' => new MonthlyResource($monthly),
        ]);
    }

    /**
     * Delete a monthly task.
     */
    public function destroyMonthly(int $id): JsonResponse
    {
        $monthly = Monthly::find($id);

        if (! $monthly) {
            return response()->json([
                'success' => false,
                'message' => 'Tugas bulanan tidak ditemukan.',
            ], 404);
        }

        $this->authorize('delete', $monthly);

        $monthly->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tugas bulanan berhasil dihapus.',
        ]);
    }

    // ==========================================
    // 5. ACTIVITY SUMMARY (RINGKASAN PROGRES)
    // ==========================================

    /**
     * Get cumulative activity summary for current user or filtered employee.
     */
    public function summary(Request $request): JsonResponse
    {
        $userId = $request->query('user_id', auth()->id());
        $this->authorize('view', new Daily(['user_id' => (int) $userId]));
        $today = Date::today()->format('Y-m-d');

        $dailyQuery = Daily::where('user_id', $userId);
        $totalDailyToday = (clone $dailyQuery)->whereDate('date', $today)->count();
        $completedDailyToday = (clone $dailyQuery)->whereDate('date', $today)->where('status', 1)->count();
        $pendingDailyToday = (clone $dailyQuery)->whereDate('date', $today)->where('status', 0)->count();

        $currentWeek = (int) date('W');
        $currentYear = (int) date('Y');
        $totalWeekly = Weekly::where('user_id', $userId)->where('week', $currentWeek)->where('year', $currentYear)->count();

        $currentMonth = (int) date('m');
        $totalMonthly = Monthly::where('user_id', $userId)->whereYear('date', $currentYear)->whereMonth('date', $currentMonth)->count();

        return response()->json([
            'success' => true,
            'message' => 'Ringkasan aktivitas kerja berhasil diambil.',
            'data' => [
                'user_id' => (int) $userId,
                'date' => $today,
                'daily' => [
                    'total_today' => $totalDailyToday,
                    'completed_today' => $completedDailyToday,
                    'pending_today' => $pendingDailyToday,
                ],
                'weekly' => [
                    'current_week' => $currentWeek,
                    'current_year' => $currentYear,
                    'total_tasks' => $totalWeekly,
                ],
                'monthly' => [
                    'current_month' => $currentMonth,
                    'current_year' => $currentYear,
                    'total_tasks' => $totalMonthly,
                ],
            ],
        ]);
    }

    /**
     * Restrict activity list queries to the authenticated user's own records
     * and the one-level approval scope. Admins retain company-wide visibility.
     *
     * @param  Builder<Daily|Weekly|Monthly>  $query
     */
    private function applyVisibleUserScope($query, ?User $actor): void
    {
        if ($actor?->role?->name === 'ADMIN') {
            return;
        }

        $visibleUserIds = array_merge(
            [(int) ($actor?->id ?? 0)],
            ApprovalScopeService::getManagedUserIdsOneLevelDown((int) ($actor?->id ?? 0)),
        );

        $query->whereIn('user_id', array_values(array_unique($visibleUserIds)));
    }
}
