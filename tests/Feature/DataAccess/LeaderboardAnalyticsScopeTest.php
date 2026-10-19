<?php

namespace Tests\Feature\DataAccess;

use App\Exports\LeaderboardExport;
use App\Exports\LeaderboardSummarySheet;
use App\Filament\Widgets\LeaderboardKPI;
use App\Models\Area;
use App\Models\Attendance;
use App\Models\Daily;
use App\Models\Divisi;
use App\Models\Request as TodoRequest;
use App\Models\User;
use App\Services\LeaderboardScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use ReflectionMethod;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

class LeaderboardAnalyticsScopeTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private const PERIOD = '2026-02';

    private Area $coo;

    private Area $uls;

    private Area $gmd;

    private Area $outside;

    private User $superAdmin;

    private User $scopedAdmin;

    private User $manager;

    private User $ulsStaff;

    private User $gmdStaff;

    private User $cooStaff;

    private User $cooTeam;

    private User $managerReport;

    private User $outsideStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();
        Cache::flush();

        $this->coo = $this->fixtureArea('COO');
        $this->uls = $this->fixtureArea('ULS');
        $this->gmd = $this->fixtureArea('GMD');
        // Badan usaha tanpa akses maupun anggota tim admin per badan usaha.
        $this->outside = $this->fixtureArea('XYZ');

        $this->superAdmin = $this->fixtureGlobalAdmin('boss', $this->coo);
        $this->scopedAdmin = $this->fixtureScopedAdmin('ojan', $this->coo, [$this->uls, $this->gmd]);
        $this->manager = $this->fixtureUser('coo_manager', 'MANAGER', $this->coo);

        $this->ulsStaff = $this->fixtureUser('uls_staff', 'STAFF', $this->uls);
        $this->gmdStaff = $this->fixtureUser('gmd_staff', 'STAFF', $this->gmd);
        $this->cooStaff = $this->fixtureUser('coo_staff', 'STAFF', $this->coo);
        $this->cooTeam = $this->fixtureUser('coo_team', 'STAFF', $this->coo, $this->scopedAdmin);
        $this->managerReport = $this->fixtureUser('manager_report', 'STAFF', $this->coo, $this->manager);
        $this->outsideStaff = $this->fixtureUser('xyz_staff', 'STAFF', $this->outside);

        // Skor berbeda supaya urutan peringkat deterministik.
        $this->attendance($this->ulsStaff, 0);
        $this->attendance($this->gmdStaff, 1);
        $this->attendance($this->cooStaff, 2);
        $this->attendance($this->cooTeam, 3);
        $this->attendance($this->managerReport, 4);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_score_service_filters_shared_cache_by_allowed_areas_and_renumbers_ranks(): void
    {
        $service = resolve(LeaderboardScoreService::class);

        $all = $service->rankedScores(self::PERIOD);
        $this->assertCount(User::query()->count(), $all);
        $this->assertSame(range(1, count($all)), array_column($all, 'rank'));

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $scoped = $service->rankedScores(self::PERIOD, allowedAreaIds: [$this->uls->id, $this->gmd->id]);

        $this->assertSame([$this->ulsStaff->id, $this->gmdStaff->id], array_column($scoped, 'user_id'));
        $this->assertSame([1, 2], array_column($scoped, 'rank'));
        $this->assertCount(0, array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'kpi_details') || str_contains($sql, 'attendances'),
        ), 'Scoped read must reuse the shared cache entry.');

        $this->assertSame([], $service->rankedScores(self::PERIOD, $this->coo->id, allowedAreaIds: [$this->uls->id]));
        $this->assertSame([], $service->rankedScores(self::PERIOD, allowedAreaIds: []));
        $this->assertSame([], $service->rankedScores(self::PERIOD, allowedAreaIds: [], allowedUserIds: []));

        // Tim approval line + diri sendiri ikut terlihat walau badan usahanya di luar cakupan.
        $withTeam = $service->rankedScores(
            self::PERIOD,
            allowedAreaIds: [$this->uls->id, $this->gmd->id],
            allowedUserIds: [$this->cooTeam->id, $this->scopedAdmin->id],
        );
        $this->assertSame(
            [$this->ulsStaff->id, $this->gmdStaff->id, $this->cooTeam->id, $this->scopedAdmin->id],
            array_column($withTeam, 'user_id'),
        );
        $this->assertSame([1, 2, 3, 4], array_column($withTeam, 'rank'));

        // Filter badan usaha tim hanya menyisakan tim/dirinya, bukan seluruh karyawan badan usaha itu.
        $teamArea = $service->rankedScores(
            self::PERIOD,
            $this->coo->id,
            allowedAreaIds: [$this->uls->id],
            allowedUserIds: [$this->cooTeam->id, $this->scopedAdmin->id],
        );
        $this->assertSame([$this->cooTeam->id, $this->scopedAdmin->id], array_column($teamArea, 'user_id'));
        $this->assertSame([1, 2], array_column($teamArea, 'rank'));

        // Hanya user_ids (tanpa area) tetap membatasi, bukan membuka seluruh perusahaan.
        $this->assertSame(
            [$this->cooTeam->id],
            array_column($service->rankedScores(self::PERIOD, allowedUserIds: [$this->cooTeam->id]), 'user_id'),
        );

        // Cache tetap lintas viewer: hasil tanpa cakupan tidak ikut terpotong.
        $this->assertCount(count($all), $service->rankedScores(self::PERIOD));
    }

    public function test_viewer_area_scope_only_restricts_area_scoped_admins(): void
    {
        $this->assertNull(LeaderboardScoreService::viewerAreaScope(null));
        $this->assertNull(LeaderboardScoreService::viewerAreaScope($this->superAdmin));
        $this->assertNull(LeaderboardScoreService::viewerAreaScope($this->manager));
        $this->assertNull(LeaderboardScoreService::viewerAreaScope($this->cooStaff));
        $this->assertEqualsCanonicalizing(
            [$this->uls->id, $this->gmd->id],
            LeaderboardScoreService::viewerAreaScope($this->scopedAdmin),
        );

        $this->assertNull(LeaderboardScoreService::viewerScope(null));
        $this->assertNull(LeaderboardScoreService::viewerScope($this->superAdmin));
        $this->assertNull(LeaderboardScoreService::viewerScope($this->manager));
        $this->assertNull(LeaderboardScoreService::viewerScope($this->cooStaff));

        $scope = LeaderboardScoreService::viewerScope($this->scopedAdmin);
        $this->assertEqualsCanonicalizing([$this->uls->id, $this->gmd->id], $scope['area_ids']);
        $this->assertEqualsCanonicalizing([$this->cooTeam->id, $this->scopedAdmin->id], $scope['user_ids']);
        $this->assertEqualsCanonicalizing(
            [$this->uls->id, $this->gmd->id, $this->coo->id],
            LeaderboardScoreService::selectableAreaIds($scope),
        );
    }

    public function test_api_leaderboard_is_limited_to_allowed_areas_for_scoped_admin(): void
    {
        Sanctum::actingAs($this->scopedAdmin);

        $response = $this->getJson('/api/v1/analytics/leaderboard?periode='.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('meta.total', 4);

        // Badan usaha yang diizinkan + tim approval line + dirinya sendiri.
        $this->assertSame(
            [$this->ulsStaff->id, $this->gmdStaff->id, $this->cooTeam->id, $this->scopedAdmin->id],
            array_column(array_column($response->json('data'), 'user'), 'id'),
        );
        $this->assertSame([1, 2, 3, 4], array_column($response->json('data'), 'rank'));

        $this->getJson('/api/v1/analytics/leaderboard?periode='.self::PERIOD.'&area_id='.$this->gmd->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user.id', $this->gmdStaff->id)
            ->assertJsonPath('data.0.rank', 1);

        // Badan usaha tim: hanya tim/dirinya, karyawan COO lain tetap tersembunyi.
        $response = $this->getJson('/api/v1/analytics/leaderboard?periode='.self::PERIOD.'&area_id='.$this->coo->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->assertSame([$this->cooTeam->id, $this->scopedAdmin->id], array_column(array_column($response->json('data'), 'user'), 'id'));
        $this->assertSame([1, 2], array_column($response->json('data'), 'rank'));

        $this->getJson('/api/v1/analytics/leaderboard?periode='.self::PERIOD.'&area_id='.$this->outside->id)
            ->assertForbidden();
    }

    public function test_api_leaderboard_stays_company_wide_for_super_admin_and_non_admin(): void
    {
        $total = User::query()->count();

        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/v1/analytics/leaderboard?periode='.self::PERIOD.'&per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', $total);

        Sanctum::actingAs($this->cooStaff);
        $this->getJson('/api/v1/analytics/leaderboard?periode='.self::PERIOD.'&per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', $total);
        $this->getJson('/api/v1/analytics/leaderboard?periode='.self::PERIOD.'&area_id='.$this->uls->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user.id', $this->ulsStaff->id);
    }

    public function test_api_dashboard_counters_are_scoped_for_scoped_admin_only(): void
    {
        $this->daily($this->ulsStaff, true);
        $this->daily($this->cooStaff, false);
        $this->daily($this->cooTeam, false);
        $this->daily($this->scopedAdmin, true);
        $this->daily($this->gmdStaff, false);

        $this->pendingRequest($this->ulsStaff);
        $this->pendingRequest($this->gmdStaff);
        $this->pendingRequest($this->cooStaff);
        $this->pendingRequest($this->cooTeam);
        $this->pendingRequest($this->ulsStaff, 'APPROVED');

        // Data karyawan yang sudah di-soft-delete tetap terhitung (subquery withTrashed).
        $this->gmdStaff->delete();
        $this->resetAccessMemos();

        Sanctum::actingAs($this->scopedAdmin);
        $response = $this->getJson('/api/v1/analytics/dashboard?periode='.self::PERIOD)
            ->assertOk()
            // Skor dan penghitung memakai cakupan yang sama: ULS/GMD + tim (coo_team) + dirinya.
            ->assertJsonPath('data.company_overview.total_active_employees', 3)
            ->assertJsonPath('data.daily_activities_today.total_tasks', 4)
            ->assertJsonPath('data.daily_activities_today.completed_tasks', 2)
            ->assertJsonPath('data.pending_requests_count', 3)
            ->assertJsonPath('data.top_performers.0.user_id', $this->ulsStaff->id)
            ->assertJsonCount(3, 'data.top_performers');
        $this->assertSame(
            [$this->ulsStaff->id, $this->cooTeam->id, $this->scopedAdmin->id],
            array_column($response->json('data.top_performers'), 'user_id'),
        );

        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/v1/analytics/dashboard?periode='.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('data.company_overview.total_active_employees', User::query()->count())
            ->assertJsonPath('data.daily_activities_today.total_tasks', 5)
            ->assertJsonPath('data.pending_requests_count', 4);

        // Non-admin: perilaku lama (angka seluruh perusahaan) tidak berubah.
        Sanctum::actingAs($this->cooStaff);
        $this->getJson('/api/v1/analytics/dashboard?periode='.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('data.company_overview.total_active_employees', User::query()->count())
            ->assertJsonPath('data.daily_activities_today.total_tasks', 5)
            ->assertJsonPath('data.pending_requests_count', 4);
    }

    public function test_api_department_stats_only_group_allowed_areas_for_scoped_admin(): void
    {
        Sanctum::actingAs($this->scopedAdmin);
        $response = $this->getJson('/api/v1/analytics/department-stats?periode='.self::PERIOD)->assertOk();

        // COO hanya berisi tim (coo_team) dan dirinya, bukan seluruh karyawan COO.
        $byArea = array_column($response->json('data.by_area'), 'employee_count', 'name');
        $this->assertEqualsCanonicalizing(['ULS', 'GMD', 'COO'], array_keys($byArea));
        $this->assertSame(2, $byArea['COO']);
        $this->assertSame(4, array_sum(array_column($response->json('data.by_division'), 'employee_count')));

        Sanctum::actingAs($this->superAdmin);
        $response = $this->getJson('/api/v1/analytics/department-stats?periode='.self::PERIOD)->assertOk();

        $this->assertEqualsCanonicalizing(['COO', 'ULS', 'GMD', 'XYZ'], array_column($response->json('data.by_area'), 'name'));
    }

    public function test_widget_scopes_options_rows_and_rejects_out_of_scope_filters_for_scoped_admin(): void
    {
        // Divisi milik COO (divisis.area_id) tetapi berisi karyawan ULS: harus muncul untuk admin ULS.
        $crossDivision = Divisi::query()->create(['name' => 'LINTAS', 'area_id' => $this->coo->id]);
        $crossStaff = $this->fixtureUser('cross_staff', 'STAFF', $this->uls, null, ['divisi_id' => $crossDivision->id]);
        // Divisi COO yang tidak berisi tim/dirinya: tetap di luar cakupan.
        $cooOnlyDivision = $this->fixtureDivisi($this->coo, 'KHUSUS');
        $this->fixtureUser('coo_special', 'STAFF', $this->coo, null, ['divisi_id' => $cooOnlyDivision->id]);
        $outsideDivision = $this->fixtureDivisi($this->outside);
        $this->resetAccessMemos();

        $this->actingAs($this->scopedAdmin);

        $component = Livewire::test(LeaderboardKPI::class, ['month' => self::PERIOD])
            ->assertSuccessful()
            ->assertSee('ULS STAFF')
            ->assertSee('GMD STAFF')
            ->assertSee('COO TEAM')
            ->assertSee('OJAN')
            ->assertDontSee('COO STAFF')
            ->assertDontSee('COO SPECIAL')
            ->assertDontSee('MANAGER REPORT')
            ->assertDontSee('XYZ STAFF');

        $widget = $component->instance();

        // Badan usaha yang diizinkan + badan usaha tim/dirinya (COO).
        $this->assertEqualsCanonicalizing(
            [$this->uls->id, $this->gmd->id, $this->coo->id],
            $this->callProtected($widget, 'getAreas')->pluck('id')->all(),
        );

        $divisionIds = $this->callProtected($widget, 'getDivisions')->pluck('id')->all();
        $this->assertContains($crossDivision->id, $divisionIds);
        $this->assertContains($this->fixtureDivisi($this->uls)->id, $divisionIds);
        $this->assertContains($this->fixtureDivisi($this->coo)->id, $divisionIds, 'Divisi tim harus bisa dipilih.');
        $this->assertNotContains($cooOnlyDivision->id, $divisionIds);
        $this->assertNotContains($outsideDivision->id, $divisionIds);

        $rows = $this->callProtected($widget, 'getLeaderboardData');
        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->gmdStaff->id, $crossStaff->id, $this->cooTeam->id, $this->scopedAdmin->id],
            array_map(fn (array $row): int => $row['user']->id, $rows),
        );

        // Prop publik Livewire diubah klien ke badan usaha di luar cakupan: dikosongkan di server.
        $component->set('area', (string) $this->outside->id)
            ->assertSet('area', '')
            ->assertDontSee('XYZ STAFF')
            ->assertDontSee('COO STAFF');

        $component->set('division', (string) $cooOnlyDivision->id)
            ->assertSet('division', '')
            ->assertDontSee('COO SPECIAL');

        // Badan usaha tim: hanya tim/dirinya yang tampil.
        $component->set('area', (string) $this->coo->id)
            ->assertSet('area', (string) $this->coo->id)
            ->assertSee('COO TEAM')
            ->assertSee('OJAN')
            ->assertDontSee('COO STAFF')
            ->assertDontSee('MANAGER REPORT')
            ->assertDontSee('ULS STAFF');

        $component->set('division', (string) $cooOnlyDivision->id)
            ->assertSet('division', '')
            ->assertDontSee('COO SPECIAL');

        $component->set('area', (string) $this->uls->id)
            ->assertSet('area', (string) $this->uls->id)
            ->assertSee('ULS STAFF')
            ->assertDontSee('GMD STAFF')
            ->assertDontSee('COO TEAM');

        $component->set('division', (string) $crossDivision->id)
            ->assertSet('division', (string) $crossDivision->id)
            ->assertSee('CROSS STAFF')
            ->assertDontSee('ULS STAFF');
    }

    public function test_widget_is_company_wide_for_super_admin_and_non_admin(): void
    {
        $total = User::query()->count();

        foreach ([$this->superAdmin, $this->cooStaff] as $viewer) {
            $this->resetAccessMemos();
            $this->actingAs($viewer);

            $component = Livewire::test(LeaderboardKPI::class, ['month' => self::PERIOD])
                ->assertSuccessful()
                ->assertSee('COO STAFF')
                ->assertSee('ULS STAFF');

            $widget = $component->instance();
            $this->assertCount(4, $this->callProtected($widget, 'getAreas'));
            $this->assertCount($total, $this->callProtected($widget, 'getLeaderboardData'));

            $component->set('area', (string) $this->coo->id)
                ->assertSet('area', (string) $this->coo->id)
                ->assertSee('COO STAFF')
                ->assertDontSee('ULS STAFF');
        }
    }

    public function test_export_summary_and_details_are_limited_to_allowed_areas_for_scoped_admin(): void
    {
        Excel::fake();
        $this->actingAs($this->scopedAdmin);

        $this->get(route('leaderboard.export', ['month' => self::PERIOD]))->assertOk();

        Excel::assertDownloaded('leaderboard.xlsx', function (LeaderboardExport $export): bool {
            // Pengguna ber-role ADMIN (termasuk dirinya) tetap dikeluarkan dari ringkasan seperti sebelumnya.
            $this->assertEqualsCanonicalizing(
                [$this->ulsStaff->id, $this->gmdStaff->id, $this->cooTeam->id],
                $this->summaryUserIds($export),
            );
            $this->assertCount(4, $export->sheets());
            // Detail juga mencakup tim approval line-nya di badan usaha lain.
            $this->assertEqualsCanonicalizing(
                [$this->ulsStaff->id, $this->gmdStaff->id, $this->cooTeam->id],
                $this->exportProperty($export, 'attendanceDetails')->pluck('user_id')->unique()->all(),
            );

            return true;
        });

        // Badan usaha tim boleh difilter, tetapi hanya menyisakan tim/dirinya.
        Excel::fake();
        $this->get(route('leaderboard.export', ['month' => self::PERIOD, 'area' => $this->coo->id]))->assertOk();

        Excel::assertDownloaded('leaderboard.xlsx', function (LeaderboardExport $export): bool {
            $this->assertSame([$this->cooTeam->id], $this->summaryUserIds($export));
            $this->assertSame(
                [$this->cooTeam->id],
                $this->exportProperty($export, 'attendanceDetails')->pluck('user_id')->unique()->values()->all(),
            );

            return true;
        });

        $this->get(route('leaderboard.export', ['month' => self::PERIOD, 'area' => $this->outside->id]))
            ->assertForbidden();
    }

    public function test_export_yearly_summary_is_limited_to_allowed_areas_for_scoped_admin(): void
    {
        Excel::fake();
        $this->actingAs($this->scopedAdmin);

        $this->get(route('leaderboard.export', ['period_type' => 'year', 'year' => '2026']))->assertOk();

        Excel::assertDownloaded('leaderboard.xlsx', function (LeaderboardExport $export): bool {
            $this->assertEqualsCanonicalizing(
                [$this->ulsStaff->id, $this->gmdStaff->id, $this->cooTeam->id],
                $this->summaryUserIds($export),
            );
            $monthlyNames = array_unique(array_column($this->exportProperty($export, 'monthlySummaryRows'), 2));
            $this->assertEqualsCanonicalizing(['ULS STAFF', 'GMD STAFF', 'COO TEAM'], $monthlyNames);

            return true;
        });
    }

    public function test_api_export_uses_the_sanctum_actor_scope(): void
    {
        Excel::fake();
        Sanctum::actingAs($this->scopedAdmin);

        $this->get('/api/v1/analytics/leaderboard/export?periode='.self::PERIOD, ['Accept' => 'application/json'])
            ->assertOk();

        Excel::assertDownloaded('leaderboard.xlsx', function (LeaderboardExport $export): bool {
            $this->assertEqualsCanonicalizing(
                [$this->ulsStaff->id, $this->gmdStaff->id, $this->cooTeam->id],
                $this->summaryUserIds($export),
            );

            return true;
        });

        $this->get('/api/v1/analytics/leaderboard/export?periode='.self::PERIOD.'&area='.$this->outside->id, ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_export_keeps_company_summary_for_super_admin_and_team_details_for_non_admin(): void
    {
        $nonAdminIds = User::query()->whereKeyNot([$this->superAdmin->id, $this->scopedAdmin->id])->pluck('id')->all();

        Excel::fake();
        $this->actingAs($this->superAdmin);
        $this->get(route('leaderboard.export', ['month' => self::PERIOD]))->assertOk();

        Excel::assertDownloaded('leaderboard.xlsx', function (LeaderboardExport $export) use ($nonAdminIds): bool {
            // Pengguna ber-role ADMIN tetap dikeluarkan dari ringkasan seperti sebelumnya.
            $this->assertEqualsCanonicalizing($nonAdminIds, $this->summaryUserIds($export));
            $this->assertCount(5, $this->exportProperty($export, 'attendanceDetails'));

            return true;
        });

        Excel::fake();
        $this->resetAccessMemos();
        $this->actingAs($this->manager);
        $this->get(route('leaderboard.export', ['month' => self::PERIOD]))->assertOk();

        Excel::assertDownloaded('leaderboard.xlsx', function (LeaderboardExport $export) use ($nonAdminIds): bool {
            $this->assertEqualsCanonicalizing($nonAdminIds, $this->summaryUserIds($export));
            $this->assertSame(
                [$this->managerReport->id],
                $this->exportProperty($export, 'attendanceDetails')->pluck('user_id')->unique()->values()->all(),
            );

            return true;
        });

        // Non-admin tetap bisa memfilter area mana pun (perilaku lama).
        Excel::fake();
        $this->get(route('leaderboard.export', ['month' => self::PERIOD, 'area' => $this->uls->id]))->assertOk();

        Excel::assertDownloaded('leaderboard.xlsx', function (LeaderboardExport $export): bool {
            $this->assertSame([$this->ulsStaff->id], $this->summaryUserIds($export));
            $this->assertCount(1, $export->sheets());

            return true;
        });
    }

    private function attendance(User $user, int $lateLess30): void
    {
        Attendance::query()->create([
            'user_id' => $user->id,
            'periode' => self::PERIOD,
            'work_days' => 20,
            'late_less_30' => $lateLess30,
            'late_more_30' => 0,
            'sick_days' => 0,
        ]);
    }

    private function daily(User $user, bool $done): void
    {
        Daily::query()->create([
            'user_id' => $user->id,
            'date' => now(),
            'task' => 'Tugas '.$user->username,
            'status' => $done,
        ]);
    }

    private function pendingRequest(User $user, string $status = 'PENDING'): void
    {
        TodoRequest::query()->create([
            'user_id' => $user->id,
            'jenistodo' => 'Daily',
            'todo_request' => 'Ganti tugas',
            'todo_replace' => 'Tugas baru',
            'approval_id' => $this->superAdmin->id,
            'status' => $status,
        ]);
    }

    /**
     * @return array<int>
     */
    private function summaryUserIds(LeaderboardExport $export): array
    {
        $summary = $export->sheets()[0];
        $this->assertInstanceOf(LeaderboardSummarySheet::class, $summary);

        return $summary->collection()->map(fn (array $row): int => $row['user']->id)->values()->all();
    }

    private function exportProperty(LeaderboardExport $export, string $property): mixed
    {
        return (fn () => $this->{$property})->call($export);
    }

    private function callProtected(object $instance, string $method): mixed
    {
        return (new ReflectionMethod($instance, $method))->invoke($instance);
    }
}
