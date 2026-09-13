<?php

namespace App\Exports;

use App\Models\Overopen;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Date;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CutpointExport implements FromCollection, WithHeadings, WithMapping
{
    protected string $month;

    public function __construct(string $month)
    {
        $this->month = $month;
    }

    public function collection(): Enumerable
    {
        return Overopen::with(['user', 'leader', 'user.area', 'user.divisi'])
            ->whereBetween(
                'daily',
                [
                    Date::parse(strtotime($this->month))->setTimezone(config('app.timezone'))->timestamp,
                    Date::parse(strtotime($this->month))->setTimezone(config('app.timezone'))->endOfMonth()->timestamp,
                ]
            )
            ->get();
    }

    public function headings(): array
    {
        return [
            'nama',
            'dept',
            'divisi',
            'tanggal',
            'minggu',
            'tahun',
            'point',
            'keterangan',
            'atasan',
        ];
    }

    public function map(mixed $row): array
    {
        return [
            $row->user->nama_lengkap,
            $row->user->area->name,
            $row->user->divisi->name,
            date('d M Y', $row->daily),
            $row->week,
            $row->year,
            $row->point,
            $row->keterangan,
            $row->leader->nama_lengkap,
        ];
    }
}
