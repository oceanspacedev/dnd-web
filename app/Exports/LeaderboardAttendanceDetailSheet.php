<?php

namespace App\Exports;

use App\Services\PerformanceScoreCalculator;
use App\Services\ScoringRuleService;
use App\Support\Scoring\AttendanceComponentData;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Detail kehadiran per baris attendances. Skor dihitung dengan versi aturan periode baris itu sendiri
 * (export tahunan bisa memuat dua versi aturan).
 *
 * Kolom lama tetap di posisinya; kolom tambahan ada di belakang: nama versi aturan, lalu satu kolom per
 * komponen kehadiran tanpa kolom lama (izin, mangkir, cuti, dan komponen baru dari master data).
 */
class LeaderboardAttendanceDetailSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
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
        $extraCodes = array_keys($this->extraComponents());

        foreach ($this->attendanceDetails as $attendance) {
            $user = $attendance->user;

            $period = $this->periodType === 'year'
                ? $attendance->periode
                : $this->selectedPeriod;

            // Aturan dari periode baris ini (memo per request, tanpa query per baris).
            $rule = ScoringRuleService::forPeriode($attendance->periode);
            $score = PerformanceScoreCalculator::attendanceFor($attendance, $rule);

            $row = [
                $period,
                $user->employee_id,
                $user->nama_lengkap,
                $user->divisi->name ?? '',
                $user->area->name ?? '',
                $attendance->scoringWorkDays(),
                $attendance->countFor('late_less_30'),
                $attendance->countFor('late_more_30'),
                $attendance->countFor('sick_days'),
                $score->basePct,
                $score->penaltyPointsNumber(),
                $score->finalPct,
                $score->score,
                $rule->name,
            ];

            foreach ($extraCodes as $code) {
                $row[] = $attendance->countFor($code);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    public function headings(): array
    {
        $headings = [
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
            'Aturan Penilaian',
        ];

        foreach ($this->extraComponents() as $component) {
            $headings[] = $component->name;
        }

        return $headings;
    }

    /**
     * Komponen kehadiran yang tidak punya kolom lama di attendances, urut sort_order.
     *
     * @return array<string, AttendanceComponentData>
     */
    protected function extraComponents(): array
    {
        return ScoringRuleService::components()
            ->reject(static fn (AttendanceComponentData $component): bool => $component->usesLegacyColumn()
                || AttendanceComponentData::legacyColumnFor($component->code) !== null)
            ->all();
    }
}
