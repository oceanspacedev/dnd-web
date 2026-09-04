<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class LeaderboardEmployeeReviewDetailSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    protected $reviewDetails;

    protected string $periodType;

    protected string $selectedPeriod;

    public function __construct($reviewDetails, string $periodType, string $selectedPeriod)
    {
        $this->reviewDetails = $reviewDetails;
        $this->periodType = $periodType;
        $this->selectedPeriod = $selectedPeriod;
    }

    public function title(): string
    {
        return 'Employee Review';
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->reviewDetails as $review) {
            $user = $review->user;

            $responsiveness = $review?->responsiveness ?? 0;
            $problemSolver = $review?->problem_solver ?? 0;
            $helpfulness = $review?->helpfulness ?? 0;
            $initiative = $review?->initiative ?? 0;

            $activityScore = ($responsiveness + $problemSolver + $helpfulness + $initiative) / 20 * 100 * 0.15;
            $period = $this->periodType === 'year'
                ? $review->periode
                : $this->selectedPeriod;

            $rows[] = [
                $period,
                $user->employee_id,
                $user->nama_lengkap,
                $user->divisi->name ?? '',
                $user->area->name ?? '',
                $responsiveness,
                $problemSolver,
                $helpfulness,
                $initiative,
                $activityScore,
            ];
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
            'Responsiveness',
            'Problem Solver',
            'Helpfulness',
            'Initiative',
            'Activity Score',
        ];
    }
}
