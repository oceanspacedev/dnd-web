<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class LeaderboardAttendanceDetailSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    protected $attendanceDetails;
    protected string $periodType;
    protected string $selectedPeriod;

    public function __construct($attendanceDetails, string $periodType, string $selectedPeriod)
    {
        $this->attendanceDetails = $attendanceDetails;
        $this->periodType = $periodType;
        $this->selectedPeriod = $selectedPeriod;
    }

    public function title(): string
    {
        return 'Attendance Detail';
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->attendanceDetails as $attendance) {
            $user = $attendance->user;

            $lateLess30 = $attendance?->late_less_30 ?? 0;
            $lateMore30 = $attendance?->late_more_30 ?? 0;
            $sickDays = $attendance?->sick_days ?? 0;
            $workDays = $attendance?->work_days ?? 0;

            $period = $this->periodType === 'year'
                ? $attendance->periode
                : $this->selectedPeriod;

            $initialAttendanceAchv = ($workDays > 0)
                ? ($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays * 100
                : 0;
            $penalty = ($lateLess30 * 1) + ($lateMore30 * 3) + ($sickDays * 5);
            $finalAttendanceAchv = max(0, $initialAttendanceAchv - $penalty);
            $attendanceScore = ($finalAttendanceAchv / 100) * 15;

            $row = [
                $period,
                $user->employee_id,
                $user->nama_lengkap,
                $user->divisi->name ?? '',
                $user->area->name ?? '',
                $workDays,
                $lateLess30,
                $lateMore30,
                $sickDays,
                $initialAttendanceAchv,
                $penalty,
                $finalAttendanceAchv,
                $attendanceScore,
            ];

            $rows[] = $row;
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'Periode',
            'ID Karyawan',
            'Nama Lengkap',
            'Division',
            'Area',
            'Work Days',
            'Late < 30',
            'Late > 30',
            'Sick Days',
            'Initial Achievement',
            'Penalty',
            'Final Achievement',
            'Attendance Score',
        ];
    }
}
