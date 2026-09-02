<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Seeder;

class AreaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Area::insert([
            [
                'name' => 'COO',
            ],
            [
                'name' => 'CSO',
            ],
            [
                'name' => 'CME',
            ],
            [
                'name' => 'ANG',
            ],
            [
                'name' => 'AMZ',
            ],
            [
                'name' => 'CMM',
            ],
            [
                'name' => 'ANN',
            ],
            [
                'name' => 'DLP',
            ],
            [
                'name' => '-',
            ],
        ]);
    }
}
