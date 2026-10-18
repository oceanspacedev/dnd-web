<?php

namespace App\Filament\Widgets;

use App\Models\Area;
use App\Models\Attendance;
use App\Models\Divisi;
use App\Models\User;
use App\Services\KpiScoringService;
use App\Services\LeaderboardScoreService;
use App\Services\PerformanceScoreCalculator;
use App\Services\ScoringRuleService;
use App\Support\Periode;
use App\Support\Scoring\ScoringRuleData;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Locked;

class LeaderboardKPI extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.leaderboard-kpi';

    protected int|string|array $columnSpan = 'full';

    #[Locked]
    public $user_id;

    public $month;

    public $area;

    public $division;

    /** @var list<int>|null */
    protected ?array $selectableAreaIdsMemo = null;

    public function mount($user_id = null, $month = null): void
    {
        $this->user_id = $user_id;
        $this->month = $month ?? now()->format('Y-m');
        $this->area = '';
        $this->division = '';
    }

    protected function getAreas()
    {
        $scope = $this->viewerScope();

        if ($scope === null) {
            return Area::all();
        }

        // Admin per badan usaha hanya bisa memilih badan usaha yang diizinkan
        // ditambah badan usaha tempat tim/dirinya berada.
        return Area::query()->whereIn('id', $this->selectableAreaIds($scope))->get();
    }

    /**
     * Area diganti: divisi terpilih yang tidak ada di daftar divisi Area baru dikosongkan, supaya tidak ada
     * filter divisi tersembunyi (dropdown tidak lagi menampilkannya, tetapi query dan export masih memakainya).
     */
    public function updatedArea(): void
    {
        $division = is_scalar($this->division) ? (string) $this->division : '';

        if ($division === '') {
            return;
        }

        if (! ctype_digit($division) || ! $this->getDivisions()->contains(fn (Divisi $divisi): bool => (int) $divisi->getKey() === (int) $division)) {
            $this->division = '';
        }
    }

    protected function getDivisions()
    {
        $scope = $this->viewerScope();

        if ($scope !== null) {
            $this->enforceFilterScope();

            return $this->scopedDivisionsQuery($scope)->get();
        }

        $area = is_scalar($this->area) ? (string) $this->area : '';

        if ($area !== '' && ctype_digit($area)) {
            // Divisi yang dipakai karyawan di badan usaha terpilih (users.area_id, bukan divisis.area_id).
            return Divisi::query()
                ->whereIn('id', User::query()
                    ->select('divisi_id')
                    ->whereNotNull('divisi_id')
                    ->where('area_id', (int) $area))
                ->get();
        }

        return Divisi::all();
    }

    /**
     * Cakupan leaderboard viewer: null = seluruh badan usaha (super admin & non-admin);
     * selain itu admin per badan usaha (area_ids) + tim approval line dan dirinya (user_ids).
     *
     * @return array{area_ids: list<int>, user_ids: list<int>}|null
     */
    protected function viewerScope(): ?array
    {
        $viewer = auth()->user();

        return LeaderboardScoreService::viewerScope($viewer instanceof User ? $viewer : null);
    }

    /**
     * @param  array{area_ids: list<int>, user_ids: list<int>}  $scope
     * @return list<int>
     */
    protected function selectableAreaIds(array $scope): array
    {
        // Memo per request (properti protected tidak ikut state Livewire).
        return $this->selectableAreaIdsMemo ??= LeaderboardScoreService::selectableAreaIds($scope);
    }

    /**
     * Divisi yang berisi karyawan terlihat di badan usaha terpilih (atau semua badan usaha yang diizinkan),
     * termasuk divisi tim/dirinya. Pakai users.area_id, bukan divisis.area_id, karena keduanya bisa berbeda.
     *
     * @param  array{area_ids: list<int>, user_ids: list<int>}  $scope
     * @return Builder<Divisi>
     */
    protected function scopedDivisionsQuery(array $scope): Builder
    {
        $selectedAreaId = filled($this->area) ? (int) $this->area : null;
        $areaIds = $selectedAreaId !== null
            ? array_values(array_intersect([$selectedAreaId], $scope['area_ids']))
            : $scope['area_ids'];

        $members = User::query()
            ->select('divisi_id')
            ->whereNotNull('divisi_id')
            ->where(function (Builder $visible) use ($areaIds, $scope, $selectedAreaId): void {
                $visible->whereIn('area_id', $areaIds)
                    ->orWhere(function (Builder $team) use ($scope, $selectedAreaId): void {
                        $team->whereIn('id', $scope['user_ids'])
                            ->when($selectedAreaId !== null, fn (Builder $query) => $query->where('area_id', $selectedAreaId));
                    });
            });

        return Divisi::query()->whereIn('id', $members);
    }

    /**
     * $area dan $division adalah prop publik Livewire yang bisa diubah klien:
     * validasi ulang terhadap cakupan admin per badan usaha, nilai di luar cakupan dikosongkan.
     */
    protected function enforceFilterScope(): void
    {
        // Nilai bukan skalar (mis. array dari payload yang diubah) dikosongkan untuk semua viewer.
        if ($this->area !== null && ! is_scalar($this->area)) {
            $this->area = '';
        }

        if ($this->division !== null && ! is_scalar($this->division)) {
            $this->division = '';
        }

        $scope = $this->viewerScope();

        if ($scope === null) {
            return;
        }

        $area = (string) ($this->area ?? '');

        if ($area !== '' && (! ctype_digit($area) || ! in_array((int) $area, $this->selectableAreaIds($scope), true))) {
            $this->area = '';
        }

        $division = (string) ($this->division ?? '');

        if ($division !== '' && (! ctype_digit($division) || ! $this->scopedDivisionsQuery($scope)->whereKey((int) $division)->exists())) {
            $this->division = '';
        }
    }

    /**
     * Bulan yang ditampilkan ('Y-m'); nilai tidak valid dari klien => bulan berjalan.
     */
    protected function selectedPeriod(): string
    {
        return is_string($this->month) && Periode::isValid($this->month)
            ? $this->month
            : Periode::current();
    }

    /**
     * Versi aturan penilaian bulan yang ditampilkan (bobot untuk judul kolom di blade).
     */
    protected function scoringRule(): ScoringRuleData
    {
        return ScoringRuleService::forPeriode($this->selectedPeriod());
    }

    protected function weightLabel(float $weight): string
    {
        return LeaderboardScoreService::weightLabel($weight);
    }

    protected function getLeaderboardData()
    {
        $this->enforceFilterScope();

        $period = $this->selectedPeriod();
        $area = (string) ($this->area ?? '');
        $division = (string) ($this->division ?? '');
        $ttl = max(1, (int) config('kpi.cache_ttl_seconds.leaderboard', 60));

        // Kunci cache tanpa cakupan viewer (dipakai bersama); batasi cakupan setelah dibaca.
        // Versi cache aturan ikut di kunci agar perubahan aturan/komponen langsung terlihat.
        $rows = Cache::remember(
            "leaderboard:panel:{$period}:{$area}:{$division}:r".ScoringRuleService::cacheVersion(),
            $ttl,
            fn (): array => $this->computeLeaderboardData($period),
        );

        $scope = $this->viewerScope();

        if ($scope === null) {
            return $rows;
        }

        $allowedAreas = array_flip($scope['area_ids']);
        $allowedUsers = array_flip($scope['user_ids']);

        // Peringkat di blade = urutan array, jadi array_values() sekaligus menomori ulang 1..N.
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset($allowedUsers[(int) $row['user']->id])
                || isset($allowedAreas[(int) $row['user']->area_id]),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function computeLeaderboardData(string $period): array
    {
        $periodStart = Date::createFromFormat('!Y-m', $period)->startOfMonth();
        $periodEnd = $periodStart->copy()->addMonth();

        $query = User::query()
            ->select(['id', 'nama_lengkap', 'divisi_id', 'area_id'])
            ->with([
                'divisi:id,name',
                'area:id,name',
                'kpi' => function ($query) use ($periodStart, $periodEnd) {
                    $query->select('id', 'user_id', 'percentage', 'date')
                        ->where('kpi_type_id', 3)
                        ->where('date', '>=', $periodStart)
                        ->where('date', '<', $periodEnd)
                        ->orderBy('date', 'DESC')
                        ->with(['kpi_detail' => function ($query) {
                            $query->select('id', 'kpi_id', 'value_result')
                                ->whereNotNull('value_result')
                                ->where('value_result', '>=', 0);
                        }]);
                },
                'attendance' => function ($query) use ($period) {
                    // Kolom skor lengkap (termasuk component_counts) agar komponen baru ikut terhitung.
                    $query->select(['user_id', 'periode', ...Attendance::SCORING_COLUMNS])
                        ->where('periode', $period);
                },
                'employeeReview' => function ($query) use ($period) {
                    $query->select('user_id', 'responsiveness', 'problem_solver', 'helpfulness', 'initiative', 'periode')
                        ->where('periode', $period);
                },
            ])
            ->withSum([
                'cutpoint as period_cutpoint' => fn ($query) => $query->where('periode', $period),
            ], 'point');

        if ($this->area) {
            $query->where('area_id', $this->area);
        }

        if ($this->division) {
            $query->where('divisi_id', $this->division);
        }

        $users = $query->get();
        // Aturan bulan yang dihitung (bukan bulan berjalan); memo per request, tanpa query per baris.
        $rule = ScoringRuleService::forPeriode($period);
        $leaderboardData = [];

        foreach ($users as $user) {
            $kpiScore = $this->calculateKPIScore($user, $rule);
            $attendanceScore = $this->calculateAttendanceScore($user, $rule);
            $activityScore = $this->calculateActivityScore($user, $rule);

            $totalScore = ($kpiScore + $attendanceScore + $activityScore);

            $cutpointValue = (int) ($user->period_cutpoint ?? 0);
            $totalScore = max(0, $totalScore - $cutpointValue);

            $leaderboardData[] = [
                'user' => $user,
                'kpiScore' => $kpiScore,
                'attendanceScore' => $attendanceScore,
                'activityScore' => $activityScore,
                'totalScore' => $totalScore,
                'cutpoint' => $cutpointValue,
            ];
        }

        return collect($leaderboardData)->sortByDesc('totalScore')->values()->all();
    }

    protected function calculateKPIScore($user, ScoringRuleData $rule)
    {
        $kpiScore = 0;

        foreach ($user->kpi as $kpi) {
            $result = KpiScoringService::calculateKpiScore($kpi);
            $kpiScore += $result['score'] * 100;
        }

        return KpiScoringService::calculateFinalKpiScore($kpiScore, $rule);
    }

    protected function calculateAttendanceScore($user, ScoringRuleData $rule)
    {
        return PerformanceScoreCalculator::attendanceFor($user->attendance, $rule)->score;
    }

    protected function calculateActivityScore($user, ScoringRuleData $rule)
    {
        return PerformanceScoreCalculator::reviewFor($user->employeeReview, $rule);
    }
}
