<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TemplateExport implements FromCollection, WithHeadings
{
    public function collection(): Collection
    {
        return collect([
            [
                'nama_lengkap' => 'KUSUMA CHANDRA',
                'username' => 'kusumachandra',
                'id_karyawan' => '25.02.001',
                'no_hp' => '081234567890',
                'email' => 'kusuma.chandra@example.com',
                'role' => 'MANAGER',
                'area' => 'ULS',
                'divisi' => 'RETAIL',
                'position' => 'ULS - GENERAL MANAGER',
                'approval' => '',
                'password' => 'Msg@NEXUJ332',
            ],
            [
                'nama_lengkap' => 'NUR HASNI',
                'username' => 'nurhasni',
                'id_karyawan' => '24.12.002',
                'no_hp' => '081298765432',
                'email' => 'nur.hasni@example.com',
                'role' => 'COORDINATOR',
                'area' => 'COO',
                'divisi' => 'SCM',
                'position' => 'SUPERVISOR PURCHASING',
                'approval' => 'ERNIATI',
                'password' => 'Msg@FXN2UQOG',
            ],
        ]);
    }

    public function headings(): array
    {
        return [
            'nama_lengkap',
            'username',
            'id_karyawan',
            'no_hp',
            'email',
            'role',
            'area',
            'divisi',
            'position',
            'approval',
            'password',
        ];
    }
}
