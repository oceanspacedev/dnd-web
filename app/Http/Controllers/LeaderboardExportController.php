<?php

namespace App\Http\Controllers;

use App\Exports\LeaderboardExport;
use App\Models\Attendance;
use App\Models\EmployeeReview;
use App\Models\Kpi;
use App\Models\User;
use App\Services\DataAccessService;
use App\Services\KpiScoringService;
use App\Services\LeaderboardScoreService;
use App\Services\PerformanceScoreCalculator;
use App\Services\ScoringRuleService;
use App\Support\Scoring\ScoringRuleData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Maatwebsite\Excel\Facades\Excel;

class LeaderboardExportController extends Controller
{
    public function export(Request $request)
    {
        $actor = $request->user();
        $actor = $actor instanceof User ? $actor : null;
        // null = tanpa batas badan usaha (super admin & non-admin, seperti sebelumnya);
        // selain itu admin per badan usaha: karyawan dengan users.area_id di area_ids,
        // ditambah tim approval line + dirinya sendiri (user_ids).
        $scope = LeaderboardScoreService::viewerScope($actor);

        $periodType = $request->input('period_type', 'month');
        $selectedPeriod = $periodType === 'year'
            ? ($request->year ? $request->year : Date::now()->format('Y'))
            : ($request->month ? $request->month : Date::now()->format('Y-m'));
        $divisionId = $request->division;
        $areaId = $request->area;

        // Parameter area dari klien tidak boleh memperluas cakupan admin per badan usaha;
        // badan usaha tim/dirinya tetap boleh dipilih (hanya menyisakan tim/dirinya di sana).
        abort_if(
            $scope !== null && filled($areaId) && ! in_array((int) $areaId, LeaderboardScoreService::selectableAreaIds($scope), true),
            403,
            'Anda tidak memiliki akses ke area ini.',
        );

        $leaderboardData = [];

        $userQuery = User::with(['role', 'divisi', 'area', 'position']);

        if ($scope !== null) {
            $userQuery->where(function ($scoped) use ($scope): void {
                $scoped->whereIn('area_id', $scope['area_ids'])
                    ->orWhereIn('id', $scope['user_ids']);
            });
        }

        // Apply filters if any
        if ($divisionId) {
            $userQuery->where('divisi_id', $divisionId);
        }

        if ($areaId) {
            $userQuery->where('area_id', $areaId);
        }

        $userIds = (clone $userQuery)->pluck('id')->all();
        $detailScopeUserIds = $this->resolveDetailScopeUserIds($userIds, $actor);
        $includeDetails = count($detailScopeUserIds) > 0;

        /** @var list<ScoringRuleData> $scoringRules versi aturan yang dipakai (untuk judul kolom bobot). */
        $scoringRules = [];

        if ($periodType === 'year') {
            $users = $userQuery->get();
            $monthlySummaryRows = [];

            // Aturan per bulan: tahun yang melewati perubahan aturan memakai aturan masing-masing bulan.
            $rulesByMonth = [];
            for ($month = 1; $month <= 12; $month++) {
                $ym = sprintf('%s-%02d', $selectedPeriod, $month);
                $rulesByMonth[$ym] = ScoringRuleService::forPeriode($ym);
            }
            $scoringRules = array_values($rulesByMonth);

            $kpiSummaryDetails = Kpi::with([
                'kpi_detail',
                'kpi_detail.kpi_description',
                'kpi_category',
                'user',
                'user.divisi',
                'user.area',
                'user.position',
            ])
                ->where('kpi_type_id', 3)
                ->whereYear('date', $selectedPeriod)
                ->whereIn('user_id', $userIds)
                ->get();

            $attendanceSummaryDetails = Attendance::with(['user.divisi', 'user.area'])
                ->whereIn('user_id', $userIds)
                ->where('periode', 'like', $selectedPeriod.'-%')
                ->get();

            $reviewSummaryDetails = EmployeeReview::with(['user.divisi', 'user.area'])
                ->whereIn('user_id', $userIds)
                ->where('periode', 'like', $selectedPeriod.'-%')
                ->get();

            $kpisByUserMonth = $kpiSummaryDetails->groupBy(function ($kpi) {
                return $kpi->user_id.'|'.Date::parse($kpi->date)->format('Y-m');
            });
            $attendanceByUserMonth = $attendanceSummaryDetails->groupBy(function ($attendance) {
                return $attendance->user_id.'|'.$attendance->periode;
            });
            $reviewByUserMonth = $reviewSummaryDetails->groupBy(function ($review) {
                return $review->user_id.'|'.$review->periode;
            });

            foreach ($users as $user) {
                if (DataAccessService::isAdmin($user)) {
                    continue;
                }

                $kpiSum = 0;
                $attendanceSum = 0;
                $activitySum = 0;

                foreach ($rulesByMonth as $ym => $rule) {
                    $key = $user->id.'|'.$ym;

                    $kpiScoreRaw = 0;
                    $kpisForMonth = $kpisByUserMonth->get($key, collect());
                    foreach ($kpisForMonth as $kpi) {
                        $result = KpiScoringService::calculateKpiScore($kpi);
                        $kpiScoreRaw += $result['score'] * 100;
                    }
                    $kpiScoreMonth = KpiScoringService::calculateFinalKpiScore($kpiScoreRaw, $rule);
                    $kpiSum += $kpiScoreMonth;

                    $attendanceGroup = $attendanceByUserMonth->get($key);
                    $attendance = $attendanceGroup ? $attendanceGroup->first() : null;
                    $attendanceScoreMonth = PerformanceScoreCalculator::attendanceFor($attendance, $rule)->score;
                    $attendanceSum += $attendanceScoreMonth;

                    $reviewGroup = $reviewByUserMonth->get($key);
                    $review = $reviewGroup ? $reviewGroup->first() : null;
                    $activityScore = PerformanceScoreCalculator::reviewFor($review, $rule);
                    $activitySum += $activityScore;

                    // Kolom 0-8 tetap; kolom aturan ditambahkan di belakang.
                    $monthlySummaryRows[] = [
                        $ym,
                        $user->employee_id,
                        $user->nama_lengkap,
                        $user->divisi->name ?? '',
                        $user->area->name ?? '',
                        $kpiScoreMonth,
                        $attendanceScoreMonth,
                        $activityScore,
                        $kpiScoreMonth + $attendanceScoreMonth + $activityScore,
                        $rule->name,
                        $rule->kpiWeight,
                        $rule->attendanceWeight,
                        $rule->reviewWeight,
                    ];
                }

                $kpiScore = $kpiSum / 12;
                $attendanceScore = $attendanceSum / 12;
                $activityScore = $activitySum / 12;
                $totalScore = $kpiScore + $attendanceScore + $activityScore;

                $leaderboardData[] = [
                    'user' => $user,
                    'kpiScore' => $kpiScore,
                    'attendanceScore' => $attendanceScore,
                    'activityScore' => $activityScore,
                    'totalScore' => $totalScore,
                ];
            }

            $kpiDetails = $includeDetails
                ? $kpiSummaryDetails->whereIn('user_id', $detailScopeUserIds)->values()
                : collect();
            $attendanceDetails = $includeDetails
                ? $attendanceSummaryDetails->whereIn('user_id', $detailScopeUserIds)->values()
                : collect();
            $reviewDetails = $includeDetails
                ? $reviewSummaryDetails->whereIn('user_id', $detailScopeUserIds)->values()
                : collect();
        } else {
            // Aturan bulan yang diekspor (bukan bulan berjalan).
            $rule = ScoringRuleService::forPeriode($selectedPeriod);
            $scoringRules = [$rule];

            $userQuery->with([
                'attendance' => function ($query) use ($selectedPeriod) {
                    // Kolom skor lengkap (termasuk component_counts) agar komponen baru ikut terhitung.
                    $query->select(['user_id', 'periode', ...Attendance::SCORING_COLUMNS])
                        ->where('periode', $selectedPeriod);
                },
                'employeeReview' => function ($query) use ($selectedPeriod) {
                    $query->select('user_id', 'responsiveness', 'problem_solver', 'helpfulness', 'initiative', 'periode')
                        ->where('periode', $selectedPeriod);
                },
                'kpi' => function ($query) use ($selectedPeriod) {
                    $query->select('id', 'user_id', 'percentage', 'date')
                        ->where('kpi_type_id', 3)
                        ->tap(fn ($kpiQuery) => $this->constrainKpiPeriod($kpiQuery, $selectedPeriod))
                        ->orderBy('date', 'DESC')
                        ->with(['kpi_detail' => function ($query) {
                            $query->whereNotNull('value_result')->where('value_result', '>=', 0);
                        }]);
                },
            ]);

            $userQuery->chunk(100, function ($users) use (&$leaderboardData, $rule) {
                foreach ($users as $user) {
                    if (DataAccessService::isAdmin($user)) {
                        continue;
                    }

                    $kpiScore = 0;

                    foreach ($user->kpi as $kpi) {
                        $result = KpiScoringService::calculateKpiScore($kpi);
                        $kpiScore += $result['score'] * 100;
                    }

                    $kpiScore = KpiScoringService::calculateFinalKpiScore($kpiScore, $rule);
                    $attendanceScore = PerformanceScoreCalculator::attendanceFor($user->attendance, $rule)->score;
                    $activityScore = PerformanceScoreCalculator::reviewFor($user->employeeReview, $rule);

                    $leaderboardData[] = [
                        'user' => $user,
                        'kpiScore' => $kpiScore,
                        'attendanceScore' => $attendanceScore,
                        'activityScore' => $activityScore,
                        'totalScore' => $kpiScore + $attendanceScore + $activityScore,
                    ];
                }
            });

            $kpiDetails = collect();
            $attendanceDetails = collect();
            $reviewDetails = collect();

            if ($includeDetails) {
                $kpiDetails = Kpi::with([
                    'kpi_detail',
                    'kpi_detail.kpi_description',
                    'kpi_category',
                    'user',
                    'user.divisi',
                    'user.area',
                    'user.position',
                ])
                    ->where('kpi_type_id', 3)
                    ->tap(fn ($kpiQuery) => $this->constrainKpiPeriod($kpiQuery, $selectedPeriod))
                    ->whereIn('user_id', $detailScopeUserIds)
                    ->get();

                $attendanceDetails = Attendance::with(['user.divisi', 'user.area'])
                    ->whereIn('user_id', $detailScopeUserIds)
                    ->where('periode', $selectedPeriod)
                    ->get();

                $reviewDetails = EmployeeReview::with(['user.divisi', 'user.area'])
                    ->whereIn('user_id', $detailScopeUserIds)
                    ->where('periode', $selectedPeriod)
                    ->get();
            }
        }

        // Sort leaderboard data by total score
        usort($leaderboardData, function ($a, $b) {
            return $b['totalScore'] <=> $a['totalScore'];
        });

        // Export to Excel
        return Excel::download(
            new LeaderboardExport(
                $leaderboardData,
                $periodType,
                $selectedPeriod,
                $kpiDetails,
                $attendanceDetails,
                $reviewDetails,
                $monthlySummaryRows ?? [],
                $includeDetails,
                $scoringRules,
            ),
            'leaderboard.xlsx'
        );
    }

