<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class KpiPerDivisionExport implements Export, WithMultipleSheets
{
    protected string $month;

    protected string $divisi_id;

    protected ?int $userId;

    public function __construct(string $month, string $divisi_id, ?int $userId = null)
    {
        $this->month = $month;
        $this->divisi_id = $divisi_id;
        $this->userId = $userId;
    }

    public function sheets(): array
    {
        return [
            new KpiPerDivisionSummarySheet($this->month, $this->divisi_id, $this->userId),
            new KpiPerDivisionDetailSheet($this->month, $this->divisi_id, $this->userId),
        ];
    }
}
