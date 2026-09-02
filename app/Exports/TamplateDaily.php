<?php

namespace App\Exports;

use App\Helpers\ConvertDate;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TamplateDaily implements WithHeadings, FromCollection
{
    use Exportable;
    public function collection()
    {
        $monday = ConvertDate::getMondayOrSaturday(now()->year, now()->weekOfYear, true);
        return collect([
            [
                'date' => $monday->format('Y-m-d'),
                'task' => 'contoh task daily',
                'time' => '08:00'
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
