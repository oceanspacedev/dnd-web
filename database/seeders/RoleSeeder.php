<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Role::insert([
            [
                'name' => 'ADMIN'
            ],
            [
                'name' => 'STAFF'
            ],
            [
                'name' => 'TEAM LEADER'
            ],
            [
                'name' => 'COORDINATOR'
            ],
            [
                'name' => 'MANAGER'
            ],
            [
                'name' => 'CHIEF'
            ],
            [
                'name' => 'BOD'
            ],
        ]);
    }
}
