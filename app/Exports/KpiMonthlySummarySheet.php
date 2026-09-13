<?php

namespace App\Exports;

use App\Models\Kpi;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class KpiMonthlySummarySheet implements FromArray, WithHeadings, WithMapping, WithTitle
{
    protected string $year;

    protected string $divisi_id;

    protected ?int $userId;

    protected array $usersMonthYear = [];

    public function __construct(string $year, string $divisi_id, ?int $userId = null)
    {
        $this->year = $year;
        $this->divisi_id = $divisi_id;
        $this->userId = $userId;
    }

    public function title(): string
    {
        return 'Summary';
    }

    public function array(): array
    {
        $result = [];
        $this->usersMonthYear = [];

        for ($month = 1; $month <= 12; $month++) {
            $yearMonth = $this->year.'-'.sprintf('%02d', $month);
            $carbonDate = Date::createFromFormat('Y-m', $yearMonth);
            $this->usersMonthYear[] = $carbonDate->isoFormat('MMM');
        }

        if ($this->userId) {
            $users = User::orderBy('nama_lengkap')
                ->where('id', $this->userId)
                ->get();
        } elseif ($this->divisi_id) {
            $users = User::orderBy('nama_lengkap')->where('divisi_id', $this->divisi_id)->get();
        } else {
            $users = User::orderBy('nama_lengkap')->where('divisi_id', auth()->user()->divisi_id)->get();
        }

        foreach ($users as $user) {
            $usersYearlyKpis = Kpi::with('kpi_detail', 'kpi_detail.kpi_description', 'kpi_type', 'kpi_category', 'user')
                ->where('kpi_type_id', 3)
                ->whereYear('date', $this->year)
                ->whereHas('user', function ($q) use ($user) {
                    $q->where('id', $user->id);
                })
                ->orderBy('date', 'DESC')
                ->get();

            $groupedUsersKpisByYear = $usersYearlyKpis->groupBy(function ($kpi) {
                return CarbonImmutable::parse($kpi->date)->format('Y-m');
            });

            $usersAverageKpiMonthlyByYear = [];
            $sumOfScores = 0;

            for ($month = 1; $month <= 12; $month++) {
                $yearMonth = $this->year.'-'.sprintf('%02d', $month);
                $yearlyGroupedKpis = $groupedUsersKpisByYear[$yearMonth] ?? collect();
                $cumulativeScore = 0;

                foreach ($yearlyGroupedKpis as $kpi) {
                    $kpiDetailWithValue = $kpi->kpi_detail->filter(function ($kpiDetail) {
                        return $kpiDetail->value_result !== null && $kpiDetail->value_result >= 0;
                    });

                    if ($kpiDetailWithValue->isNotEmpty()) {
                        $actualCount = $kpiDetailWithValue->sum('value_result');
                        $ratio = $actualCount / $kpiDetailWithValue->count();
                        $ratio = min(1, $ratio);
                        $score = ($kpi->percentage / 100) * $ratio;
                        $cumulativeScore += $score;
                    }
                }

                $usersAverageKpiMonthly = $yearlyGroupedKpis->count() > 0 ? ($cumulativeScore * 100) : 0;
                $usersAverageKpiMonthly = min(100, $usersAverageKpiMonthly);
                $usersAverageKpiMonthlyByYear[$yearMonth] = $usersAverageKpiMonthly;
                $sumOfScores += $usersAverageKpiMonthly;
            }

            $overallAverageScore = $sumOfScores / 12;

            $result[] = [
                'id' => $user->id,
                'name' => $user->nama_lengkap,
                'score' => $usersAverageKpiMonthlyByYear,
                'average' => $overallAverageScore,
            ];
        }

        return $result;
    }

    public function headings(): array
    {
        $headings = ['Name'];
        $this->usersMonthYear = [];

        for ($month = 1; $month <= 12; $month++) {
            $yearMonth = $this->year.'-'.sprintf('%02d', $month);
            $carbonDate = Date::createFromFormat('Y-m', $yearMonth);
            $this->usersMonthYear[] = $carbonDate->isoFormat('MMM');
        }

        return array_merge($headings, $this->usersMonthYear, ['Average']);
    }

    public function map(mixed $row): array
    {
        $result = [$row['name']];
        $average = [$row['average']];

        return array_merge($result, $row['score'], $average);
    }
}
