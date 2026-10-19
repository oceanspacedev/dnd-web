<?php

namespace App\Exports;

use App\Support\Scoring\ScoringRuleData;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class LeaderboardMonthlySummarySheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    protected array $rows;

    protected string $year;

    /** @var list<ScoringRuleData> */
    protected array $scoringRules;

    /**
     * @param  list<ScoringRuleData>  $scoringRules  versi aturan tiap bulan pada tahun export.
     */
    public function __construct(array $rows, string $year, array $scoringRules = [])
    {
        $this->rows = $rows;
        $this->year = $year;
        $this->scoringRules = $scoringRules;
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
            ...LeaderboardWeightHeadings::scoreHeadings($this->scoringRules),
            'Total Score',
            // Kolom tambahan (di belakang): versi aturan dan bobot yang dipakai bulan itu.
            'Aturan Penilaian',
            'Bobot KPI (%)',
            'Bobot Kehadiran (%)',
            'Bobot Penilaian (%)',
        ];
    }
}
