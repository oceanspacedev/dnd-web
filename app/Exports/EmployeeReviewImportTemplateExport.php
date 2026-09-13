<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class EmployeeReviewImportTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function collection(): Enumerable
    {
        $currentPeriod = Date::now()->format('Y-m'); // Current month in YYYY-MM format

        return User::whereNull('deleted_at')
            ->when(auth()->user()->role !== 'admin', function ($query) {
                return $query->where('approval_id', Auth::id());
            })
            ->with('position')
            ->get()
            ->map(function ($user) use ($currentPeriod) {
                return [
                    'employee_id' => $user->employee_id,
                    'nama_lengkap' => $user->nama_lengkap,
                    // 'posisi' => $user->position->name,
                    'periode' => $currentPeriod,
                    'responsiveness' => '',
                    'problem_solver' => '',
                    'helpfulness' => '',
                    'initiative' => '',
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
            // 'Posisi',
            'Periode',
            'Responsiveness',
            'Problem solver',
            'Helpfulness',
            'Initiative',
        ];
    }
}
