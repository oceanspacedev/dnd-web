<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'nama_lengkap' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role_id' => 1,
            'area_id' => 1,
            'divisi_id' => 1,
            'd' => true,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
            'remember_token' => Str::random(10),
        ];
    }
}
