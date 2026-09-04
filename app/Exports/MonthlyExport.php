<?php

namespace App\Exports;

use App\Models\Monthly;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class MonthlyExport implements FromCollection, WithHeadings, WithMapping
{
    protected string $month;

    public function __construct(string $month)
    {
        $this->month = $month;
    }

    /**
     * @return Collection
     */
    public function collection(): Enumerable
    {
        return Monthly::with('user', 'user.area', 'user.divisi')
            ->where('date', $this->month)
            ->get()
            ->sortBy('user.nama_lengkap');
    }

    public function headings(): array
    {
        return [
            'nama',
            'area',
            'divisi',
            'month',
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
            date('M y', $row->date / 1000),
            $row->task,
            $row->tipe,
            $row->value_plan ? number_format($row->value_plan, 0, ',', '.') : '-',
            $row->value_actual ? number_format($row->value_actual, 0, ',', '.') : '-',
            $row->value ? 'CLOSED' : 'OPEN',
        ];
    }
}
