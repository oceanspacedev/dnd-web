<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Weekly;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;

class WeeklySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = Faker::create('id_ID');
        for ($m = 0; $m < 2; $m++) {
            for ($u = 2; $u < 8; $u++) {
                $user = User::find($u);
                if ($user->wn) {
                    for ($n = 0; $n < 5; $n++) {
                        Weekly::create([
                            'user_id' => $user->id,
                            'task' => $faker->sentence(3),
                            'week' => $m == 0 ? now()->startOfWeek()->weekOfYear: now()->startOfWeek()->addWeek(1)->weekOfYear,
                            'year' => date('Y'),
                            'tipe' => 'NON',
                            'status_non' => false,
                        ]);
                    }
                }
                if ($user->wr) {
                    for ($r = 1; $r < 4; $r++) {
                        Weekly::create([
                            'user_id' => $user->id,
                            'task' => $faker->sentence(3),
                            'week' => $m == 0 ? now()->startOfWeek()->weekOfYear : now()->startOfWeek()->addWeek(1)->weekOfYear,
                            'year' => date('Y'),
                            'tipe' => 'RESULT',
                            'value_plan' => $faker->randomDigitNotNull() . '00000',
                            'value_actual' => 0,
                            'status_result' => false,
                        ]);
                    }
                }
            }
        }
    }
}
