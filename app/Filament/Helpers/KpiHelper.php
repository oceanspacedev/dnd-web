<?php

namespace App\Filament\Helpers;

use App\Models\User;
use App\Models\Kpi;
use App\Models\Area;
use App\Models\Divisi;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

class KpiHelper
{
    public static function getFormattedMonth($month, $targetFormat = 'Y-m')
    {
        if (empty($month)) {
            return now()->format($targetFormat);
        }
        
        // Detect format
        if (strpos($month, '/') !== false) {
            // Format m/Y
            return Carbon::createFromFormat('m/Y', $month)->format($targetFormat);
        } elseif (strpos($month, '-') !== false) {
            // Format Y-m
            return Carbon::createFromFormat('Y-m', $month)->format($targetFormat);
        }
        
        // Default, return as is
        return $month;
    }
    
    public static function getKpisData($month, $userId = null)
    {
        $month = self::getFormattedMonth($month, 'm/Y');
        $date = Carbon::createFromFormat('m/Y', $month);
        
        $kpisQuery = Kpi::with('kpi_detail', 'kpi_detail.kpi_description', 'kpi_type', 'kpi_category', 'user')
            ->where('kpi_type_id', 3)
            ->whereMonth('date', $date->month)
            ->whereYear('date', $date->year);

        if ($userId) {
            $kpisQuery->where('user_id', $userId);
        } else {
            $kpisQuery->where('user_id', Auth::id());
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
            'users' => self::getUsers(),
            'divisions' => Divisi::all(),
        ];
    }
    
    public static function getLeaderboardData($month, $userId = null, $area = null, $division = null)
    {
        $month = self::getFormattedMonth($month, 'Y-m');
        $date = Carbon::createFromFormat('Y-m', $month);

        $query = User::query()
            ->with([
                'divisi.area',
                'area',
                'kpi' => function($query) use ($month) {
                    $query->select('id', 'user_id', 'percentage', 'date')
                        ->where('kpi_type_id', 3)
                        ->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$month])
                        ->orderBy('date', 'DESC')
                        ->with(['kpi_detail' => function($query) {
                            $query->whereNotNull('value_result')->where('value_result', '>=', 0);
                        }]);
                },
                'attendance' => function($query) use ($month) {
                    $query->select('user_id', 'late_less_30', 'late_more_30', 'sick_days', 'work_days', 'periode')
                        ->where('periode', $month);
                },
                'employeeReview' => function($query) use ($month) {
                    $query->select('user_id', 'responsiveness', 'problem_solver', 'helpfulness', 'initiative', 'periode')
                        ->where('periode', $month);
                }
            ]);

        if ($area) {
            $query->where('area_id', $area);
        }

        if ($division) {
            $query->where('divisi_id', $division);
        }

        if ($userId) {
            $query->where('id', $userId);
        }

        $users = $query->get();
        $leaderboardData = [];

        foreach ($users as $user) {
            $kpiScore = self::calculateKPIScore($user);
            $attendanceScore = self::calculateAttendanceScore($user);
            $activityScore = self::calculateActivityScore($user);

            $totalScore = ($kpiScore * 0.4) + ($attendanceScore * 0.4) + ($activityScore * 0.2);

            $leaderboardData[] = [
                'user' => $user,
                'kpiScore' => $kpiScore,
                'attendanceScore' => $attendanceScore,
                'activityScore' => $activityScore,
                'totalScore' => $totalScore
            ];
        }

        return collect($leaderboardData)->sortByDesc('totalScore')->values()->all();
    }
    
    protected static function getUsers()
    {
        return User::query()
            ->when(auth()->user()->role_id === 2, function ($query) {
                return $query->where('id', auth()->id());
            })
            ->orderBy('nama_lengkap')
            ->get();
    }
    
    protected static function calculateKPIScore($user)
    {
        $kpiScore = 0;
        
        foreach ($user->kpi as $kpi) {
            $kpiDetailWithValue = $kpi->kpi_detail->filter(function ($kpiDetail) {
                return $kpiDetail->value_result !== null && $kpiDetail->value_result >= 0;
            });

            $actualCount = $kpiDetailWithValue->sum('value_result');
            $count = $kpiDetailWithValue->count();
            $divisor = $count > 0 ? $count : 1;
            $score = ($kpi->percentage / 100) * ($actualCount / $divisor);
            $kpiScore += $score * 100;
        }

        return min(40, $kpiScore * 0.4);
    }

    protected static function calculateAttendanceScore($user)
    {
        if (!$user->attendance) return 0;

        $attendance = $user->attendance;
        $lateLess30 = $attendance->late_less_30 ?? 0;
        $lateMore30 = $attendance->late_more_30 ?? 0;
        $sickDays = $attendance->sick_days ?? 0;
        $workDays = $attendance->work_days ?? 0;

        if ($workDays <= 0) return 0;

        $initialAttendanceAchv = ($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays * 100;
        $penalty = ($lateLess30 * 1) + ($lateMore30 * 3) + ($sickDays * 5);
        $finalAttendanceAchv = max(0, $initialAttendanceAchv - $penalty);
        
        return ($finalAttendanceAchv / 100) * 40;
    }

    protected static function calculateActivityScore($user)
    {
        if (!$user->employeeReview) return 0;

        $review = $user->employeeReview;
        $responsiveness = $review->responsiveness ?? 0;
        $problemSolver = $review->problem_solver ?? 0;
        $helpfulness = $review->helpfulness ?? 0;
        $initiative = $review->initiative ?? 0;

        return ($responsiveness + $problemSolver + $helpfulness + $initiative) / 20 * 100 * 0.2;
    }
}
