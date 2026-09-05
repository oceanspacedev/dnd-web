<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TemplatePosition implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'job_position',
        ];
    }

    public function array(): array
    {
        return [];
    }
}
