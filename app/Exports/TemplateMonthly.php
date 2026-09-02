<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TemplateMonthly implements WithHeadings, FromCollection
{
    use Exportable;
    public function collection()
    {
        $date = now()->year . '-' . now()->month;
        if(now()->month < 10){
            $date = now()->year . '-0' . now()->month;
        }
        return collect([
            [
                'date' => $date,
                'task' => 'contoh task monthly non',
                'tipe' => 'NON',
                'value_plan_result' => '',
            ],
            [
                'date' => $date,
                'task' => 'contoh task monthly result',
                'tipe' => 'RESULT',
                'value_plan_result' => '10000',
            ],
        ]);
    }
    public function headings(): array
    {
        return [
            'date',
            'task',
            'tipe',
            'value_plan_result',
        ];
    }
}
