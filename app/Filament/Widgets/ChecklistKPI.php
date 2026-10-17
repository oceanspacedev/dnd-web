<?php

namespace App\Filament\Widgets;

use App\Models\Kpi;
use App\Models\KpiDescription;
use App\Models\KpiDetail;
use App\Models\User;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use App\Services\KpiScoringService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Exception;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;

class ChecklistKPI extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.checklist-kpi';

    protected int|string|array $columnSpan = 'full';

    public $month;

    public $user_id;

    public $parent_id;

    public $description;

    public $count_type = 'NON';

    public $value_actual;

    public $kpiDetail;

    public int $updateModalKey = 0;

    public function mount($user_id = null, $month = null): void
    {
        $this->user_id = $user_id ?? null;
        $this->month = $month ?? now()->format('m/Y');
    }

    protected function getKpis()
    {
        $currentMonth = Date::now()->month;
        $currentYear = Date::now()->year;

        // user_id adalah properti publik Livewire (bisa diubah klien): validasi ke daftar user yang diizinkan.
        $users = $this->getUsers();
        $viewedUserId = $this->resolveViewedUserId($users);

        $with = [
            'kpi_detail',
            'kpi_detail.kpi_description',
            'kpi_detail.children',
            'kpi_detail.children.kpi_description',
            'kpi_type',
            'kpi_category',
            'user',
        ];

        $kpisQuery = Kpi::with($with)
            ->where('user_id', Auth::id())
            ->where('kpi_type_id', 3)
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear);

        if ($this->month) {
            try {
                $date = Date::createFromFormat('m/Y', $this->month);
            } catch (Exception $e) {
                try {
                    $date = Date::parse($this->month);
                } catch (Exception $e2) {
                    $date = Date::now();
                }
            }

            $kpisQuery = Kpi::with($with)
                ->where('kpi_type_id', 3)
                ->whereMonth('date', $date->month)
                ->whereYear('date', $date->year);

            $kpisQuery->where('user_id', $viewedUserId);
        }

        $kpis = $kpisQuery->orderBy('date', 'DESC')->get();

        // Group the KPIs by yearly month
        $groupedKpisByYear = $kpis->groupBy(function ($kpi) {
            return CarbonImmutable::parse($kpi->date)->format('Y-m');
        });

        // Group the KPIs by KPI category within each month group
        $groupedKpisByYearAndCategory = [];
        $totalScore = 0;

        foreach ($groupedKpisByYear as $yearMonth => $groupedKpi) {
            $groupedKpiByCategory = $groupedKpi->groupBy('kpi_category.name');

            // Sort the grouped data by category name
            $groupedKpiByCategory = $groupedKpiByCategory->sortBy(function ($kpis, $categoryName) {
                $categoryOrder = ['MAIN JOB', 'ADMINISTRATION', 'REPORTING'];
                $categoryIndex = array_search($categoryName, $categoryOrder);

                return $categoryIndex !== false ? $categoryIndex : count($categoryOrder);
            });

            $groupedKpisByYearAndCategory[$yearMonth] = $groupedKpiByCategory;

            foreach ($groupedKpiByCategory as $categoryName => $kpis) {
                foreach ($kpis as $kpi) {
                    $kpiDetailWithValue = $kpi->kpi_detail->filter(function ($kpiDetail) {
                        return $kpiDetail->value_result !== null && $kpiDetail->value_result >= 0;
                    });

                    $actualCount = $kpiDetailWithValue->sum('value_result');
                    $count = $kpiDetailWithValue->count();

                    $score = 0;
                    if ($count > 0) {
                        $score = ($kpi->percentage / 100) * ($actualCount / $count);
                    }

                    $kpi->actualCount = $actualCount;
                    $kpi->score = $score;

                    $totalScore += $score;
                }
            }
        }

        return [
            'groupedKpis' => $groupedKpisByYearAndCategory,
            'totalScore' => $totalScore,
            'users' => $users,
        ];
    }

    /**
     * User yang checklist-nya ditampilkan. Pilihan di luar daftar yang diizinkan kembali ke diri sendiri.
     *
     * @param  Collection<int, User>  $users
     */
    protected function resolveViewedUserId(Collection $users): int
    {
        $actorId = (int) Auth::id();

        if (blank($this->user_id) || ! is_numeric($this->user_id)) {
            return $actorId;
        }

        $requestedId = (int) $this->user_id;

        if ($requestedId === $actorId || $users->contains(fn (User $user): bool => (int) $user->getKey() === $requestedId)) {
            return $requestedId;
        }

        $this->user_id = null;

        return $actorId;
    }

    /**
     * Pilihan user tampil untuk admin, atasan (termasuk siapa pun yang punya bawahan), dan role selain STAFF
     * seperti sebelumnya; isinya tetap dibatasi getUsers().
     */
    public function canChooseUser(): bool
    {
        $actor = Auth::user();

        return DataAccessService::isAdmin($actor)
            || DataAccessService::isTeamSupervisor($actor)
            || $actor?->role?->name !== 'STAFF';
    }

    /**
     * Tombol ubah checklist hanya untuk KPI milik user yang boleh dilihat aktor di widget ini.
     */
    public function canUpdateKpi($kpi): bool
    {
        return $kpi instanceof Kpi && $this->canManageChecklistOf($kpi);
    }

    /**
     * Cakupan ubah checklist = cakupan lihat (getUsers): super admin semua; admin badan usaha:
     * badan usaha yang diizinkan + tim + diri sendiri, tanpa checklist akun ADMIN lain; lainnya: tim
     * (approval line) + diri sendiri, sama dengan aturan atasan di KpiPolicy.
     */
    protected function canManageChecklistOf(Kpi $kpi): bool
    {
        return DataAccessService::canWriteForUser(Auth::user(), $kpi->ownerForAccessCheck());
    }

    /**
     * Setiap mutasi memakai id dari klien: pastikan pemilik KPI-nya berada dalam cakupan aktor.
     */
    protected function authorizeKpiDetail(KpiDetail $kpiDetail): void
    {
        $kpiDetail->loadMissing('kpi');

        abort_unless($kpiDetail->kpi instanceof Kpi, 404);

        if (! $this->canManageChecklistOf($kpiDetail->kpi)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    /**
     * Deskripsi ekstra task hanya boleh ikut dihapus bila tidak dipakai detail lain (termasuk yang
     * sudah dihapus). Deskripsi KPI adalah master bersama: create by position, Copy KPI, dan API
     * bisa memakai id yang sama untuk karyawan lain, termasuk di badan usaha lain.
     */
    protected function isDescriptionOnlyUsedBy(KpiDetail $extraTask): bool
    {
        return KpiDetail::withTrashed()
            ->where('kpi_description_id', $extraTask->kpi_description_id)
            ->whereKeyNot($extraTask->getKey())
            ->doesntExist();
    }

    protected function getUsers()
    {
        $actor = auth()->user();

        if (DataAccessService::hasGlobalAccess($actor)) {
            // Super admin: semua user kecuali user dengan id 1
            return User::where('id', '<>', 1)
                ->whereNull('deleted_at')
                ->orderBy('nama_lengkap')
                ->get();
        } elseif (DataAccessService::isAreaScopedAdmin($actor)) {
            // Admin badan usaha tertentu: karyawan di badan usaha yang diizinkan + tim + diri sendiri
            return DataAccessService::scopeOwnedRecords(User::whereNull('deleted_at'), $actor, 'users.id')
                ->orderBy('nama_lengkap')
                ->get();
        } else {
            $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
            $allowedUserIds = array_values(array_unique(array_merge([(int) auth()->id()], $managedUserIds)));

            return User::whereNull('deleted_at')
                ->whereIn('id', $allowedUserIds)
                ->orderBy('nama_lengkap')
                ->get();
        }
    }

    public function openUpdateModal($kpiDetailId)
    {
        $kpiDetail = KpiDetail::with(['kpi', 'kpi_description'])->findOrFail($kpiDetailId);
        $this->authorizeKpiDetail($kpiDetail);

        $this->kpiDetail = $kpiDetail;

        if (! $this->ensureKpiDetailEditable($this->kpiDetail)) {
            return;
        }

        $this->value_actual = $this->kpiDetail->value_actual ?? 0;
        $this->updateModalKey++;

        $this->dispatch('open-modal', id: 'updateKpiModal');
    }

    public function openExtraTaskModal($kpiDetailId)
    {
        $parentKpiDetail = KpiDetail::with('kpi')->findOrFail($kpiDetailId);
        $this->authorizeKpiDetail($parentKpiDetail);

        if (! $this->ensureKpiDetailEditable($parentKpiDetail)) {
            return;
        }

        $this->parent_id = $kpiDetailId;
        $this->description = '';
        $this->count_type = 'NON';
        $this->value_actual = 0;

        $this->dispatch('open-modal', id: 'extraTaskModal');
    }

    public function changeKpiStatus($kpiDetailId, $type)
    {
        $kpiDetail = KpiDetail::with('kpi')->findOrFail($kpiDetailId);
        $this->authorizeKpiDetail($kpiDetail);

        if (! $this->ensureKpiDetailEditable($kpiDetail)) {
            return;
        }

        if ($kpiDetail->count_type === 'NON') {
            // Toggle between completed (1) and not completed (0)
            $kpiDetail->value_result = $kpiDetail->value_result ? 0 : 1;
            $kpiDetail->save();
        }
    }

    public function submitUpdateKpi()
    {
        if (! $this->kpiDetail instanceof KpiDetail) {
            return;
        }

        $this->authorizeKpiDetail($this->kpiDetail);
        if (! $this->ensureKpiDetailEditable($this->kpiDetail)) {
            return;
        }

        $this->validate([
            'value_actual' => 'required|numeric',
        ]);

        $this->kpiDetail->value_actual = $this->value_actual;

        // Hitung value_result berdasarkan value_plan dan value_actual
        if ($this->kpiDetail->count_type === 'RESULT') {
            $isNegative = $this->kpiDetail->kpi_description->is_negative ?? false;
            $this->kpiDetail->value_result = KpiScoringService::calculateResultValue(
                $this->kpiDetail->value_plan,
                $this->kpiDetail->value_actual,
                $isNegative
            );
        }

        $this->kpiDetail->save();

        $this->dispatch('close-modal', id: 'updateKpiModal');
    }

    public function updatedCountType($value)
    {
        $this->dispatch('count-type-changed', $value);
    }

    public function submitExtraTask()
    {
        $this->validate([
            'parent_id' => 'required|exists:kpi_details,id',
            'description' => 'required|string|max:255',
            'count_type' => 'required|in:NON,RESULT',
            'value_actual' => 'required_if:count_type,RESULT|nullable|numeric|min:0',
        ]);

        try {
            // Ambil detail parent KPI
            $parentKpiDetail = KpiDetail::with('kpi')->findOrFail($this->parent_id);
            $this->authorizeKpiDetail($parentKpiDetail);

            if (! $this->ensureKpiDetailEditable($parentKpiDetail)) {
                return;
            }

            // Buat deskripsi baru untuk ekstra task
            $kpiDescription = KpiDescription::create([
                'description' => $this->description,
                'kpi_category_id' => $parentKpiDetail->kpi->kpi_category_id,
                'is_negative' => $parentKpiDetail->kpi_description->is_negative ?? false,
                'created_by' => auth()->id(), // Maintaining created_by from original code
            ]);

            // Buat detail KPI untuk ekstra task
            $extraTask = KpiDetail::create([
                'parent_id' => $parentKpiDetail->id,
                'is_extra_task' => 1,
                'value_actual' => $this->count_type === 'RESULT' ? $this->value_actual : null,
                'count_type' => $this->count_type,
                'kpi_description_id' => $kpiDescription->id,
                'kpi_id' => $parentKpiDetail->kpi_id,
                'created_by' => auth()->id(), // Maintaining created_by from original code
            ]);

            // Update nilai Value_Result pada parent
            if ($this->count_type === 'NON') {
                // Jika NON, langsung set Value_Result parent menjadi 1
                $parentKpiDetail->update([
                    'value_result' => 1,
                ]);
            } elseif ($this->count_type === 'RESULT') {
                // Jika RESULT, hitung Value_Result baru berdasarkan nilai actual
                $parentPlanValue = $parentKpiDetail->value_plan ?? 1; // Hindari pembagian dengan 0
                $newActualValue = $parentKpiDetail->value_actual + $this->value_actual;
                $isNegative = $parentKpiDetail->kpi_description->is_negative ?? false;
                $newValueResult = KpiScoringService::calculateResultValue(
                    $parentPlanValue,
                    $newActualValue,
                    $isNegative
                );
                $parentKpiDetail->update([
                    'value_actual' => $newActualValue,
                    'value_result' => $newValueResult,
                ]);
            }

            // Flash message ke session
            session()->flash('success', 'Ekstra task berhasil ditambahkan dan nilai parent diperbarui.');

            // Close the modal (keeping this from the original code)
            $this->dispatch('close-modal', id: 'extraTaskModal');

        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Exception $e) {
            // Flash error ke session
            session()->flash('error', 'Terjadi kesalahan saat menyimpan ekstra task: '.$e->getMessage());
        }
    }

    public function deleteExtraTask($extraTaskId)
    {
        try {
            // Cari data extra task berdasarkan ID
            $extraTask = KpiDetail::with('kpi')->findOrFail($extraTaskId);
            $this->authorizeKpiDetail($extraTask);

            // Pastikan data yang dihapus adalah ekstra task
            if ($extraTask->is_extra_task == 1) {
                // Ambil parent dari extra task
                $parentKpiDetail = KpiDetail::with('kpi')->findOrFail($extraTask->parent_id);
                $this->authorizeKpiDetail($parentKpiDetail);

                if (! $this->ensureKpiDetailEditable($parentKpiDetail)) {
                    return;
                }

                // Kurangi nilai actual parent jika tipe extra task adalah RESULT
                if ($extraTask->count_type === 'RESULT') {
                    $parentActualValue = $parentKpiDetail->value_actual - ($extraTask->value_actual ?? 0);
                    $parentActualValue = max($parentActualValue, 0); // Pastikan tidak negatif
                } else {
                    $parentActualValue = $parentKpiDetail->value_actual; // Tidak ada perubahan untuk NON
                }

                // Hitung ulang value_result untuk parent
                $parentPlanValue = $parentKpiDetail->value_plan ?? 1; // Hindari pembagian dengan 0
                $isNegative = $parentKpiDetail->kpi_description->is_negative ?? false;
                $newValueResult = KpiScoringService::calculateResultValue(
                    $parentPlanValue,
                    $parentActualValue,
                    $isNegative
                );

                // Update parent KPI detail
                $parentKpiDetail->update([
                    'value_actual' => $parentActualValue,
                    'value_result' => $newValueResult,
                ]);

                // Hapus kpi_description terkait, jika ada dan hanya dipakai ekstra task ini
                if ($extraTask->kpi_description_id && $this->isDescriptionOnlyUsedBy($extraTask)) {
                    $extraTask->kpi_description?->delete(); // Hapus kpi_description
                }

                // Hapus data extra task
                $extraTask->delete();

                // Flash message ke session
                session()->flash('success', 'Ekstra task berhasil dihapus dan nilai parent diperbarui.');

                // Optional: For Livewire, you might want to emit an event to refresh the UI
                $this->dispatch('extraTaskDeleted');
            } else {
                session()->flash('error', 'Data bukan ekstra task.');
            }
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Exception $e) {
            session()->flash('error', 'Terjadi kesalahan saat menghapus ekstra task: '.$e->getMessage());
        }
    }

    public function getDeadlineInfo(string $kpiDate): array
    {
        $kpiMonth = Date::parse($kpiDate)->startOfMonth();
        $deadline = $this->resolveChecklistDeadline($kpiMonth);
        $now = Date::now();
        // Semua admin bebas dari batas periode; cakupan record tetap dicek lewat authorizeKpiDetail().
        $isAdmin = DataAccessService::isAdmin(auth()->user());
        $isClosed = ! $isAdmin && $now->gt($deadline);
        $daysLeft = $isClosed ? 0 : (int) max(0, $now->diffInDays($deadline, false));

        return [
            'deadline' => $deadline,
            'daysLeft' => $daysLeft,
            'isClosed' => $isClosed,
            'isAdmin' => $isAdmin,
        ];
    }

    public function canEditKpiDetail($kpiDetail): bool
    {
        if (! $kpiDetail || ! $kpiDetail->kpi || ! $kpiDetail->kpi->date) {
            return false;
        }

        return $this->canEditKpiDate($kpiDetail->kpi->date);
    }

    public function canEditKpiDate($kpiDate): bool
    {
        if (DataAccessService::isAdmin(auth()->user())) {
            return true;
        }

        if (! $kpiDate) {
            return false;
        }

        $kpiMonth = Date::parse($kpiDate)->startOfMonth();
        $deadline = $this->resolveChecklistDeadline($kpiMonth);

        return Date::now()->lessThanOrEqualTo($deadline);
    }

    protected function ensureKpiDetailEditable($kpiDetail): bool
    {
        if ($this->canEditKpiDetail($kpiDetail)) {
            return true;
        }

        $kpiMonth = Date::parse($kpiDetail->kpi->date)->startOfMonth();
        $deadline = $this->resolveChecklistDeadline($kpiMonth);

        Notification::make()
            ->title('Periode checklist sudah ditutup')
            ->body('Pengisian KPI '.$kpiMonth->format('F Y').' hanya sampai '.$deadline->format('d M Y').'.')
            ->danger()
            ->send();

        return false;
    }

    protected function resolveChecklistDeadline(Carbon $kpiMonth): Carbon
    {
        return $kpiMonth
            ->copy()
            ->endOfMonth()
            ->addDays($this->getChecklistLockDays())
            ->endOfDay();
    }

    protected function getChecklistLockDays(): int
    {
        return max(0, (int) config('kpi.checklist_lock_days', 5));
    }
}
