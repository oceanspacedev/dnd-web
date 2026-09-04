<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class KpiMonthlyExport implements WithMultipleSheets
{
    protected string $year;

    protected string $divisi_id;

    protected ?int $userId;

    public function __construct(string $year, string $divisi_id, ?int $userId = null)
    {
        $this->year = $year;
        $this->divisi_id = $divisi_id;
        $this->userId = $userId;
    }

    public function sheets(): array
    {
        return [
            new KpiMonthlySummarySheet($this->year, $this->divisi_id, $this->userId),
            new KpiMonthlyDetailSheet($this->year, $this->divisi_id, $this->userId),
        ];
    }
}
