<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class KpiPerDivisionExport implements Export, WithMultipleSheets
{
    protected string $month;

    protected string $divisi_id;

    protected ?int $userId;

    /** @var array<int>|null null = tanpa batas (super admin). */
    protected ?array $allowedUserIds;

    /**
     * @param  array<int>|null  $allowedUserIds  Batas karyawan yang boleh diekspor; null = tanpa batas.
     */
    public function __construct(string $month, string $divisi_id, ?int $userId = null, ?array $allowedUserIds = null)
    {
        $this->month = $month;
        $this->divisi_id = $divisi_id;
        $this->userId = $userId;
        $this->allowedUserIds = $allowedUserIds;
    }

    public function sheets(): array
    {
        return [
            new KpiPerDivisionSummarySheet($this->month, $this->divisi_id, $this->userId, $this->allowedUserIds),
            new KpiPerDivisionDetailSheet($this->month, $this->divisi_id, $this->userId, $this->allowedUserIds),
        ];
    }
}
