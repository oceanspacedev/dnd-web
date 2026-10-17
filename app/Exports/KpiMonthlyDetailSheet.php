<?php

namespace App\Exports;

use App\Models\Kpi;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class KpiMonthlyDetailSheet implements FromArray, WithHeadings, WithTitle
{
    protected string $year;

    protected string $divisi_id;

    protected ?int $userId;

    /** @var array<int>|null null = tanpa batas (super admin). */
    protected ?array $allowedUserIds;

    /**
     * @param  array<int>|null  $allowedUserIds  Batas karyawan yang boleh diekspor; null = tanpa batas.
     */
    public function __construct(string $year, string $divisi_id, ?int $userId = null, ?array $allowedUserIds = null)
    {
        $this->year = $year;
        $this->divisi_id = $divisi_id;
        $this->userId = $userId;
        $this->allowedUserIds = $allowedUserIds;
    }

    public function title(): string
    {
        return 'Detail';
    }

    public function array(): array
    {
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

        $userIds = $users->pluck('id')->all();
        if (empty($userIds)) {
            return [];
        }

        $kpis = Kpi::with([
            'kpi_detail',
            'kpi_detail.kpi_description',
            'kpi_category',
            'user',
            'user.divisi',
            'user.position',
        ])
            ->where('kpi_type_id', 3)
            ->whereYear('date', $this->year)
            ->whereIn('user_id', $userIds)
            ->orderBy('date', 'ASC')
            ->get();

        $result = [];

        foreach ($kpis as $kpi) {
            $kpiDetailWithValue = $kpi->kpi_detail->filter(function ($kpiDetail) {
                return $kpiDetail->value_result !== null && $kpiDetail->value_result >= 0;
            });

            $actualCount = $kpiDetailWithValue->sum('value_result');
            $count = $kpiDetailWithValue->count();
            $ratio = $count > 0 ? ($actualCount / $count) : 0;
            $ratio = min(1, $ratio);
            $score = ($kpi->percentage / 100) * $ratio;

            foreach ($kpi->kpi_detail as $detail) {
                $indicatorType = ($detail->kpi_description?->is_negative ?? false) ? 'NEGATIVE' : 'POSITIVE';

                $result[] = [
                    Date::parse($kpi->date)->format('Y-m'),
                    $kpi->user->nama_lengkap ?? '',
                    $kpi->user->position->name ?? '',
                    $kpi->user->divisi->name ?? '',
                    $kpi->kpi_category->name ?? '',
                    $detail->kpi_description->description ?? '',
                    $indicatorType,
                    $detail->count_type ?? '',
                    $detail->value_plan ?? null,
                    $detail->value_actual ?? null,
                    $detail->value_result ?? null,
                    $kpi->percentage ?? null,
                    $actualCount,
                    $count,
                    $score * 100,
                    $detail->is_extra_task ? 'YES' : 'NO',
                ];
            }
        }

        return $result;
    }

    public function headings(): array
    {
        return [
            'Month',
            'User',
            'Position',
            'Division',
            'Category',
            'Description',
            'Tipe Indikator',
            'Count Type',
            'Value Plan',
            'Value Actual',
            'Value Result',
            'KPI Percentage',
            'KPI Actual Count',
            'KPI Item Count',
            'KPI Score',
            'Extra Task',
        ];
    }
}
