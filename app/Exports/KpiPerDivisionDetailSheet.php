<?php

namespace App\Exports;

use App\Models\Kpi;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class KpiPerDivisionDetailSheet implements FromArray, WithHeadings, WithTitle
{
    protected string $month;

    protected string $divisi_id;

    protected ?int $userId;

    public function __construct(string $month, string $divisi_id, ?int $userId = null)
    {
        $this->month = $month;
        $this->divisi_id = $divisi_id;
        $this->userId = $userId;
    }

    public function title(): string
    {
        return 'Detail';
    }

    public function array(): array
    {
        if ($this->userId) {
            $users = User::orderBy('nama_lengkap')->where('id', $this->userId)->get();
        } elseif ($this->divisi_id) {
            $users = User::orderBy('nama_lengkap')->where('divisi_id', $this->divisi_id)->get();
        } else {
            $users = User::orderBy('nama_lengkap')->where('divisi_id', auth()->user()->divisi_id)->get();
        }

        $userIds = $users->pluck('id')->all();
        if (empty($userIds)) {
            return [];
        }

        $date = Date::createFromFormat('Y-m', $this->month);

        $kpis = Kpi::with([
            'kpi_detail',
            'kpi_detail.kpi_description',
            'kpi_category',
            'user',
            'user.divisi',
            'user.position',
        ])
            ->where('kpi_type_id', 3)
            ->whereMonth('date', $date->month)
            ->whereYear('date', $date->year)
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
