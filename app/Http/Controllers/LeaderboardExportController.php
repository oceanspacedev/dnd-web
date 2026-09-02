<?php

namespace App\Http\Controllers;

use App\Exports\LeaderboardExport;
use App\Models\Attendance;
use App\Models\EmployeeReview;
use App\Models\Kpi;
use App\Models\User;
use App\Services\KpiScoringService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class LeaderboardExportController extends Controller
{
    public function export(Request $request)
    {
        $periodType = $request->input('period_type', 'month');
        $selectedPeriod = $periodType === 'year'
            ? ($request->year ? $request->year : Carbon::now()->format('Y'))
            : ($request->month ? $request->month : Carbon::now()->format('Y-m'));
        $divisionId = $request->division;
        $areaId = $request->area;

        $leaderboardData = [];

        $userQuery = User::with(['role', 'divisi', 'area', 'position']);

        // Apply filters if any
        if ($divisionId) {
            $userQuery->where('divisi_id', $divisionId);
        }

        if ($areaId) {
            $userQuery->where('area_id', $areaId);
        }

        $userIds = (clone $userQuery)->pluck('id')->all();
        $detailScopeUserIds = $this->resolveDetailScopeUserIds($userIds);
        $includeDetails = count($detailScopeUserIds) > 0;

        if ($periodType === 'year') {
            $users = $userQuery->get();
            $monthlySummaryRows = [];

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
                ->where('periode', 'like', $selectedPeriod . '-%')
                ->get();

            $reviewSummaryDetails = EmployeeReview::with(['user.divisi', 'user.area'])
                ->whereIn('user_id', $userIds)
                ->where('periode', 'like', $selectedPeriod . '-%')
                ->get();

            $kpisByUserMonth = $kpiSummaryDetails->groupBy(function ($kpi) {
                return $kpi->user_id . '|' . Carbon::parse($kpi->date)->format('Y-m');
            });
            $attendanceByUserMonth = $attendanceSummaryDetails->groupBy(function ($attendance) {
                return $attendance->user_id . '|' . $attendance->periode;
            });
            $reviewByUserMonth = $reviewSummaryDetails->groupBy(function ($review) {
                return $review->user_id . '|' . $review->periode;
            });

            foreach ($users as $user) {
                if ($user->role && $user->role->name === 'ADMIN') {
                    continue;
                }

                $kpiSum = 0;
                $attendanceSum = 0;
                $activitySum = 0;

                for ($month = 1; $month <= 12; $month++) {
                    $ym = sprintf('%s-%02d', $selectedPeriod, $month);
                    $key = $user->id . '|' . $ym;

                    $kpiScoreRaw = 0;
                    $kpisForMonth = $kpisByUserMonth->get($key, collect());
                    foreach ($kpisForMonth as $kpi) {
                        $result = KpiScoringService::calculateKpiScore($kpi);
                        $kpiScoreRaw += $result['score'] * 100;
                    }
                    $kpiSum += KpiScoringService::calculateFinalKpiScore($kpiScoreRaw);

                    $attendanceGroup = $attendanceByUserMonth->get($key);
                    $attendance = $attendanceGroup ? $attendanceGroup->first() : null;
                    $lateLess30 = $attendance?->late_less_30 ?? 0;
                    $lateMore30 = $attendance?->late_more_30 ?? 0;
                    $sickDays = $attendance?->sick_days ?? 0;
                    $workDays = $attendance?->work_days ?? 0;
                    $initialAttendanceAchv = ($workDays > 0)
                        ? ($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays * 100
                        : 0;
                    $penalty = ($lateLess30 * 1) + ($lateMore30 * 3) + ($sickDays * 5);
                    $finalAttendanceAchv = max(0, $initialAttendanceAchv - $penalty);
                    $attendanceSum += ($finalAttendanceAchv / 100) * 15;

                    $reviewGroup = $reviewByUserMonth->get($key);
                    $review = $reviewGroup ? $reviewGroup->first() : null;
                    $responsiveness = $review?->responsiveness ?? 0;
                    $problemSolver = $review?->problem_solver ?? 0;
                    $helpfulness = $review?->helpfulness ?? 0;
                    $initiative = $review?->initiative ?? 0;
                    $activityScore = ($responsiveness + $problemSolver + $helpfulness + $initiative) / 20 * 100 * 0.15;
                    $activitySum += $activityScore;

                    $kpiScoreMonth = KpiScoringService::calculateFinalKpiScore($kpiScoreRaw);
                    $attendanceScoreMonth = ($finalAttendanceAchv / 100) * 15;
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
            $userQuery->with([
                'attendance' => function ($query) use ($selectedPeriod) {
                    $query->select(
                        'user_id',
                        'late_less_30',
                        'late_more_30',
                        'sick_days',
                        'work_days',
                        'periode'
                    )
                        ->where('periode', $selectedPeriod);
                },
                'employeeReview' => function ($query) use ($selectedPeriod) {
                    $query->select('user_id', 'responsiveness', 'problem_solver', 'helpfulness', 'initiative', 'periode')
                        ->where('periode', $selectedPeriod);
                },
                'kpi' => function ($query) use ($selectedPeriod) {
                    $query->select('id', 'user_id', 'percentage', 'date')
                        ->where('kpi_type_id', 3)
                        ->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$selectedPeriod])
                        ->orderBy('date', 'DESC')
                        ->with(['kpi_detail' => function ($query) {
                            $query->whereNotNull('value_result')->where('value_result', '>=', 0);
                        }]);
                }
            ]);

            $userQuery->chunk(100, function ($users) use (&$leaderboardData) {
                foreach ($users as $user) {
                    if ($user->role && $user->role->name === 'ADMIN') {
                        continue;
                    }

                    $kpiScore = 0;
                    $attendanceScore = 0;
                    $activityScore = 0;
                    $totalScore = 0;

                    foreach ($user->kpi as $kpi) {
                        $result = KpiScoringService::calculateKpiScore($kpi);
                        $kpiScore += $result['score'] * 100;
                    }

                    $kpiScore = KpiScoringService::calculateFinalKpiScore($kpiScore);
                    $totalScore += $kpiScore;

                    if ($user->attendance !== null) {
                        $attendance = $user->attendance;
                        $lateLess30 = $attendance->late_less_30 ?? 0;
                        $lateMore30 = $attendance->late_more_30 ?? 0;
                        $sickDays = $attendance->sick_days ?? 0;
                        $workDays = $attendance->work_days ?? 0;

                        $initialAttendanceAchv = ($workDays > 0)
                            ? ($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays * 100
                            : 0;

                        $penalty = ($lateLess30 * 1) + ($lateMore30 * 3) + ($sickDays * 5);
                        $finalAttendanceAchv = max(0, $initialAttendanceAchv - $penalty);
                        $attendanceScore = ($finalAttendanceAchv / 100) * 15;
                        $totalScore += $attendanceScore;
                    }

                    if ($user->employeeReview !== null) {
                        $review = $user->employeeReview;
                        $responsiveness = $review->responsiveness ?? 0;
                        $problemSolver = $review->problem_solver ?? 0;
                        $helpfulness = $review->helpfulness ?? 0;
                        $initiative = $review->initiative ?? 0;
                        $activityScore = ($responsiveness + $problemSolver + $helpfulness + $initiative) / 20 * 100 * 0.15;
                        $totalScore += $activityScore;
                    }

                    $leaderboardData[] = [
                        'user' => $user,
                        'kpiScore' => $kpiScore,
                        'attendanceScore' => $attendanceScore,
                        'activityScore' => $activityScore,
                        'totalScore' => $totalScore,
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
                    ->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$selectedPeriod])
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
                $includeDetails
            ),
            'leaderboard.xlsx'
        );
    }

    protected function resolveDetailScopeUserIds(array $filteredUserIds): array
    {
        if (auth()->user()->role_id == 1) {
            return array_values($filteredUserIds);
        }

        $teamUserIds = $this->getRecursiveSubordinateUserIds((int) auth()->id());

        return array_values(array_intersect($filteredUserIds, $teamUserIds));
    }

    protected function getRecursiveSubordinateUserIds(int $leaderId): array
    {
        $teamUserIds = [];
        $pendingApproverIds = [$leaderId];

        while (!empty($pendingApproverIds)) {
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
}
