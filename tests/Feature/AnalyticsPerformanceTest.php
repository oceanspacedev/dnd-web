<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Models\Area;
use App\Models\Divisi;
use App\Models\Kpi;
use App\Models\KpiDetail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class AnalyticsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_scores_are_aggregated_in_sql_with_exact_month_boundaries(): void
    {
        $area = Area::create(['name' => 'Analytics Area']);
        $division = Divisi::create(['name' => 'Analytics Division', 'area_id' => $area->id]);
        $role = Role::create(['name' => 'ANALYTICS TEST']);
        $user = User::create([
            'nama_lengkap' => 'Analytics User',
            'username' => 'analytics-user',
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
        ]);

        $firstKpi = $this->createKpi($user, '2026-02-01 00:00:00');
        $lastKpi = $this->createKpi($user, '2026-02-28 23:59:59');
        $nextMonthKpi = $this->createKpi($user, '2026-03-01 00:00:00');

        $this->createKpiDetail($firstKpi, 20);
        $this->createKpiDetail($lastKpi, 30);
        $this->createKpiDetail($lastKpi, -5);
        $deletedDetail = $this->createKpiDetail($lastKpi, 200);
        $deletedDetail->delete();
        $this->createKpiDetail($nextMonthKpi, 100);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $controller = resolve(AnalyticsController::class);
        $method = new ReflectionMethod($controller, 'computeScoresForPeriod');
        $scores = $method->invoke($controller, '2026-02');

        $this->assertCount(1, $scores);
        $this->assertSame(45.0, $scores[0]['kpi_raw']);
        $this->assertEqualsWithDelta(31.5, $scores[0]['kpi_score_70pct'], 0.000_001);
        $this->assertCount(1, array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'sum(kpi_details.value_result)'),
        ));
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'date_format('),
        ));
    }

    private function createKpi(User $user, string $date): Kpi
    {
        return Kpi::create([
            'user_id' => $user->id,
            'kpi_category_id' => 1,
            'kpi_type_id' => 3,
            'date' => $date,
            'percentage' => 100,
        ]);
    }

    private function createKpiDetail(Kpi $kpi, float $result): KpiDetail
    {
        return KpiDetail::create([
            'kpi_id' => $kpi->id,
            'kpi_description_id' => 1,
            'value_result' => $result,
        ]);
    }
}
