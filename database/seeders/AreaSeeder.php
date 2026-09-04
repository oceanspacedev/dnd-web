<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Seeder;

class AreaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
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
