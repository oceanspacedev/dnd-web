<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Attendance;
use App\Models\Divisi;
use App\Models\EmployeeReview;
use App\Models\User;
use App\Support\Periode;
use App\Support\Scoring\ScoringRuleData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class LeaderboardScoreService
{
    /**
     * Populasi leaderboard menurut viewer.
     *
     * - null  => tanpa batas badan usaha: super admin, dan non-admin (leaderboard perusahaan
     *            untuk gamifikasi tetap seperti sebelumnya).
     * - int[] => admin per badan usaha: badan usaha yang diizinkan (users.area_id). Ini hanya
     *            bagian badan usaha; populasi lengkap (+ tim + dirinya) ada di viewerScope().
     *
     * @return array<int>|null
     */
    public static function viewerAreaScope(?User $viewer): ?array
    {
        if (! DataAccessService::isAreaScopedAdmin($viewer)) {
            return null;
        }

        return DataAccessService::allowedAreaIds($viewer) ?? [];
    }

    /**
     * Cakupan leaderboard lengkap menurut viewer (areas ∪ tim ∪ diri sendiri, sama dengan modul lain).
     *
     * - null => tanpa batas (lihat viewerAreaScope()).
     * - area_ids => badan usaha yang diizinkan (users.area_id).
     * - user_ids => tim approval line (ApprovalScopeService) + viewer sendiri, di badan usaha mana pun.
     *
     * Hanya admin per badan usaha yang memicu query tim; viewer lain tidak menambah query.
     *
     * @return array{area_ids: list<int>, user_ids: list<int>}|null
     */
    public static function viewerScope(?User $viewer): ?array
    {
        $areaIds = self::viewerAreaScope($viewer);

        if ($areaIds === null || ! $viewer instanceof User) {
            return null;
        }

        $viewerId = (int) $viewer->getKey();
        $userIds = ApprovalScopeService::getManagedUserIdsOneLevelDown($viewerId);
        $userIds[] = $viewerId;

        return [
            'area_ids' => array_values(array_unique(array_map(static fn ($id): int => (int) $id, $areaIds))),
            'user_ids' => array_values(array_unique(array_map(static fn ($id): int => (int) $id, $userIds))),
        ];
    }

    /**
     * Badan usaha yang boleh dipakai sebagai filter oleh viewer bercakupan:
     * badan usaha yang diizinkan + badan usaha tempat tim/dirinya berada.
     *
     * @param  array{area_ids: list<int>, user_ids: list<int>}  $scope
     * @return list<int>
     */
    public static function selectableAreaIds(array $scope): array
    {
        $memberAreaIds = $scope['user_ids'] === []
            ? []
            : User::query()
                ->whereIn('id', $scope['user_ids'])
                ->whereNotNull('area_id')
                ->distinct()
                ->pluck('area_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

        return array_values(array_unique(array_merge($scope['area_ids'], $memberAreaIds)));
    }

    /**
     * Ranked API scores for a period. User models are not loaded until hydrateUsers().
     *
     * Cache dipakai bersama semua viewer (kunci tanpa cakupan); pembatasan cakupan
     * diterapkan setelah cache dibaca lalu peringkat dinomori ulang 1..N.
     *
     * Cakupan aktif bila salah satu dari $allowedAreaIds / $allowedUserIds tidak null:
     * baris dipertahankan bila area_id ada di $allowedAreaIds ATAU user_id ada di $allowedUserIds.
     * Filter $areaId di luar $allowedAreaIds hanya menyisakan $allowedUserIds di badan usaha itu.
     *
     * @param  array<int>|null  $allowedAreaIds  null (bersama $allowedUserIds null) = seluruh badan usaha.
     * @param  array<int>|null  $allowedUserIds  tim + viewer sendiri (lihat viewerScope()).
     * @return list<array<string, mixed>>
     */
    public function rankedScores(string $periode, ?int $areaId = null, ?int $divisiId = null, ?string $search = null, ?array $allowedAreaIds = null, ?array $allowedUserIds = null): array
    {
        $scoped = $allowedAreaIds !== null || $allowedUserIds !== null;
        $areaLookup = array_flip(array_map(static fn ($id): int => (int) $id, $allowedAreaIds ?? []));
        $userLookup = array_flip(array_map(static fn ($id): int => (int) $id, $allowedUserIds ?? []));

        if ($scoped) {
            // Filter area di luar cakupan tidak boleh memperluas populasi.
            $areaOutsideScope = $areaId !== null && ! isset($areaLookup[$areaId]);

            if ($userLookup === [] && ($areaLookup === [] || $areaOutsideScope)) {
                return [];
            }
        }

        $rows = Cache::remember(
            $this->cacheKey($periode, $areaId, $divisiId),
            $this->ttl(),
            fn (): array => $this->computeRankedScores($periode, $areaId, $divisiId),
        );

        $renumber = false;

        if ($scoped) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => isset($userLookup[(int) $row['user_id']])
                    || ($row['area_id'] !== null && isset($areaLookup[(int) $row['area_id']])),
            ));
            $renumber = true;
        }

        $search = is_string($search) ? trim($search) : '';

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter(
                $rows,
                function (array $row) use ($needle): bool {
                    return str_contains(mb_strtolower((string) $row['nama_lengkap']), $needle)
                        || str_contains(mb_strtolower((string) $row['username']), $needle);
                },
            ));
            $renumber = true;
        }

        if (! $renumber) {
            return $rows;
        }

        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function hydrateUsers(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $users = User::query()
            ->select(['id', 'nama_lengkap', 'username', 'area_id', 'divisi_id', 'position_id'])
            ->with(['divisi:id,name', 'area:id,name', 'position:id,name'])
            ->whereIn('id', array_column($rows, 'user_id'))
            ->get()
            ->keyBy('id');

        foreach ($rows as &$row) {
            $row['user'] = $users->get($row['user_id']);
        }
        unset($row);

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['user'] !== null,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{name: string, employee_count: int, average_score: float, highest_score: float, lowest_score: float}>
     */
    public function groupAverages(array $rows, string $group): array
    {
        $idKey = $group === 'area' ? 'area_id' : 'divisi_id';
        $names = $group === 'area'
            ? Area::query()->pluck('name', 'id')
            : Divisi::query()->pluck('name', 'id');

        $buckets = [];
        foreach ($rows as $row) {
            $id = $row[$idKey] ?? null;
            $label = $id ? (string) ($names[$id] ?? 'Unassigned') : 'Unassigned';
            $buckets[$label][] = (float) $row['total_score'];
        }

        $stats = [];
        foreach ($buckets as $name => $values) {
            $stats[] = [
                'name' => $name,
                'employee_count' => count($values),
                'average_score' => round(array_sum($values) / count($values), 2),
                'highest_score' => round(max($values), 2),
                'lowest_score' => round(min($values), 2),
            ];
        }

        usort($stats, fn (array $a, array $b): int => $b['average_score'] <=> $a['average_score']);

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function computeRankedScores(string $periode, ?int $areaId, ?int $divisiId): array
    {
        $periodStart = Date::createFromFormat('!Y-m', $periode)->startOfMonth();
        $periodEnd = $periodStart->copy()->addMonth();

        $users = User::query()
            ->select(['id', 'nama_lengkap', 'username', 'area_id', 'divisi_id', 'position_id'])
            ->whereNull('deleted_at')
            ->when($areaId, fn ($query) => $query->where('area_id', $areaId))
            ->when($divisiId, fn ($query) => $query->where('divisi_id', $divisiId))
            ->get();

        if ($users->isEmpty()) {
            return [];
        }

        /** @var Collection<int, User> $users */
        $userIds = $users->pluck('id')->all();

        $rawKpiScores = DB::table('kpis')
            ->join('kpi_details', 'kpi_details.kpi_id', '=', 'kpis.id')
            ->whereIn('kpis.user_id', $userIds)
            ->whereNull('kpis.deleted_at')
            ->whereNull('kpi_details.deleted_at')
            ->where('kpis.date', '>=', $periodStart)
            ->where('kpis.date', '<', $periodEnd)
            ->selectRaw('kpis.user_id, COALESCE(SUM(kpi_details.value_result), 0) as raw_score')
            ->groupBy('kpis.user_id')
            ->pluck('raw_score', 'kpis.user_id');

        // Versi aturan penilaian periode ini (memo per request, tanpa query per baris).
        $rule = ScoringRuleService::forPeriode($periode);

        // Kolom skor lengkap (termasuk component_counts) agar komponen baru seperti izin/mangkir ikut terhitung.
        $attendances = Attendance::query()
            ->whereIn('user_id', $userIds)
            ->where('periode', $periode)
            ->get(['user_id', ...Attendance::SCORING_COLUMNS])
            ->keyBy('user_id');

        $reviews = EmployeeReview::query()
            ->whereIn('user_id', $userIds)
            ->where('periode', $periode)
            ->get(['user_id', 'responsiveness', 'problem_solver', 'helpfulness', 'initiative'])
            ->keyBy('user_id');

        $leaderboard = [];

        foreach ($users as $user) {
            $rawKpiScore = (float) ($rawKpiScores->get($user->id) ?? 0);
            $kpiScore = KpiScoringService::calculateFinalKpiScore($rawKpiScore, $rule);
            $attendanceScore = PerformanceScoreCalculator::attendanceFor($attendances->get($user->id), $rule)->score;
            $reviewScore = PerformanceScoreCalculator::reviewFor($reviews->get($user->id), $rule);

            $totalScore = $kpiScore + $attendanceScore + $reviewScore;

            // Nama kunci *_70pct / *_15pct dipertahankan untuk klien lama; nilainya mengikuti bobot aturan periode.
            $leaderboard[] = [
                'user_id' => (int) $user->id,
                'nama_lengkap' => (string) $user->nama_lengkap,
                'username' => (string) $user->username,
                'area_id' => $user->area_id ? (int) $user->area_id : null,
                'divisi_id' => $user->divisi_id ? (int) $user->divisi_id : null,
                'position_id' => $user->position_id ? (int) $user->position_id : null,
                'kpi_raw' => $rawKpiScore,
                'kpi_score_70pct' => $kpiScore,
                'attendance_score_15pct' => $attendanceScore,
                'review_score_15pct' => $reviewScore,
                'total_score' => $totalScore,
                'grade' => $this->grade($totalScore),
                'scoring_rule_id' => $rule->id,
            ];
        }

        usort($leaderboard, fn (array $a, array $b): int => $b['total_score'] <=> $a['total_score']);

        foreach ($leaderboard as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        return $leaderboard;
    }

    private function grade(float $totalScore): string
    {
        return match (true) {
            $totalScore >= 85 => 'A (Istimewa / Outstanding)',
            $totalScore >= 75 => 'B (Baik / Good)',
            $totalScore >= 60 => 'C (Cukup / Satisfactory)',
            $totalScore >= 50 => 'D (Kurang / Needs Improvement)',
            default => 'E (Sangat Kurang / Poor)',
        };
    }

    /**
     * Label bobot untuk judul kolom, mis. 70.0 => '70', 62.5 => '62.5'. Beberapa versi aturan dengan bobot
     * berbeda (export tahunan yang melewati perubahan aturan) => '60/70'.
     *
     * @param  float|list<float>  $weights
     */
    public static function weightLabel(float|array $weights): string
    {
        $labels = [];

        foreach ((array) $weights as $weight) {
            $label = rtrim(rtrim(number_format((float) $weight, 2, '.', ''), '0'), '.');
            $labels[$label] = (float) $weight;
        }

        asort($labels);

        return implode('/', array_keys($labels));
    }

    /**
     * Ringkasan versi aturan untuk respons API (metadata tambahan, bukan pengganti kunci lama).
     *
     * @return array{id: int|null, name: string, effective_from: string, kpi_weight: float, attendance_weight: float, review_weight: float, review_max_points: int}
     */
    public static function ruleSummary(ScoringRuleData $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'effective_from' => $rule->effectiveFrom,
            'kpi_weight' => $rule->kpiWeight,
            'attendance_weight' => $rule->attendanceWeight,
            'review_weight' => $rule->reviewWeight,
            'review_max_points' => $rule->reviewMaxPoints,
        ];
    }

    /**
     * Periode aturan untuk nilai yang menggabungkan beberapa bulan sekaligus (ringkasan KPI seluruh waktu atau
     * satu tahun, evaluasi terpadu dengan periode yang bukan 'Y-m'). Dipilih dari data, bukan dari tanggal hari
     * ini, agar nilai data lama tidak berubah seiring waktu:
     * - kelompok kandidat dicoba berurutan; periode valid terakhir dari kelompok pertama yang berisi data dipakai;
     * - tanpa data sama sekali (nilai 0 di aturan mana pun) => bulan berjalan;
     * - bila tahun 'YYYY' diminta, hasilnya dijepit ke Januari–Desember tahun itu.
     *
     * @param  list<iterable<mixed>>  $candidateGroups  nilai yang diterima Periode::normalize() (periode, tanggal KPI)
     */
    public static function dataRulePeriode(array $candidateGroups, ?string $year = null): string
    {
        $periode = null;

        foreach ($candidateGroups as $candidates) {
            foreach ($candidates as $value) {
                $normalized = Periode::normalize($value);

                if ($normalized !== null && ($periode === null || strcmp($normalized, $periode) > 0)) {
                    $periode = $normalized;
                }
            }

            if ($periode !== null) {
                break;
            }
        }

        $periode ??= Periode::current();

        if ($year === null || preg_match('/^\d{4}$/D', $year) !== 1) {
            return $periode;
        }

        if (strcmp($periode, $year.'-01') < 0) {
            return $year.'-01';
        }

        return strcmp($periode, $year.'-12') > 0 ? $year.'-12' : $periode;
    }

    /**
     * Versi cache aturan ikut di kunci: aturan/komponen yang berubah langsung terlihat tanpa menunggu TTL.
     */
    private function cacheKey(string $periode, ?int $areaId, ?int $divisiId): string
    {
        return 'leaderboard:api:'.$periode.':'.($areaId ?? 'all').':'.($divisiId ?? 'all').':r'.ScoringRuleService::cacheVersion();
    }

    private function ttl(): int
    {
        return max(1, (int) config('kpi.cache_ttl_seconds.leaderboard', 60));
    }
}
