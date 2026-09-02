<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TemplateWeekly implements WithHeadings, FromCollection
{
    use Exportable;
    public function collection()
    {
        return auth()->user()->wr ? collect([
            [
                'year' => now()->year,
                'week' => now()->weekOfYear,
                'task' => 'contoh task weekly non',
                'tipe' => 'NON',
                'value_plan_result' => '',
            ],
            [
                'year' => now()->year,
                'week' => now()->weekOfYear,
                'task' => 'contoh task weekly result',
                'tipe' => 'RESULT',
                'value_plan_result' => '10000',
            ],
        ]) : collect([
            [
                'year' => now()->year,
                'week' => now()->weekOfYear,
                'task' => 'contoh task weekly non',
                'tipe' => 'NON',
                'value_plan_result' => '',
            ],
        ]);
    }
    public function headings(): array
    {
        return [
            'year',
            'week',
            'task',
            'tipe',
            'value_plan_result',
        ];
    }
}
