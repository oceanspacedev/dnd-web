<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use App\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class UsersExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @return Collection
     */
    public function collection()
    {
        return User::with('approval', 'area', 'position', 'role', 'divisi')
            ->whereNull('deleted_at') // Hanya mengambil data yang tidak dihapus
            ->orderBy('nama_lengkap')
            ->get();
    }

    public function headings(): array
    {
        return [
            'nama_lengkap',
            'username',
            'id_karyawan',
            'position',
            'role',
            'area',
            'divisi',
            'dr',
            'wn',
            'wr',
            'mn',
            'mr',
            'approval'
        ];
    }

    public function map($user): array
    {
        return [
            $user->nama_lengkap,
            $user->username,
            $user->employee_id ?? null,
            $user->position->name ?? null,
            $user->role->name,
            $user->area->name,
            $user->divisi->name,
            $user->dr ? 'YES' : 'NO',
            $user->wn ? 'YES' : 'NO',
            $user->wr ? 'YES' : 'NO',
            $user->mn ? 'YES' : 'NO',
            $user->mr ? 'YES' : 'NO',
            $user->approval->nama_lengkap ?? '-',
        ];
    }
}
