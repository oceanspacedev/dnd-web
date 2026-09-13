<?php

namespace App\Exports;

use App\Models\Daily;
use App\Models\User;
use App\Support\IsoWeek;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class DailyExport implements FromCollection, WithHeadings, WithMapping
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
        $monday = IsoWeek::startsAt($this->year, $this->week);

        return Daily::with('tag', 'user', 'user.area', 'user.divisi')
            ->whereBetween('date', [$monday->format('y-m-d'), $monday->addDay(6)->format('y-m-d')])
            ->orderBy(User::select('nama_lengkap')->whereColumn('users.id', 'dailies.user_id'))
            ->orderBy('date')
            ->orderBy('time')
            ->get();
    }

    public function headings(): array
    {
        return [
            'nama',
            'dept',
            'divisi',
            'tanggal',
            'jam',
            'task',
            'status',
            'taged by',
        ];
    }

    public function map(mixed $row): array
    {
        return [
            $row->user->nama_lengkap,
            $row->user->area->name,
            $row->user->divisi->name,
            date('d M y', $row->date / 1000),
            $row->time,
            $row->task,
            $row->status ? 'Closed' : 'Open',
            $row->tag_id ? $row->tag->nama_lengkap : '-',
        ];
    }
}
