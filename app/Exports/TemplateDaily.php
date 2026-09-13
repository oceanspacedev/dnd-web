<?php

namespace App\Exports;

use App\Support\IsoWeek;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TemplateDaily implements FromCollection, WithHeadings
{
    use Exportable;

    public function collection(): Enumerable
    {
        $monday = IsoWeek::startsAt(now()->year, now()->weekOfYear);

        return collect([
            [
                'date' => $monday->format('Y-m-d'),
                'task' => 'contoh task daily',
                'time' => '08:00',
            ],
        ]);
    }

    public function headings(): array
    {
        return [
            'date',
            'task',
            'time',
        ];
    }

    // public function columnFormats(): array
    // {
    //     return [
    //         'A' => DataType::TYPE_STRING,
    //         'B' => DataType::TYPE_STRING,
    //         'C' => DataType::TYPE_STRING,
    //     ];
    // }
}
