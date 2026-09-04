<?php

namespace App\Exports;

use App\Models\Weekly;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class WeeklyExport implements FromCollection, WithHeadings, WithMapping
{
    protected int $week;

    protected int $year;

    public function __construct(int $week, int $year)
    {
        $this->week = $week;
        $this->year = $year;
    }

    /**
     * @return Collection
     */
    public function collection(): Enumerable
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

    public function map(mixed $row): array
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
