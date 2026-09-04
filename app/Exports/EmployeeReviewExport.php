<?php

namespace App\Exports;

use App\Models\EmployeeReview;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class EmployeeReviewExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    /**
     * @return Collection
     */
    public function collection(): Enumerable
    {
        $authUser = auth()->user();

        if (! $authUser) {
            return collect();
        }

        $authUserId = (int) $authUser->id;
        $query = EmployeeReview::query()->with('user');

        if ($authUser->role?->name !== 'ADMIN') {
            $query->whereHas('user', function ($query) use ($authUserId) {
                $query->where('approval_id', $authUserId);
            });
        }

        return $query
            ->get()
            ->map(function ($review) {
                return [
                    'employee_id' => $review->user->employee_id ?? '-',
                    'nama_lengkap' => $review->user->nama_lengkap ?? '-',
                    'periode' => $review->periode,
                    'responsiveness' => $review->responsiveness,
                    'problem_solver' => $review->problem_solver,
                    'helpfulness' => $review->helpfulness,
                    'initiative' => $review->initiative,
                ];
            });
    }

    public function headings(): array
    {
        return [
            'ID Karyawan',
            'Nama Lengkap',
            'Periode',
            'Responsiveness',
            'Problem Solver',
            'Helpfulness',
            'Initiative',
        ];
    }

    /**
     * @param  array  $review
     */
    public function map(mixed $review): array
    {
        return [
            $review['employee_id'],
            $review['nama_lengkap'],
            $review['periode'],
            $review['responsiveness'],
            $review['problem_solver'],
            $review['helpfulness'],
            $review['initiative'],
        ];
    }

    public function title(): string
    {
        return 'Employee Review';
    }
}
