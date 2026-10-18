<?php

namespace App\Exports;

use App\Support\Scoring\ScoringRuleData;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class LeaderboardSummarySheet implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    protected array $leaderboardData;

    /** @var list<ScoringRuleData> */
    protected array $scoringRules;

    /**
     * @param  list<ScoringRuleData>  $scoringRules  versi aturan periode export (bobot pada judul kolom).
     */
    public function __construct(array $leaderboardData, array $scoringRules = [])
    {
        $this->leaderboardData = $leaderboardData;
        $this->scoringRules = $scoringRules;
    }

    public function title(): string
    {
        return 'Leaderboard';
    }

    public function collection(): Enumerable
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
            ...LeaderboardWeightHeadings::scoreHeadings($this->scoringRules),
            'Total Score',
        ];
    }

    public function map(mixed $data): array
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
