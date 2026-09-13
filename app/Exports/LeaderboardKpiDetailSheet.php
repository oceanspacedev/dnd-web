<?php

namespace App\Exports;

use Illuminate\Support\Facades\Date;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class LeaderboardKpiDetailSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    protected $kpiDetails;

    protected string $periodType;

    protected string $selectedPeriod;

    public function __construct($kpiDetails, string $periodType, string $selectedPeriod)
    {
        $this->kpiDetails = $kpiDetails;
        $this->periodType = $periodType;
        $this->selectedPeriod = $selectedPeriod;
    }

    public function title(): string
    {
        return 'KPI Detail';
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->kpiDetails as $kpi) {
            $user = $kpi->user;

            $kpiDetailWithValue = $kpi->kpi_detail->filter(function ($kpiDetail) {
                return $kpiDetail->value_result !== null && $kpiDetail->value_result >= 0;
            });

            $actualCount = $kpiDetailWithValue->sum('value_result');
            $count = $kpiDetailWithValue->count();
            $ratio = $count > 0 ? ($actualCount / $count) : 0;
            $ratio = min(1, $ratio);
            $score = ($kpi->percentage / 100) * $ratio;
            $period = $this->periodType === 'year'
                ? Date::parse($kpi->date)->format('Y-m')
                : $this->selectedPeriod;

            foreach ($kpi->kpi_detail as $detail) {
                $indicatorType = ($detail->kpi_description?->is_negative ?? false) ? 'NEGATIVE' : 'POSITIVE';

                $rows[] = [
                    $period,
                    $user->employee_id,
                    $user->nama_lengkap,
                    $user->position->name ?? '',
                    $user->divisi->name ?? '',
                    $user->area->name ?? '',
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
                ];
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'Periode',
            'ID Karyawan',
            'Nama Lengkap',
            'Position',
            'Division',
            'Area',
            'KPI Category',
            'KPI Description',
            'Tipe Indikator',
            'Count Type',
            'Value Plan',
            'Value Actual',
            'Value Result',
            'KPI Percentage',
            'KPI Actual Count',
            'KPI Item Count',
            'KPI Score',
        ];
    }
}
