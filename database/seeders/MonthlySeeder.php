<?php

namespace Database\Seeders;

use App\Models\Monthly;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;
use App\Models\User;

class MonthlySeeder extends Seeder
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
            for ($u = 2; $u < 6; $u++) {
                $user = User::find($u);
                if ($user->mn) {
                    for ($n = 0; $n < 5; $n++) {
                        Monthly::create([
                            'user_id' => $user->id,
                            'task' => $faker->sentence(3),
                            'date' => $m == 0 ? now()->startOfMonth() : now()->addMonth(1)->startOfMonth(),
                            'tipe' => 'NON',
                            'status_non' => false,
                        ]);
                    }
                }
                if ($user->mr) {
                    for ($r = 1; $r < 4; $r++) {
                        Monthly::create([
                            'user_id' => $user->id,
                            'task' => $faker->sentence(3),
                            'date' => $m == 0 ? now()->startOfMonth() : now()->addMonth(1)->startOfMonth(),
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
