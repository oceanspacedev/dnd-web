<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class LeaderboardSummarySheet implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithTitle
{
    protected array $leaderboardData;

    public function __construct(array $leaderboardData)
    {
        $this->leaderboardData = $leaderboardData;
    }

    public function title(): string
    {
        return 'Leaderboard';
    }

    public function collection()
    {
        return collect($this->leaderboardData);
    }

    public function headings(): array
    {
        return [
            'ID Karyawan',
            'Nama Lengkap',
            'Division',
            'Area',
            'KPI Score (70%)',
            'Attendance Score (15%)',
            'Activity Score (15%)',
            'Total Score',
        ];
    }

    public function map($data): array
    {
        return [
            $data['user']->employee_id,
            $data['user']->nama_lengkap,
            $data['user']->divisi->name ?? 'N/A',
            $data['user']->area->name ?? 'N/A',
            number_format($data['kpiScore'], 2),
            number_format($data['attendanceScore'], 2),
            number_format($data['activityScore'], 2),
            number_format($data['totalScore'], 2),
        ];
    }
}
