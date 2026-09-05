<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Daily;
use App\Models\Divisi;
use App\Models\EmployeeReview;
use App\Models\Kpi;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\KpiDetail;
use App\Models\KpiType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkJournal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;

class LoadTestSeeder extends Seeder
{
    public function run(): void
    {
        if (Role::query()->count() === 0) {
            $this->call([
                RoleSeeder::class,
                AreaSeeder::class,
                DivisiSeeder::class,
            ]);
        }

        $password = Hash::make('complete123');
        $staffRoleId = (int) Role::query()->where('name', 'STAFF')->value('id');
        $managerRoleId = (int) Role::query()->where('name', 'MANAGER')->value('id');
        $adminRoleId = (int) Role::query()->where('name', 'ADMIN')->value('id');
        $divisions = Divisi::query()->get(['id', 'area_id']);

        if ($divisions->isEmpty() || $staffRoleId === 0 || $adminRoleId === 0) {
            throw new \RuntimeException('LoadTestSeeder membutuhkan role dan divisi.');
        }

        $admin = User::query()->firstOrCreate(
            ['username' => 'admin'],
            [
                'nama_lengkap' => 'ADMIN',
                'password' => $password,
                'role_id' => $adminRoleId,
                'area_id' => (int) $divisions->first()->area_id,
                'divisi_id' => (int) $divisions->first()->id,
                'd' => true,
                'dr' => false,
                'wn' => false,
                'wr' => false,
                'mn' => false,
                'mr' => false,
                'approval_id' => 1,
            ],
        );

        User::query()->firstOrCreate(
            ['username' => 'loadmanager'],
            [
                'nama_lengkap' => 'Load Manager',
                'password' => $password,
                'role_id' => $managerRoleId ?: $staffRoleId,
                'area_id' => (int) $divisions->first()->area_id,
                'divisi_id' => (int) $divisions->first()->id,
                'd' => true,
                'dr' => false,
                'wn' => true,
                'wr' => true,
                'mn' => true,
                'mr' => true,
                'approval_id' => $admin->id,
            ],
        );

        for ($i = 1; $i <= 80; $i++) {
            $division = $divisions[($i - 1) % $divisions->count()];
            User::query()->firstOrCreate(
                ['username' => sprintf('loadstaff%03d', $i)],
                [
                    'nama_lengkap' => 'Load Staff '.$i,
                    'password' => $password,
                    'role_id' => $staffRoleId,
                    'area_id' => (int) $division->area_id,
                    'divisi_id' => (int) $division->id,
                    'd' => true,
                    'dr' => false,
                    'wn' => true,
                    'wr' => false,
                    'mn' => false,
                    'mr' => false,
                    'approval_id' => $admin->id,
                ],
            );
        }

        if (KpiType::query()->count() === 0) {
            KpiType::query()->insert([
                ['name' => 'Harian', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Mingguan', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Bulanan', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        $category = KpiCategory::query()->firstOrCreate(
            ['name' => 'Load Quality'],
            ['name' => 'Load Quality'],
        );

        if (KpiDescription::query()->where('kpi_category_id', $category->id)->count() < 5) {
            foreach (['Ketepatan waktu', 'Kualitas output', 'Kolaborasi', 'Inisiatif', 'Kepatuhan proses'] as $description) {
                KpiDescription::query()->firstOrCreate([
                    'kpi_category_id' => $category->id,
                    'description' => $description,
                ]);
            }
        }

        $descriptionIds = KpiDescription::query()
            ->where('kpi_category_id', $category->id)
            ->orderBy('id')
            ->limit(5)
            ->pluck('id')
            ->all();
        $typeId = (int) (KpiType::query()->orderBy('id')->skip(2)->value('id') ?: KpiType::query()->value('id'));
        $period = Date::now()->format('Y-m');
        $periodStart = Date::now()->startOfMonth();
        $users = User::query()
            ->where(function ($query): void {
                $query->where('username', 'admin')
                    ->orWhere('username', 'like', 'load%');
            })
            ->get();

        foreach ($users as $user) {
            $kpi = Kpi::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'kpi_type_id' => $typeId,
                    'date' => $periodStart,
                ],
                [
                    'kpi_category_id' => $category->id,
                    'percentage' => 100,
                ],
            );

            if ($kpi->kpi_detail()->count() === 0) {
                foreach ($descriptionIds as $index => $descriptionId) {
                    KpiDetail::query()->create([
                        'kpi_id' => $kpi->id,
                        'kpi_description_id' => $descriptionId,
                        'count_type' => 'NON',
                        'value_plan' => 100,
                        'value_actual' => 70 + (($user->id + $index) % 30),
                        'value_result' => 8 + (($user->id + $index) % 12),
                    ]);
                }
            }

            Attendance::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'periode' => $period,
                ],
                [
                    'work_days' => 22,
                    'late_less_30' => $user->id % 4,
                    'late_more_30' => $user->id % 3,
                    'sick_days' => $user->id % 2,
                ],
            );

            EmployeeReview::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'periode' => $period,
                ],
                [
                    'responsiveness' => 3 + ($user->id % 3),
                    'problem_solver' => 2 + ($user->id % 4),
                    'helpfulness' => 3 + ($user->id % 3),
                    'initiative' => 2 + ($user->id % 4),
                ],
            );

            for ($day = 0; $day < 10; $day++) {
                WorkJournal::query()->firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'date' => Date::now()->subDays($day)->toDateString(),
                    ],
                    [
                        'activity' => 'Pekerjaan load test hari ke-'.$day,
                        'notes' => 'Catatan sintetis untuk stress test.',
                    ],
                );
            }

            Daily::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'task' => 'Task load test',
                ],
                [
                    'date' => Date::now(),
                    'status' => false,
                    'ontime' => 0,
                    'isplan' => true,
                    'isupdate' => false,
                ],
            );
        }
    }
}
