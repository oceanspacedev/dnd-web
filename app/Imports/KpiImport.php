<?php

namespace App\Imports;

use App\Models\Kpi;
use Exception;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class KpiImport implements ToModel, WithHeadingRow
{
    protected array $kpiIds;

    function __construct(array $kpiIds)
    {
        $this->kpiIds = $kpiIds;
    }

    public function model(array $row)
    {
        dd($this->kpiIds);

        try {
            $kpis = [];
    
            foreach ($this->kpiIds as $kpiId) {
                $kpi_desc = Kpi::where('description', $row['kpi_description'])->first();
    
                $kpi = new Kpi([
                    'kpi_id' => $kpiId,
                    'kpi_description_id' => $kpi_desc->id,
                    'count_type' => preg_replace('/\s+/', '', strtoupper($row['tipe'])),
                    'value_plan' => preg_replace('/\s+/', '', $row['value_plan']),
                    'value_result' => 0,
                ]);
                $kpi->save();
    
                $kpis[] = $kpi;
            }
        } catch (Exception $e) {
            throw new Exception("Somethings wrong, " . $e->getMessage());
        }

    }
}
