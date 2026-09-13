<?php

namespace App\Exports;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class AttendanceExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    /**
     * Mengambil data koleksi untuk ekspor
     *
     * @return Collection
     */
    public function collection(): Enumerable
    {
        // Ambil semua data attendance yang diperlukan
        return Attendance::with('user') // Pastikan relasi dengan model User tersedia
            ->get()
            ->map(function ($attendance) {
                return [
                    'employee_id' => $attendance->user->employee_id ?? '-',
                    'nama_lengkap' => $attendance->user->nama_lengkap ?? '-',
                    'periode' => $attendance->periode,
                    'work_days' => $attendance->work_days,
                    'late_less_30' => $attendance->late_less_30,
                    'late_more_30' => $attendance->late_more_30,
                    'sick_days' => $attendance->sick_days,
                ];
            });
    }

    /**
     * Menentukan heading atau judul kolom di Excel
     */
    public function headings(): array
    {
        return [
            'ID Karyawan',
            'Nama Lengkap',
            'Periode',
            'Hari Kerja',
            'Late Less 30 min',
            'Late More 30 min',
            'Sakit or Izin',
        ];
    }

    /**
     * Menentukan bagaimana data setiap baris akan dipetakan ke dalam array
     *
     * @param  array  $attendance
     */
    public function map(mixed $attendance): array
    {
        return [
            $attendance['employee_id'],      // ID Karyawan
            $attendance['nama_lengkap'],     // Nama Lengkap
            $attendance['periode'],          // Periode
            $attendance['work_days'],        // Hari Kerja
            $attendance['late_less_30'],     // Late Less 30 min
            $attendance['late_more_30'],     // Late More 30 min
            $attendance['sick_days'],        // Sakit or Izin
        ];
    }

    /**
     * Menentukan nama sheet untuk file Excel
     */
    public function title(): string
    {
        return 'Attendance Data';
    }
}
