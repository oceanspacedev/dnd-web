<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        User::insert([
            [
                'nama_lengkap' => 'ADMIN',
                'username' => 'admin',
                'password' => bcrypt('complete123'),
                'role_id' => 1,
                'area_id' => 9,
                'divisi_id' => 17,
                'd' => true,
                'wn' => false,
                'wr' => false,
                'mn' => false,
                'mr' => false,
                'approval_id' => 1,
            ],
            [
                'nama_lengkap' => 'FIRMAN SYAHBANA',
                'username' => 'firman',
                'password' => bcrypt('complete123'),
                'role_id' => 6,
                'area_id' => 1,
                'divisi_id' => 1,
                'd' => true,
                'wn' => true,
                'wr' => true,
                'mn' => true,
                'mr' => true,
                'approval_id' => 1,
            ],
            [
                'nama_lengkap' => 'ARIK CAHYA HIDAYAT',
                'username' => 'arik',
                'password' => bcrypt('complete123'),
                'role_id' => 4,
                'area_id' => 1,
                'divisi_id' => 4,
                'd' => true,
                'wn' => true,
                'wr' => true,
                'mn' => true,
                'mr' => true,
                'approval_id' => 2,
            ],
            [
                'nama_lengkap' => 'USEP HERMANTO',
                'username' => 'usep',
                'password' => bcrypt('complete123'),
                'role_id' => 2,
                'area_id' => 1,
                'divisi_id' => 4,
                'd' => true,
                'wn' => true,
                'wr' => false,
                'mn' => false,
                'mr' => false,
                'approval_id' => 3,
            ],
            [
                'nama_lengkap' => 'FARID ARDHYANSYAH',
                'username' => 'farid',
                'password' => bcrypt('complete123'),
                'role_id' => 2,
                'area_id' => 1,
                'divisi_id' => 4,
                'd' => true,
                'wn' => true,
                'wr' => false,
                'mn' => false,
                'mr' => false,
                'approval_id' => 3,
            ],
            [
                'nama_lengkap' => 'SANDI DWI HERMANWAN',
                'username' => 'sandi',
                'password' => bcrypt('complete123'),
                'role_id' => 2,
                'area_id' => 1,
                'divisi_id' => 4,
                'd' => true,
                'wn' => true,
                'wr' => false,
                'mn' => false,
                'mr' => false,
                'approval_id' => 3,
            ],
            [
                'nama_lengkap' => 'AGAN ERSYAD FERMANA',
                'username' => 'agan',
                'password' => bcrypt('complete123'),
                'role_id' => 2,
                'area_id' => 1,
                'divisi_id' => 4,
                'd' => true,
                'wn' => true,
                'wr' => false,
                'mn' => false,
                'mr' => false,
                'approval_id' => 3,
            ]
        ]);
    }
}
