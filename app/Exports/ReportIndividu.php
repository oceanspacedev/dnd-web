<?php

namespace App\Exports;

use App\Helpers\ConvertDate;
use App\Models\User;
use App\Models\Daily;
use App\Models\Weekly;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ReportIndividu implements FromArray, WithHeadings, WithMapping
{
    use Exportable;


    protected int $week;
    protected int $year;
    protected $users;

    function __construct(int $week, int $year,$users)
    {
        $this->week = $week;
        $this->year = $year;
        $this->users = $users;
    }

    public function array(): array
    {
        $report = array();

        foreach ($this->users as $user) {
            ##SETUP VARIABLE
            //DAILY
            $onTimePoint = 0;
            $dailyPoint = 0;
            $dailyOntime = 0;
            $dailysLength = [];
            $actTarget = [];
            $dpoint = [];
            //WEEKLY
            $weeklyValue = 0;
            $kpiWeekly = 0;
            $totalTaskWeekly = 0;
            $closedTaskWeekly = 0;
            for ($i = 0; $i < 7; $i++) {
                $dailyClose = 0;
                if ($i == 0) {
                    $monday = ConvertDate::getMondayOrSaturday($this->year, $this->week, true);
                    $daily = Daily::where('date', $monday)->where('user_id', $user->id)->orderBy('time')->get();
                    if (count($daily) > 0) {
                        $dailyClose = count($daily->where('status', 1));
                        if ($dailyClose > 0) {
                            if ($dailyClose / count($daily->where('status', 1)) < 1) {
                                $dpoint[$monday->format('D')] = 1 - ($dailyClose / count($daily->where('status', 1)));
                            } else {
                                $dpoint[$monday->format('D')] = 0;
                            }
                        } else {
                            $dpoint[$monday->format('D')] = 1;
                        }
                    } else {
                        $dpoint[$monday->format('D')] = 0;
                    }
                    $actTarget[$monday->format('D')] = '';
                    if ($dailyClose < 10) {
                        $actTarget[$monday->format('D')] = '0' . $dailyClose;
                    } else {
                        $actTarget[$monday->format('D')] = $dailyClose;
                    }
                    $actTarget[$monday->format('D')] = $actTarget[$monday->format('D')] . '/';
                    if (count($daily->where('isplan', 1)) < 10) {
                        $actTarget[$monday->format('D')] = $actTarget[$monday->format('D')] . '0' . count($daily->where('isplan', 1));
                    } else {
                        $actTarget[$monday->format('D')] = $actTarget[$monday->format('D')] . count($daily->where('isplan', 1));
                    }
                } else {
                    $monday1 = ConvertDate::getMondayOrSaturday($this->year, $this->week, true)->addDay($i);
                    $daily = Daily::where('date', $monday1)->where('user_id', $user->id)->orderBy('time')->get();
                    if (count($daily) > 0) {
                        $dailyClose = count($daily->where('status', 1));
                        if ($dailyClose > 0) {
                            if ($dailyClose / count($daily->where('status', 1)) < 1) {
                                $dpoint[$monday1->format('D')] = 1 - ($dailyClose / count($daily->where('status', 1)));
                            } else {
                                $dpoint[$monday1->format('D')] = 0;
                            }
                        } else {
                            $dpoint[$monday1->format('D')] = 1;
                        }
                    } else {
                        $dpoint[$monday1->format('D')] = 0;
                    }
                    $actTarget[$monday1->format('D')] = '';
                    if ($dailyClose < 10) {
                        $actTarget[$monday1->format('D')] = '0' . $dailyClose;
                    } else {
                        $actTarget[$monday1->format('D')] = $dailyClose;
                    }
                    $actTarget[$monday1->format('D')] = $actTarget[$monday1->format('D')] . '/';
                    if (count($daily->where('isplan', 1)) < 10) {
                        $actTarget[$monday1->format('D')] = $actTarget[$monday1->format('D')] . '0' . count($daily->where('isplan', 1));
                    } else {
                        $actTarget[$monday1->format('D')] = $actTarget[$monday1->format('D')] . count($daily->where('isplan', 1));
                    }
                }

                if (count($daily) > 0) {
                    array_push($dailysLength, $daily);
                }
            }
            ##DAILYPOINT
            $dailyDone = 0;
            $dailyTaskPlanTotal = 0;
            foreach ($dailysLength as $dailys) {
                foreach ($dailys as $d) {
                    if ($d->status) {
                        $dailyDone++;
                    }
                    if ($d->isplan) {
                        $dailyTaskPlanTotal++;
                    }
                    $onTimePoint += $d->ontime;
                }
            }
            $dailySubmit = count($dailysLength);
            #SUMMARY DAILY
            if ($dailyDone) {
                if (!$user->wr && !$user->wn) {
                    if ($dailyTaskPlanTotal) {
                        $dailyPoint = ($dailySubmit / 6) * ($dailyDone / $dailyTaskPlanTotal) > 1 ? 80 : (($dailySubmit / 6) * ($dailyDone / $dailyTaskPlanTotal)) * 80;
                    }
                } else {
                    if ($dailyTaskPlanTotal) {
                        $dailyPoint = ($dailySubmit / 6) * ($dailyDone / $dailyTaskPlanTotal) > 1 ? 40 : (($dailySubmit / 6) * ($dailyDone / $dailyTaskPlanTotal)) * 40;
                    }
                }
                if ($dailyTaskPlanTotal) {
                    $dailyOntime = ($dailySubmit / 6) * ($onTimePoint / $dailyTaskPlanTotal) > 1 ? 20 : (($dailySubmit / 6) * ($onTimePoint / $dailyTaskPlanTotal)) * 20;
                }
            }
            ##DAILYONTIME
            $kpiDaily = $dailyPoint;
            $kpiDailyOntime = $dailyOntime;

            if ($user->wr || $user->wn) {
                ##WEEKLY
                $weeklys = Weekly::where('year', $this->year)->where('week', $this->week)->where('user_id', $user->id)->get();
                $totalTaskWeekly = count($weeklys);
                foreach ($weeklys as $weekly) {
                    if ($weekly->value) {
                        $weeklyValue += $weekly->value;
                        $closedTaskWeekly++;
                    }
                }
                if ($weeklyValue) {
                    $kpiWeekly = ($weeklyValue / $totalTaskWeekly) > 1 ? 40 : ($weeklyValue / $totalTaskWeekly) * 40;
                }
            }
            $totalKpi = $kpiDaily + $kpiWeekly + $kpiDailyOntime;
            $closedTaskWeekly = $closedTaskWeekly < 10 ? '0' . $closedTaskWeekly : $closedTaskWeekly;
            $totalTaskWeekly = $totalTaskWeekly < 10 ? '0' . $totalTaskWeekly : $totalTaskWeekly;
            array_push($report, [
                'user' => $user,
                'daily' => $kpiDaily,
                'ontime_point' => $kpiDailyOntime,
                'weekly' => $kpiWeekly,
                'total' => $totalKpi,
                'act' => $actTarget,
                'actw' => $closedTaskWeekly . '/' . $totalTaskWeekly,
                'dpoint' => $dpoint,
            ]);
        }
        return $report;
    }

    public function headings(): array
    {
        return [
            'Nama',
            'Dept',
            'Divisi',
            'Senin',
            'Selasa',
            'Rabu',
            'Kamis',
            'Jumat',
            'Sabtu',
            'Minggu',
            'Weekly Actual',
            'Daily',
            'Ontime Point',
            'Weekly',
            'Total Point',
        ];
    }

    public function map($row): array
    {
        return [
            $row['user']->nama_lengkap,
            $row['user']->area->name,
            $row['user']->divisi->name,
            $row['act']['Mon'],
            $row['act']['Tue'],
            $row['act']['Wed'],
            $row['act']['Thu'],
            $row['act']['Fri'],
            $row['act']['Sat'],
            $row['act']['Sun'],
            $row['actw'],
            round($row['daily'], 2) ?? '0',
            round($row['ontime_point'], 2) ?? '0',
            round($row['weekly'], 2) ?? '0',
            round($row['total'], 2) ?? '0',
        ];
    }
}
