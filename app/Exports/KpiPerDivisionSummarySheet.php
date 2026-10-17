<?php

namespace App\Exports;

use App\Models\Kpi;
use App\Models\KpiDetail;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class KpiPerDivisionSummarySheet implements FromArray, WithHeadings, WithMapping, WithTitle
{
    protected string $month;

    protected string $divisi_id;

    protected ?int $userId;

    /** @var array<int>|null null = tanpa batas (super admin). */
    protected ?array $allowedUserIds;

    /**
     * @param  array<int>|null  $allowedUserIds  Batas karyawan yang boleh diekspor; null = tanpa batas.
     */
    public function __construct(string $month, string $divisi_id, ?int $userId = null, ?array $allowedUserIds = null)
    {
        $this->month = $month;
        $this->divisi_id = $divisi_id;
        $this->userId = $userId;
        $this->allowedUserIds = $allowedUserIds;
    }

    public function title(): string
    {
        return 'Summary';
    }

    public function array(): array
    {
        $result = [];

        $usersQuery = User::orderBy('nama_lengkap');

        if ($this->userId) {
            $usersQuery->where('id', $this->userId);
        } elseif ($this->divisi_id) {
            $usersQuery->where('divisi_id', $this->divisi_id);
        } else {
            $usersQuery->where('divisi_id', auth()->user()?->divisi_id);
        }

        // Hanya karyawan dalam cakupan aktor (badan usaha / tim); null = tanpa batas.
        if ($this->allowedUserIds !== null) {
            $usersQuery->whereIn('id', $this->allowedUserIds);
        }

        $users = $usersQuery->get();

        $date = Date::createFromFormat('Y-m', $this->month);

        foreach ($users as $user) {
            $user_closed_kpis = 0;
            $user_all_kpis = 0;
            $cumulativeScore = 0;

            $kpis = Kpi::with('kpi_detail', 'kpi_detail.kpi_description', 'kpi_type', 'kpi_category', 'user')
                ->where('kpi_type_id', 3)
                ->where('user_id', $user->id)
                ->whereMonth('date', $date->month)
                ->whereYear('date', $date->year)
                ->get();

            foreach ($kpis as $kpi) {
                $closed_kpis = KpiDetail::where('kpi_id', $kpi->id)
                    ->whereNotNull('value_result')
                    ->where('value_result', '!=', 0)
                    ->count();

                $all_kpis = KpiDetail::where('kpi_id', $kpi->id)
                    ->whereNull('deleted_at')
                    ->count();

                $kpiDetailWithValue = $kpi->kpi_detail->filter(function ($kpiDetail) {
                    return $kpiDetail->value_result !== null && $kpiDetail->value_result >= 0;
                });

                $actualCount = $kpiDetailWithValue->sum('value_result');
                $ratio = $all_kpis > 0 ? ($actualCount / $all_kpis) : 0;
                $ratio = min(1, $ratio);
                $score = ($kpi->percentage) * $ratio;

                $user_closed_kpis += $closed_kpis;
                $user_all_kpis += $all_kpis;
                $cumulativeScore += $score;
            }

            $result[] = [
                'id' => $user->id,
                'name' => $user->nama_lengkap,
                'divisi' => $user->divisi->name ?? '',
                'total_task' => $user_closed_kpis.'/'.$user_all_kpis,
                'score' => $cumulativeScore.'%',
            ];
        }

        return $result;
    }

    public function headings(): array
    {
        return ['Name', 'Divisi', 'Total Task', 'Score'];
    }

    public function map(mixed $row): array
    {
        return [
            $row['name'],
            $row['divisi'],
            $row['total_task'],
            $row['score'],
        ];
    }
}
