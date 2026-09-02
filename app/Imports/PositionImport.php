<?php

namespace App\Imports;

use App\Models\Position;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Exception;

class PositionImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        $position = Position::where('name', strtoupper($row['job_position']));

        if ($position->first()) {
            throw new Exception('Job position ' . $row['job_position'] . ' already exist !');
        } else {
            return new Position([
                'name' => strtoupper($row['job_position']),
            ]);
        }
    }
}
