<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithHeadings;

class TemplatePosition implements WithHeadings
{
    public function headings(): array
    {
        return [
            'job_position',
        ];
    }
}
