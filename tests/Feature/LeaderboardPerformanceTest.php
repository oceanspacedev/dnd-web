<?php

namespace Tests\Feature;

use App\Filament\Widgets\LeaderboardKPI;
use App\Models\Area;
use App\Models\Cutpoint;
use App\Models\Divisi;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class LeaderboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cutpoints_are_aggregated_without_an_n_plus_one_query(): void
    {
        $area = Area::create(['name' => 'Performance Area']);
        $division = Divisi::create(['name' => 'Performance Division', 'area_id' => $area->id]);
        $role = Role::create(['name' => 'PERFORMANCE TEST']);

        $users = collect(range(1, 5))->map(fn (int $index) => User::create([
            'nama_lengkap' => "Performance User {$index}",
            'username' => "performance-user-{$index}",
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'area_id' => $area->id,
            'divisi_id' => $division->id,
            'd' => false,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]));

        Cutpoint::create([
            'user_id' => $users[0]->id,
            'periode' => '2026-02',
            'point' => 2,
        ]);
        Cutpoint::create([
            'user_id' => $users[0]->id,
            'periode' => '2026-02',
            'point' => 3,
        ]);
        $deletedCutpoint = Cutpoint::create([
            'user_id' => $users[0]->id,
            'periode' => '2026-02',
            'point' => 100,
        ]);
        $deletedCutpoint->delete();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $widget = resolve(LeaderboardKPI::class);
        $widget->mount(month: '2026-02');
        $method = new ReflectionMethod($widget, 'getLeaderboardData');
        $leaderboard = $method->invoke($widget);

        $target = collect($leaderboard)->first(
            fn (array $row): bool => $row['user']->id === $users[0]->id,
        );

        $this->assertSame(5, $target['cutpoint']);
        $this->assertCount(1, array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'cutpoints'),
        ));
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'date_format('),
        ));
    }
}
