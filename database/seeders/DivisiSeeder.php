<?php

namespace Database\Seeders;

use App\Models\Divisi;
use Illuminate\Database\Seeder;

class DivisiSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Divisi::insert([
            [
                'name' => 'FAM',
                'area_id' => 1,
            ],
            [
                'name' => 'MIS',
                'area_id' => 1,
            ],
            [
                'name' => 'HCM',
                'area_id' => 1,
            ],
            [
                'name' => 'BUSDEV',
                'area_id' => 1,
            ],
            [
                'name' => 'SCM',
                'area_id' => 1,
            ],
            [
                'name' => 'CIA',
                'area_id' => 1,
            ],
            [
                'name' => 'MARCOMM',
                'area_id' => 1,
            ],
            [
                'name' => 'RETAIL',
                'area_id' => 2,
            ],
            [
                'name' => 'DSM',
                'area_id' => 2,
            ],
            [
                'name' => 'ONLINE',
                'area_id' => 2,
            ],
            [
                'name' => 'COMPLETEME',
                'area_id' => 3,
            ],
            [
                'name' => 'ANGEL',
                'area_id' => 4,
            ],
            [
                'name' => 'AMAZY',
                'area_id' => 5,
            ],
            [
                'name' => 'COMPLETE MULIA',
                'area_id' => 6,
            ],
            [
                'name' => 'TK ANAN',
                'area_id' => 7,
            ],
            [
                'name' => 'DOLPHIN',
                'area_id' => 8,
            ],
            [
                'name' => '-',
                'area_id' => 9,
            ]
        ]);
    }
}
