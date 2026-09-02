<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use App\Models\Weekly;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class WeeklyExport implements FromCollection, WithHeadings, WithMapping
{
    protected int $week;
    protected int $year;

    function __construct(int $week, int $year)
    {
        $this->week = $week;
        $this->year = $year;
    }
    /**
     * @return Collection
     */
    public function collection()
    {
        return Weekly::with('user', 'user.area', 'user.divisi')
            ->where('week', $this->week)
            ->where('year', $this->year)
            ->get()
            ->sortBy('user.nama_lengkap');
    }

    public function headings(): array
    {
        return [
            'nama',
            'area',
            'divisi',
            'tahun',
            'week',
            'task',
            'tipe',
            'result_plan',
            'result_actual',
            'status',
        ];
    }

    public function map($row): array
    {
        return [
            $row->user->nama_lengkap,
            $row->user->area->name,
            $row->user->divisi->name,
            $row->year,
            $row->week,
            $row->task,
            $row->tipe,
            $row->value_plan ? number_format($row->value_plan, 0, ',', '.') : '-',
            $row->value_actual ? number_format($row->value_actual, 0, ',', '.') : '-',
            $row->value ? 'CLOSED' : 'OPEN',
        ];
    }
}