    protected function resolveDetailScopeUserIds(array $filteredUserIds, ?User $actor): array
    {
        // Admin: seluruh hasil filter. Untuk admin per badan usaha, hasil filter sudah
        // dibatasi ke badan usahanya + tim + dirinya pada query populasi di export().
        if (DataAccessService::isAdmin($actor)) {
            return array_values($filteredUserIds);
        }

        $teamUserIds = $this->getRecursiveSubordinateUserIds((int) $actor?->getKey());

        return array_values(array_intersect($filteredUserIds, $teamUserIds));
    }

    protected function getRecursiveSubordinateUserIds(int $leaderId): array
    {
        $teamUserIds = [];
        $pendingApproverIds = [$leaderId];

        while (true) {
            $directSubordinateIds = User::whereIn('approval_id', $pendingApproverIds)->pluck('id')->all();
            $newSubordinateIds = array_values(array_diff($directSubordinateIds, $teamUserIds));

            if (empty($newSubordinateIds)) {
                break;
            }

            $teamUserIds = array_merge($teamUserIds, $newSubordinateIds);
            $pendingApproverIds = $newSubordinateIds;
        }

        return array_values(array_filter($teamUserIds, function ($userId) use ($leaderId) {
            return (int) $userId !== $leaderId;
        }));
    }

    private function constrainKpiPeriod($query, string $period)
    {
        if (preg_match('/^(?<year>\d{4})-(?<month>0[1-9]|1[0-2])$/', $period, $matches)) {
            return $query
                ->whereYear('date', (int) $matches['year'])
                ->whereMonth('date', (int) $matches['month']);
        }

        if (preg_match('/^\d{4}$/', $period)) {
            return $query->whereYear('date', (int) $period);
        }

        return $query;
    }
}
