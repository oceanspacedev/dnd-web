<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class LeaderboardMonthlySummarySheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    protected array $rows;
    protected string $year;

    public function __construct(array $rows, string $year)
    {
        $this->rows = $rows;
        $this->year = $year;
    }

    public function title(): string
    {
        return 'Summary Bulanan';
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Periode',
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
}
