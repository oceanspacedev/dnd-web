<?php

namespace Database\Seeders;

use App\Models\Daily;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

class DailySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create('id_ID');
        for ($m = 0; $m < 2; $m++) {
            for ($u = 2; $u < 8; $u++) {
                for ($i = 0; $i < 6; $i++) {
                    for ($j = 1; $j < 6; $j++) {
                        Daily::create(
                            [
                                'user_id' => $u,
                                'task' => $faker->sentence(3),
                                'date' => $i === 0 && $m === 0
                                    ? now()->startOfWeek()
                                    : ($m === 0
                                        ? now()->startOfWeek()->addDay($i)
                                        : now()->startOfWeek()->addWeek($m)->addDay($i)),
                                'time' => (9 + $j).':00',
                            ],
                        );
                    }
                }
            }
        }
    }
}
