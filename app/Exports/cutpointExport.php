<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use App\Models\Overopen;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class cutpointExport implements FromCollection, WithHeadings, WithMapping
{

    protected string $month;

    function __construct(string $month)
    {
        $this->month = $month;
    }
    /**
     * @return Collection
     */
    public function collection()
    {
        return Overopen::with(['user', 'leader', 'user.area', 'user.divisi'])
            ->whereBetween(
                'daily',
                [
                    Carbon::parse(strtotime($this->month))->setTimezone(env('DEFAULT_TIMEZONE_APP', 'Asia/Jakarta'))->timestamp,
                    Carbon::parse(strtotime($this->month))->setTimezone(env('DEFAULT_TIMEZONE_APP', 'Asia/Jakarta'))->endOfMonth()->timestamp
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

    public function map($row): array
    {
        return [
            $row->user->nama_lengkap,
            $row->user->area->name,
            $row->user->divisi->name,
            date('d M Y',$row->daily),
            $row->week,
            $row->year,
            $row->point,
            $row->keterangan,
            $row->leader->nama_lengkap,
        ];
    }
}
