<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Date;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class AttendanceImportTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * Fetch data for export: only users' full names and current period.
     */
    public function collection(): Enumerable
    {
        $currentPeriod = Date::now()->format('Y-m'); // Current month in YYYY-MM format

        return User::whereNull('deleted_at')
            ->select('employee_id', 'nama_lengkap')
            ->get()
            ->filter(function ($user) {
                return $user->nama_lengkap !== 'ADMIN'; // Exclude users with 'nama_lengkap' as 'ADMIN'
            })
            ->map(function ($user) use ($currentPeriod) {
                return [
                    'employee_id' => $user->employee_id,
                    'nama_lengkap' => $user->nama_lengkap,
                    'periode' => $currentPeriod,
                    'work_days' => '',
                    'late_less_30' => '',
                    'late_more_30' => '',
                    'sick_days' => '',
                ];
            });
    }

    /**
     * Set headings for the columns in the template.
     */
    public function headings(): array
    {
        return [
            'ID karyawan',
            'Nama Lengkap',
            'Periode',
            'Hari Kerja',
            'Late Less 30 min',
            'Late More 30 min',
            'Sakit or Izin',
        ];
    }
}
