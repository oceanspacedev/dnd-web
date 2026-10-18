<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class KpiMonthlyExport implements Export, WithMultipleSheets
{
    protected string $year;

    protected string $divisi_id;

    protected ?int $userId;

    /** @var array<int>|null null = tanpa batas (super admin). */
    protected ?array $allowedUserIds;

    /**
     * @param  array<int>|null  $allowedUserIds  Batas karyawan yang boleh diekspor; null = tanpa batas.
     */
    public function __construct(string $year, string $divisi_id, ?int $userId = null, ?array $allowedUserIds = null)
    {
        $this->year = $year;
        $this->divisi_id = $divisi_id;
        $this->userId = $userId;
        $this->allowedUserIds = $allowedUserIds;
    }

    public function sheets(): array
    {
        return [
            new KpiMonthlySummarySheet($this->year, $this->divisi_id, $this->userId, $this->allowedUserIds),
            new KpiMonthlyDetailSheet($this->year, $this->divisi_id, $this->userId, $this->allowedUserIds),
        ];
    }
}
