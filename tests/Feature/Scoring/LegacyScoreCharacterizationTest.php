<?php

namespace Tests\Feature\Scoring;

use App\Exports\LeaderboardAttendanceDetailSheet;
use App\Exports\LeaderboardEmployeeReviewDetailSheet;
use App\Exports\LeaderboardExport;
use App\Exports\LeaderboardKpiDetailSheet;
use App\Exports\LeaderboardMonthlySummarySheet;
use App\Exports\LeaderboardSummarySheet;
use App\Filament\Widgets\LeaderboardKPI;
use App\Models\Attendance;
use App\Models\Cutpoint;
use App\Models\EmployeeReview;
use App\Models\Kpi;
use App\Models\KpiDetail;
use App\Models\User;
use App\Services\LeaderboardScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use ReflectionMethod;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Golden test (characterization) nilai kinerja aturan lama untuk periode sebelum 2026-11.
 *
 * Ditulis terhadap kode SEBELUM aturan penilaian dibuat dinamis dan menjadi kontrak:
 * fase berikutnya wajib menjaga file ini tetap hijau TANPA mengubahnya ("nilai lama dipertahankan").
 *
 * Angka harapan dihitung di dalam tes dengan salinan rumus lama (bukan memanggil kode aplikasi).
 * Setiap permukaan sengaja dipertahankan dengan logikanya sendiri (agregasi KPI, cutpoint, grade
 * berbeda-beda antar permukaan); yang dikunci di sini adalah angka yang keluar dari masing-masing.
 *
 * Kolom sheet Excel detail dicari lewat judul kolom (bukan posisi) agar kolom baru (mis. izin/mangkir)
 * boleh ditambahkan; baris ringkasan bulanan tahunan dicek pada posisi 0..8 (kolom baru di belakang).
 *
 * Jam dibekukan SESUDAH aturan baru berlaku (Januari 2027): permukaan yang memilih aturan dari tanggal
 * hari ini (now()) dan bukan dari periode datanya akan mendapat versi November 2026 dan gagal di sini.
 * Fixture juga memuat Oktober 2026 (bulan terakhir aturan lama, untuk menangkap salah batas) dan periode
 * sampah 1900-01/1970-01 yang ada di produksi (harus tetap memakai aturan lama).
 */
class LegacyScoreCharacterizationTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private const PERIODE = '2026-09';

    private const YEAR = '2026';

    private const DELTA = 1e-9;

    /** Pengguna: kunci => [role, area]. 'boss' adalah super admin yang menjalankan tes. */
    private const USERS = [
        'boss' => ['ADMIN', 'COO'],
        'alpha' => ['STAFF', 'COO'],
        'bravo' => ['STAFF', 'COO'],
        'charlie' => ['STAFF', 'ULS'],
        'delta' => ['STAFF', 'ULS'],
        'echo' => ['STAFF', 'ULS'],
    ];

    /** counters = [work_days, late_less_30, late_more_30, sick_days]. */
    private const ATTENDANCES = [
        ['user' => 'boss', 'periode' => '2026-09', 'counters' => [20, 0, 0, 0]],
        ['user' => 'alpha', 'periode' => '2026-09', 'counters' => [22, 2, 1, 1]],
        ['user' => 'alpha', 'periode' => '2026-08', 'counters' => [21, 1, 0, 0]],
        // Penghitung melebihi hari kerja => base negatif.
        ['user' => 'bravo', 'periode' => '2026-09', 'counters' => [5, 4, 1, 2]],
        // Hari kerja 0 => skor kehadiran 0.
        ['user' => 'charlie', 'periode' => '2026-09', 'counters' => [0, 1, 0, 0]],
        ['user' => 'echo', 'periode' => '2026-09', 'counters' => [24, 6, 0, 0]],
        // Terhapus (soft delete) => delta dianggap tanpa data kehadiran.
        ['user' => 'delta', 'periode' => '2026-09', 'counters' => [20, 0, 0, 0], 'deleted' => true],
        // Oktober 2026: bulan terakhir aturan lama (sakit/izin tetap 5 poin, telat > 30 menit 3 poin).
        ['user' => 'bravo', 'periode' => '2026-10', 'counters' => [21, 1, 2, 1]],
        ['user' => 'echo', 'periode' => '2026-10', 'counters' => [22, 0, 1, 2]],
        ['user' => 'delta', 'periode' => '2026-10', 'counters' => [20, 3, 0, 1]],
        // Periode sampah di produksi: tetap aturan lama.
        ['user' => 'charlie', 'periode' => '1900-01', 'counters' => [20, 1, 0, 2]],
        ['user' => 'delta', 'periode' => '1970-01', 'counters' => [22, 0, 1, 1]],
    ];

    /** points = [responsiveness, problem_solver, helpfulness, initiative]. */
    private const REVIEWS = [
        ['user' => 'alpha', 'periode' => '2026-09', 'points' => [4, 5, 3, 4]],
        ['user' => 'alpha', 'periode' => '2026-08', 'points' => [2, 2, 2, 2]],
        ['user' => 'bravo', 'periode' => '2026-09', 'points' => [5, 5, 5, 5]],
        ['user' => 'charlie', 'periode' => '2026-09', 'points' => [0, 1, 2, 3]],
        ['user' => 'delta', 'periode' => '2026-09', 'points' => [3, 3, 3, 3]],
        ['user' => 'echo', 'periode' => '2026-09', 'points' => [5, 5, 5, 5], 'deleted' => true],
        ['user' => 'bravo', 'periode' => '2026-10', 'points' => [4, 4, 4, 4]],
        ['user' => 'echo', 'periode' => '2026-10', 'points' => [3, 4, 5, 2]],
        ['user' => 'delta', 'periode' => '2026-10', 'points' => [2, 3, 4, 5]],
        ['user' => 'charlie', 'periode' => '1900-01', 'points' => [1, 2, 3, 4]],
    ];

    /** Periode sampah yang ada di data produksi. */
    private const JUNK_PERIODES = ['1900-01', '1970-01'];

    /** type 3 = Bulanan (dipakai panel & export), tipe lain hanya terbaca oleh API. */
    private const KPIS = [
        ['user' => 'alpha', 'type' => 3, 'date' => '2026-09-05 10:00:00', 'percentage' => 60, 'details' => [1.0, 0.8, null, -0.5], 'deleted_details' => [9.0]],
        ['user' => 'alpha', 'type' => 3, 'date' => '2026-09-30 23:59:59', 'percentage' => 40, 'details' => [1.2, 0.9]],
        ['user' => 'alpha', 'type' => 1, 'date' => '2026-09-10 08:00:00', 'percentage' => 100, 'details' => [0.6]],
        ['user' => 'alpha', 'type' => 3, 'date' => '2026-10-01 00:00:00', 'percentage' => 100, 'details' => [1.0]],
        ['user' => 'alpha', 'type' => 3, 'date' => '2026-09-15 09:00:00', 'percentage' => 100, 'details' => [5.0], 'deleted' => true],
        ['user' => 'alpha', 'type' => 3, 'date' => '2026-08-20 09:00:00', 'percentage' => 100, 'details' => [0.8]],
        ['user' => 'bravo', 'type' => 3, 'date' => '2026-09-01 00:00:00', 'percentage' => 100, 'details' => [2.0, 1.5]],
        ['user' => 'bravo', 'type' => 1, 'date' => '2026-09-12 08:00:00', 'percentage' => 100, 'details' => [100.0]],
        ['user' => 'charlie', 'type' => 1, 'date' => '2026-09-03 08:00:00', 'percentage' => 50, 'details' => [0.4]],
        ['user' => 'delta', 'type' => 3, 'date' => '2026-09-08 08:00:00', 'percentage' => 100, 'details' => [null, -1.0]],
        ['user' => 'echo', 'type' => 3, 'date' => '2026-09-20 08:00:00', 'percentage' => 100, 'details' => [0.5, 0.7, 0.9]],
        ['user' => 'echo', 'type' => 1, 'date' => '2026-09-21 08:00:00', 'percentage' => 100, 'details' => [90.0]],
    ];

    private const CUTPOINTS = [
        ['user' => 'alpha', 'periode' => '2026-09', 'point' => 2],
        ['user' => 'alpha', 'periode' => '2026-09', 'point' => 3],
        ['user' => 'alpha', 'periode' => '2026-09', 'point' => 100, 'deleted' => true],
        ['user' => 'alpha', 'periode' => '2026-08', 'point' => 7],
        ['user' => 'charlie', 'periode' => '2026-09', 'point' => 50],
    ];

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<int, int> indeks ATTENDANCES => id */
    private array $attendanceIds = [];

    /** @var array<int, int> indeks REVIEWS => id */
    private array $reviewIds = [];

    /** @var array<int, int> indeks KPIS => id */
    private array $kpiIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Sesudah 2026-11: "aturan hari ini" adalah versi baru, periode yang diuji tetap aturan lama.
        $this->travelTo(Date::create(2027, 1, 15, 9));
        $this->resetAccessMemos();
        Cache::flush();

        foreach (self::USERS as $key => [$role, $areaName]) {
            $area = $this->fixtureArea($areaName);
            $this->users[$key] = $role === 'ADMIN'
                ? $this->fixtureGlobalAdmin($key, $area)
                : $this->fixtureUser($key, $role, $area, null, ['employee_id' => 'EMP-'.strtoupper($key)]);
        }

        foreach (self::ATTENDANCES as $index => $spec) {
            [$workDays, $lateLess30, $lateMore30, $sickDays] = $spec['counters'];
            $attendance = Attendance::query()->create([
                'user_id' => $this->users[$spec['user']]->id,
                'periode' => $spec['periode'],
                'work_days' => $workDays,
                'late_less_30' => $lateLess30,
                'late_more_30' => $lateMore30,
                'sick_days' => $sickDays,
            ]);
            if ($spec['deleted'] ?? false) {
                $attendance->delete();
            }
            $this->attendanceIds[$index] = (int) $attendance->id;
        }

        foreach (self::REVIEWS as $index => $spec) {
            [$responsiveness, $problemSolver, $helpfulness, $initiative] = $spec['points'];
            $review = EmployeeReview::query()->create([
                'user_id' => $this->users[$spec['user']]->id,
                'periode' => $spec['periode'],
                'responsiveness' => $responsiveness,
                'problem_solver' => $problemSolver,
                'helpfulness' => $helpfulness,
                'initiative' => $initiative,
            ]);
            if ($spec['deleted'] ?? false) {
                $review->delete();
            }
            $this->reviewIds[$index] = (int) $review->id;
        }

        foreach (self::KPIS as $index => $spec) {
            $kpi = Kpi::query()->create([
                'user_id' => $this->users[$spec['user']]->id,
                'kpi_category_id' => 1,
                'kpi_type_id' => $spec['type'],
                'date' => $spec['date'],
                'percentage' => $spec['percentage'],
            ]);
            foreach ($spec['details'] as $value) {
                KpiDetail::query()->create(['kpi_id' => $kpi->id, 'kpi_description_id' => 1, 'value_result' => $value]);
            }
            foreach ($spec['deleted_details'] ?? [] as $value) {
                KpiDetail::query()->create(['kpi_id' => $kpi->id, 'kpi_description_id' => 1, 'value_result' => $value])->delete();
            }
            if ($spec['deleted'] ?? false) {
                $kpi->delete();
            }
            $this->kpiIds[$index] = (int) $kpi->id;
        }

        foreach (self::CUTPOINTS as $spec) {
            $cutpoint = Cutpoint::query()->create([
                'user_id' => $this->users[$spec['user']]->id,
                'periode' => $spec['periode'],
                'point' => $spec['point'],
                'keterangan' => 'Golden test',
            ]);
            if ($spec['deleted'] ?? false) {
                $cutpoint->delete();
            }
        }

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_panel_leaderboard_widget_keeps_legacy_scores(): void
    {
        $this->actingAs($this->users['boss']);

        $widget = resolve(LeaderboardKPI::class);
        $widget->mount(month: self::PERIODE);
        $rows = (new ReflectionMethod($widget, 'getLeaderboardData'))->invoke($widget);

        $expected = [];
        foreach (array_keys(self::USERS) as $key) {
            $kpi = self::legacyKpiFinal($this->panelKpiRaw($key, self::PERIODE));
            $attendance = self::legacyAttendanceScore($this->activeAttendance($key, self::PERIODE));
            $activity = self::legacyActivityScore($this->activeReview($key, self::PERIODE));
            $cutpoint = $this->cutpointSum($key, self::PERIODE);

            $expected[$key] = [
                'kpiScore' => $kpi,
                'attendanceScore' => $attendance,
                'activityScore' => $activity,
                'cutpoint' => $cutpoint,
                'totalScore' => max(0, $kpi + $attendance + $activity - $cutpoint),
            ];
        }

        $this->assertCount(count(self::USERS), $rows);
        $this->assertSame($this->expectedOrder($expected, 'totalScore'), $this->keysOf(array_map(fn (array $row): int => (int) $row['user']->id, $rows)));
        $this->assertSame(['bravo', 'alpha', 'echo', 'boss', 'delta', 'charlie'], $this->expectedOrder($expected, 'totalScore'));

        foreach ($rows as $row) {
            $key = $this->keyOf((int) $row['user']->id);
            foreach (['kpiScore', 'attendanceScore', 'activityScore', 'totalScore'] as $field) {
                $this->assertEqualsWithDelta($expected[$key][$field], $row[$field], self::DELTA, "{$key}.{$field}");
            }
            $this->assertSame($expected[$key]['cutpoint'], $row['cutpoint'], "{$key}.cutpoint");
        }

        // Titik jangkar yang mudah dicek manual.
        $byKey = $this->rowsByKey($rows, fn (array $row): int => (int) $row['user']->id);
        $this->assertEqualsWithDelta(67.2, $byKey['alpha']['kpiScore'], self::DELTA);
        $this->assertEqualsWithDelta((18 / 22 * 100 - 10) / 100 * 15, $byKey['alpha']['attendanceScore'], self::DELTA);
        $this->assertEqualsWithDelta(12.0, $byKey['alpha']['activityScore'], self::DELTA);
        $this->assertSame(5, $byKey['alpha']['cutpoint']);
        $this->assertEqualsWithDelta(70.0, $byKey['bravo']['kpiScore'], self::DELTA);
        $this->assertEqualsWithDelta(0.0, $byKey['bravo']['attendanceScore'], self::DELTA);
        $this->assertEqualsWithDelta(0.0, $byKey['charlie']['totalScore'], self::DELTA);
        $this->assertEqualsWithDelta(10.35, $byKey['echo']['attendanceScore'], self::DELTA);
    }

    public function test_api_leaderboard_score_service_keeps_legacy_scores_and_ranks(): void
    {
        $this->actingAs($this->users['boss']);

        $rows = resolve(LeaderboardScoreService::class)->rankedScores(self::PERIODE);
        $expected = $this->expectedServiceScores();

        $this->assertCount(count(self::USERS), $rows);
        $this->assertSame(['bravo', 'echo', 'alpha', 'boss', 'delta', 'charlie'], $this->expectedOrder($expected, 'total_score'));
        $this->assertSame($this->expectedOrder($expected, 'total_score'), $this->keysOf(array_column($rows, 'user_id')));
        $this->assertSame(range(1, count(self::USERS)), array_column($rows, 'rank'));

        foreach ($rows as $row) {
            $key = $this->keyOf((int) $row['user_id']);
            foreach (['kpi_raw', 'kpi_score_70pct', 'attendance_score_15pct', 'review_score_15pct', 'total_score'] as $field) {
                $this->assertEqualsWithDelta($expected[$key][$field], $row[$field], self::DELTA, "{$key}.{$field}");
            }
            $this->assertSame($expected[$key]['grade'], $row['grade'], "{$key}.grade");
        }

        $byKey = $this->rowsByKey($rows, fn (array $row): int => (int) $row['user_id']);
        // Jumlah mentah value_result semua tipe KPI, termasuk nilai negatif, tanpa cutpoint.
        $this->assertEqualsWithDelta(4.0, $byKey['alpha']['kpi_raw'], self::DELTA);
        $this->assertEqualsWithDelta(-0.7, $byKey['delta']['kpi_score_70pct'], self::DELTA);
        $this->assertEqualsWithDelta(85.0, $byKey['bravo']['total_score'], self::DELTA);
        $this->assertSame('A (Istimewa / Outstanding)', $byKey['bravo']['grade']);
        $this->assertSame('C (Cukup / Satisfactory)', $byKey['echo']['grade']);
    }

    public function test_api_leaderboard_endpoint_keeps_legacy_rounded_scores(): void
    {
        Sanctum::actingAs($this->users['boss']);

        $response = $this->getJson('/api/v1/analytics/leaderboard?periode='.self::PERIODE.'&per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', count(self::USERS));

        $expected = $this->expectedServiceScores();
        $data = $response->json('data');

        $this->assertSame($this->expectedOrder($expected, 'total_score'), $this->keysOf(array_map(fn (array $row): int => (int) $row['user']['id'], $data)));

        foreach ($data as $index => $row) {
            $key = $this->keyOf((int) $row['user']['id']);
            $this->assertSame($index + 1, $row['rank']);
            $this->assertEqualsWithDelta(round($expected[$key]['kpi_raw'], 2), $row['scores']['kpi_raw'], self::DELTA);
            $this->assertEqualsWithDelta(round($expected[$key]['kpi_score_70pct'], 2), $row['scores']['kpi_score_70pct'], self::DELTA);
            $this->assertEqualsWithDelta(round($expected[$key]['attendance_score_15pct'], 2), $row['scores']['attendance_score_15pct'], self::DELTA);
            $this->assertEqualsWithDelta(round($expected[$key]['review_score_15pct'], 2), $row['scores']['review_score_15pct'], self::DELTA);
            $this->assertEqualsWithDelta(round($expected[$key]['total_score'], 2), $row['scores']['total_score_100pct'], self::DELTA);
            $this->assertSame($expected[$key]['grade'], $row['grade']);
        }
    }

    public function test_api_attendance_resource_keeps_legacy_metrics(): void
    {
        Sanctum::actingAs($this->users['boss']);

        foreach (self::ATTENDANCES as $index => $spec) {
            if ($spec['deleted'] ?? false) {
                continue;
            }

            [$initial, $penalty, $final, $score] = self::legacyClampedAttendance($spec['counters']);

            $metrics = $this->getJson('/api/v1/attendances/'.$this->attendanceIds[$index])
                ->assertOk()
                ->assertJsonPath('data.periode', $spec['periode'])
                ->json('data.metrics');

            $label = "{$spec['user']} {$spec['periode']}";
            $this->assertEqualsWithDelta(round($initial, 2), $metrics['initial_achievement_pct'], self::DELTA, $label);
            $this->assertEqualsWithDelta($penalty, $metrics['penalty_points'], self::DELTA, $label);
            // Bentuk JSON lama: bilangan bulat (10, bukan 10.0) untuk klien yang membacanya sebagai int.
            $this->assertIsInt($metrics['penalty_points'], $label);
            $this->assertSame($penalty, $metrics['penalty_points'], $label);
            $this->assertEqualsWithDelta(round($final, 2), $metrics['final_achievement_pct'], self::DELTA, $label);
            $this->assertEqualsWithDelta(round($score, 2), $metrics['attendance_score_15pct'], self::DELTA, $label);
        }

        // Titik jangkar: base dijepit ke 0 sebelum penalti; hari kerja 0 => semua metrik 0.
        $bravo = $this->getJson('/api/v1/attendances/'.$this->attendanceIds[3])->json('data.metrics');
        $this->assertEqualsWithDelta(0.0, $bravo['initial_achievement_pct'], self::DELTA);
        $this->assertEqualsWithDelta(17, $bravo['penalty_points'], self::DELTA);
        $charlie = $this->getJson('/api/v1/attendances/'.$this->attendanceIds[4])->json('data.metrics');
        $this->assertEqualsWithDelta(0, $charlie['penalty_points'], self::DELTA);
        $alpha = $this->getJson('/api/v1/attendances/'.$this->attendanceIds[1])->json('data.metrics');
        $this->assertEqualsWithDelta(81.82, $alpha['initial_achievement_pct'], self::DELTA);
        $this->assertEqualsWithDelta(10, $alpha['penalty_points'], self::DELTA);
        $this->assertEqualsWithDelta(71.82, $alpha['final_achievement_pct'], self::DELTA);
        $this->assertEqualsWithDelta(10.77, $alpha['attendance_score_15pct'], self::DELTA);
        $this->assertStringContainsString('"penalty_points":10,', $this->getJson('/api/v1/attendances/'.$this->attendanceIds[1])->getContent());

        // Oktober 2026 (bulan terakhir aturan lama): sakit 5 poin, telat > 30 menit 3 poin.
        $bravoOctober = $this->getJson('/api/v1/attendances/'.$this->attendanceIds[7])->assertJsonPath('data.periode', '2026-10')->json('data.metrics');
        $this->assertSame(1 + 6 + 5, $bravoOctober['penalty_points']);
        $this->assertEqualsWithDelta(round(((21 - 4) / 21 * 100 - 12) / 100 * 15, 2), $bravoOctober['attendance_score_15pct'], self::DELTA);

        // Periode sampah 1900-01: aturan lama (versi baru akan memberi sakit 0 poin => 84 / 12.6).
        $charlieJunk = $this->getJson('/api/v1/attendances/'.$this->attendanceIds[10])->assertJsonPath('data.periode', '1900-01')->json('data.metrics');
        $this->assertEqualsWithDelta(85.0, $charlieJunk['initial_achievement_pct'], self::DELTA);
        $this->assertSame(11, $charlieJunk['penalty_points']);
        $this->assertEqualsWithDelta(74.0, $charlieJunk['final_achievement_pct'], self::DELTA);
        $this->assertEqualsWithDelta(11.1, $charlieJunk['attendance_score_15pct'], self::DELTA);
    }

    public function test_api_employee_review_resource_keeps_legacy_score(): void
    {
        Sanctum::actingAs($this->users['boss']);

        foreach (self::REVIEWS as $index => $spec) {
            if ($spec['deleted'] ?? false) {
                continue;
            }

            $total = array_sum($spec['points']);
            $data = $this->getJson('/api/v1/employee-reviews/'.$this->reviewIds[$index])->assertOk()->json('data');

            $label = "{$spec['user']} {$spec['periode']}";
            $this->assertSame($total, $data['ratings']['total_points'], $label);
            $this->assertSame(20, $data['ratings']['max_points'], $label);
            $this->assertEqualsWithDelta(round(($total / 20) * 100, 2), $data['metrics']['rating_percentage'], self::DELTA, $label);
            $this->assertEqualsWithDelta(round(self::legacyReviewScore($spec['points']), 2), $data['metrics']['review_score_15pct'], self::DELTA, $label);
        }
    }

    public function test_api_integrated_evaluation_score_keeps_legacy_values_and_grades(): void
    {
        Sanctum::actingAs($this->users['boss']);

        $grades = [];
        foreach (array_keys(self::USERS) as $key) {
            $rawKpi = 0;
            foreach ($this->activeKpis($key, self::PERIODE, null) as $kpi) {
                $rawKpi += self::legacyPerKpiScore($kpi);
            }
            $kpi70 = self::legacyKpiFinal($rawKpi);

            $counters = $this->activeAttendance($key, self::PERIODE);
            $workDays = 0;
            $achievement = 0;
            $attendance15 = 0;
            if ($counters !== null && $counters[0] > 0) {
                $workDays = $counters[0];
                [, , $achievement, $attendance15] = self::legacyClampedAttendance($counters);
            }

            $points = $this->activeReview($key, self::PERIODE);
            $reviewTotal = $points === null ? 0 : array_sum($points);
            $reviewPct = $points === null ? 0 : ($reviewTotal / 20) * 100;
            $review15 = $points === null ? 0 : ($reviewTotal / 20) * 15;

            $total = $kpi70 + $attendance15 + $review15;
            $grades[$key] = self::legacyEvaluationGrade($total);

            $data = $this->getJson('/api/v1/evaluations/user/'.$this->users[$key]->id.'/score?periode='.self::PERIODE)
                ->assertOk()
                ->assertJsonPath('success', true)
                ->json('data');

            $this->assertSame(self::PERIODE, $data['periode'], $key);
            $this->assertSame(count($this->activeKpis($key, self::PERIODE, null)), $data['kpi']['kpis_count'], $key);
            $this->assertEqualsWithDelta(round($rawKpi, 2), $data['kpi']['raw_score'], self::DELTA, $key);
            $this->assertEqualsWithDelta(round($kpi70, 2), $data['kpi']['weighted_score_70pct'], self::DELTA, $key);
            $this->assertSame($counters !== null, $data['attendance']['has_data'], $key);
            $this->assertSame($workDays, $data['attendance']['work_days'], $key);
            $this->assertEqualsWithDelta(round($achievement, 2), $data['attendance']['achievement_pct'], self::DELTA, $key);
            $this->assertEqualsWithDelta(round($attendance15, 2), $data['attendance']['weighted_score_15pct'], self::DELTA, $key);
            $this->assertSame($points !== null, $data['review']['has_data'], $key);
            $this->assertSame($reviewTotal, $data['review']['total_points'], $key);
            $this->assertEqualsWithDelta(round($reviewPct, 2), $data['review']['rating_percentage'], self::DELTA, $key);
            $this->assertEqualsWithDelta(round($review15, 2), $data['review']['weighted_score_15pct'], self::DELTA, $key);
            $this->assertEqualsWithDelta(round($total, 2), $data['total_performance']['final_score_100pct'], self::DELTA, $key);
            $this->assertSame($grades[$key], $data['total_performance']['grade'], $key);
        }

        // Titik jangkar: KPI pecahan tanpa x100 (maks 0,7 per 100%), batas grade 90/80/70/60.
        $this->assertSame('B (Baik / Good)', $grades['bravo']);
        $this->assertSame('C (Cukup / Satisfactory)', $grades['echo']);
        $this->assertSame('E (Sangat Kurang / Poor)', $grades['alpha']);
        $alpha = $this->getJson('/api/v1/evaluations/user/'.$this->users['alpha']->id.'/score?periode='.self::PERIODE)->json('data');
        $this->assertEqualsWithDelta(1.56, $alpha['kpi']['raw_score'], self::DELTA);
        $this->assertEqualsWithDelta(1.09, $alpha['kpi']['weighted_score_70pct'], self::DELTA);
        $this->assertEqualsWithDelta(23.86, $alpha['total_performance']['final_score_100pct'], self::DELTA);
    }

    public function test_api_kpi_user_summary_keeps_legacy_values(): void
    {
        Sanctum::actingAs($this->users['boss']);

        foreach (array_keys(self::USERS) as $key) {
            $kpis = $this->activeKpis($key, self::PERIODE, null);
            $raw = 0;
            $percentage = 0;
            $items = [];
            foreach ($kpis as $index => $kpi) {
                $score = self::legacyPerKpiScore($kpi);
                $raw += $score;
                $percentage += $kpi['percentage'];
                $items[$this->kpiIds[$index]] = [round($score, 2), round(array_sum(self::validDetails($kpi)), 2)];
            }

            $data = $this->getJson('/api/v1/kpis/user/'.$this->users[$key]->id.'/summary?year=2026&month=9')
                ->assertOk()
                ->json('data');

            $this->assertSame(count($kpis), $data['summary']['total_kpis'], $key);
            $this->assertEqualsWithDelta($percentage, $data['summary']['total_percentage'], self::DELTA, $key);
            $this->assertEqualsWithDelta(round($raw, 2), $data['summary']['raw_kpi_score'], self::DELTA, $key);
            $this->assertEqualsWithDelta(round(self::legacyKpiFinal($raw), 2), $data['summary']['final_kpi_score_capped'], self::DELTA, $key);

            $this->assertCount(count($items), $data['kpi_items'], $key);
            foreach ($data['kpi_items'] as $item) {
                $this->assertArrayHasKey($item['kpi_id'], $items, $key);
                [$score, $actual] = $items[$item['kpi_id']];
                $this->assertEqualsWithDelta($score, $item['score'], self::DELTA, "{$key} kpi {$item['kpi_id']}");
                $this->assertEqualsWithDelta($actual, $item['actual_count'], self::DELTA, "{$key} kpi {$item['kpi_id']}");
            }
        }

        $bravo = $this->getJson('/api/v1/kpis/user/'.$this->users['bravo']->id.'/summary?year=2026&month=9')->json('data.summary');
        $this->assertEqualsWithDelta(101.75, $bravo['raw_kpi_score'], self::DELTA);
        $this->assertEqualsWithDelta(70.0, $bravo['final_kpi_score_capped'], self::DELTA);
    }

    public function test_monthly_leaderboard_export_keeps_legacy_sheet_values(): void
    {
        Excel::fake();
        $this->actingAs($this->users['boss']);

        $this->get(route('leaderboard.export', ['month' => self::PERIODE]))->assertOk();

        Excel::assertDownloaded('leaderboard.xlsx', function (LeaderboardExport $export): bool {
            $sheets = $export->sheets();

            // Ringkasan: tanpa cutpoint, pengguna ADMIN dikeluarkan.
            $summary = $this->sheetOf($sheets, LeaderboardSummarySheet::class);
            $expected = [];
            foreach ($this->nonAdminKeys() as $key) {
                $expected[$key] = $this->expectedExportMonth($key, self::PERIODE);
            }
            $rows = $summary->collection()->values()->all();
            $this->assertSame(['alpha', 'bravo', 'echo', 'delta', 'charlie'], $this->expectedOrder($expected, 'totalScore'));
            $this->assertSame($this->expectedOrder($expected, 'totalScore'), $this->keysOf(array_map(fn (array $row): int => (int) $row['user']->id, $rows)));
            foreach ($rows as $row) {
                $key = $this->keyOf((int) $row['user']->id);
                foreach (['kpiScore', 'attendanceScore', 'activityScore', 'totalScore'] as $field) {
                    $this->assertEqualsWithDelta($expected[$key][$field], $row[$field], self::DELTA, "{$key}.{$field}");
                }
                $this->assertSame([
                    number_format($expected[$key]['kpiScore'], 2),
                    number_format($expected[$key]['attendanceScore'], 2),
                    number_format($expected[$key]['activityScore'], 2),
                    number_format($expected[$key]['totalScore'], 2),
                ], array_slice($summary->map($row), 4, 4), $key);
            }
            $this->assertEqualsWithDelta(67.2 + (18 / 22 * 100 - 10) / 100 * 15 + 12, $rows[0]['totalScore'], self::DELTA);

            $this->assertAttendanceDetailSheet($this->sheetOf($sheets, LeaderboardAttendanceDetailSheet::class), [self::PERIODE]);
            $this->assertReviewDetailSheet($this->sheetOf($sheets, LeaderboardEmployeeReviewDetailSheet::class), [self::PERIODE]);
            $this->assertKpiDetailSheet($this->sheetOf($sheets, LeaderboardKpiDetailSheet::class), [self::PERIODE]);

            return true;
        });
    }

    public function test_yearly_leaderboard_export_keeps_legacy_sheet_values(): void
    {
        Excel::fake();
        $this->actingAs($this->users['boss']);

        $this->get(route('leaderboard.export', ['period_type' => 'year', 'year' => self::YEAR]))->assertOk();

        Excel::assertDownloaded('leaderboard.xlsx', function (LeaderboardExport $export): bool {
            $sheets = $export->sheets();
            $months = array_map(fn (int $month): string => sprintf('%s-%02d', self::YEAR, $month), range(1, 12));

            $expectedYear = [];
            $expectedMonthly = [];
            foreach ($this->nonAdminKeys() as $key) {
                $sum = ['kpiScore' => 0, 'attendanceScore' => 0, 'activityScore' => 0];
                foreach ($months as $ym) {
                    $month = $this->expectedExportMonth($key, $ym);
                    $expectedMonthly["{$key}|{$ym}"] = $month;
                    $sum['kpiScore'] += $month['kpiScore'];
                    $sum['attendanceScore'] += $month['attendanceScore'];
                    $sum['activityScore'] += $month['activityScore'];
                }
                // Rata-rata 12 bulan; bulan tanpa data dihitung 0.
                $kpi = $sum['kpiScore'] / 12;
                $attendance = $sum['attendanceScore'] / 12;
                $activity = $sum['activityScore'] / 12;
                $expectedYear[$key] = [
                    'kpiScore' => $kpi,
                    'attendanceScore' => $attendance,
                    'activityScore' => $activity,
                    'totalScore' => $kpi + $attendance + $activity,
                ];
            }

            $summary = $this->sheetOf($sheets, LeaderboardSummarySheet::class);
            $rows = $summary->collection()->values()->all();
            $this->assertSame($this->expectedOrder($expectedYear, 'totalScore'), $this->keysOf(array_map(fn (array $row): int => (int) $row['user']->id, $rows)));
            foreach ($rows as $row) {
                $key = $this->keyOf((int) $row['user']->id);
                foreach (['kpiScore', 'attendanceScore', 'activityScore', 'totalScore'] as $field) {
                    $this->assertEqualsWithDelta($expectedYear[$key][$field], $row[$field], self::DELTA, "{$key}.{$field}");
                }
            }
            $alphaYear = (56 + 14.1357142857142857 + 6 + 67.2 + (18 / 22 * 100 - 10) / 100 * 15 + 12 + 70) / 12;
            $this->assertEqualsWithDelta($alphaYear, $this->rowsByKey($rows, fn (array $row): int => (int) $row['user']->id)['alpha']['totalScore'], 1e-6);

            $monthlyRows = $this->sheetOf($sheets, LeaderboardMonthlySummarySheet::class)->array();
            $this->assertCount(count($expectedMonthly), $monthlyRows);
            foreach ($monthlyRows as $monthlyRow) {
                $key = $this->keyOfName((string) $monthlyRow[2]);
                $ym = (string) $monthlyRow[0];
                $this->assertArrayHasKey("{$key}|{$ym}", $expectedMonthly);
                $expected = $expectedMonthly["{$key}|{$ym}"];
                $this->assertSame('EMP-'.strtoupper($key), $monthlyRow[1]);
                $this->assertEqualsWithDelta($expected['kpiScore'], $monthlyRow[5], self::DELTA, "{$key} {$ym} kpi");
                $this->assertEqualsWithDelta($expected['attendanceScore'], $monthlyRow[6], self::DELTA, "{$key} {$ym} attendance");
                $this->assertEqualsWithDelta($expected['activityScore'], $monthlyRow[7], self::DELTA, "{$key} {$ym} activity");
                $this->assertEqualsWithDelta($expected['kpiScore'] + $expected['attendanceScore'] + $expected['activityScore'], $monthlyRow[8], self::DELTA, "{$key} {$ym} total");
            }

            $this->assertAttendanceDetailSheet($this->sheetOf($sheets, LeaderboardAttendanceDetailSheet::class), $months);
            $this->assertReviewDetailSheet($this->sheetOf($sheets, LeaderboardEmployeeReviewDetailSheet::class), $months);
            $this->assertKpiDetailSheet($this->sheetOf($sheets, LeaderboardKpiDetailSheet::class), $months);

            return true;
        });
    }

    public function test_api_kpi_checklist_keeps_legacy_kpi_values(): void
    {
        Sanctum::actingAs($this->users['boss']);

        $totals = [];
        foreach (array_keys(self::USERS) as $key) {
            // Semua tipe KPI pada bulan itu; value_result null = 0, nilai negatif ikut dijumlah.
            $values = [];
            foreach ($this->activeKpis($key, self::PERIODE, null) as $kpi) {
                foreach ($kpi['details'] as $value) {
                    $values[] = $value;
                }
            }
            $total = 0.0;
            foreach ($values as $value) {
                $total += $value ?? 0;
            }
            $completed = count(array_filter($values, fn (?float $value): bool => ($value ?? 0) > 0));
            $totals[$key] = $total;

            $summary = $this->getJson('/api/v1/analytics/kpi-checklist?user_id='.$this->users[$key]->id.'&periode='.self::PERIODE)
                ->assertOk()
                ->assertJsonPath('data.periode', self::PERIODE)
                ->json('data.summary');

            $this->assertSame(count($values), $summary['total_indicators'], $key);
            $this->assertSame($completed, $summary['completed_indicators'], $key);
            $this->assertEqualsWithDelta(round($values === [] ? 0 : $completed / count($values) * 100, 2), $summary['completion_rate_pct'], self::DELTA, $key);
            $this->assertEqualsWithDelta(round($total, 2), $summary['total_kpi_score'], self::DELTA, $key);
            $this->assertEqualsWithDelta(round(self::legacyKpiFinal($total), 2), $summary['final_kpi_score_70pct'], self::DELTA, $key);
        }

        // Titik jangkar: min(70, jumlah * 0,7), termasuk nilai negatif.
        $this->assertEqualsWithDelta(4.0, $totals['alpha'], self::DELTA);
        $bravo = $this->getJson('/api/v1/analytics/kpi-checklist?user_id='.$this->users['bravo']->id.'&periode='.self::PERIODE)->json('data.summary');
        $this->assertEqualsWithDelta(70.0, $bravo['final_kpi_score_70pct'], self::DELTA);
        $delta = $this->getJson('/api/v1/analytics/kpi-checklist?user_id='.$this->users['delta']->id.'&periode='.self::PERIODE)->json('data.summary');
        $this->assertEqualsWithDelta(-0.7, $delta['final_kpi_score_70pct'], self::DELTA);
    }

    public function test_api_dashboard_and_department_stats_keep_legacy_aggregates(): void
    {
        Sanctum::actingAs($this->users['boss']);

        $expected = $this->expectedServiceScores();
        $order = $this->expectedOrder($expected, 'total_score');
        $count = count($order);

        // Dijumlah dalam urutan peringkat, sama seperti controller.
        $sum = ['kpi_score_70pct' => 0.0, 'attendance_score_15pct' => 0.0, 'review_score_15pct' => 0.0, 'total_score' => 0.0];
        foreach ($order as $key) {
            foreach (array_keys($sum) as $field) {
                $sum[$field] += $expected[$key][$field];
            }
        }

        $data = $this->getJson('/api/v1/analytics/dashboard?periode='.self::PERIODE)->assertOk()->json('data');
        $overview = $data['company_overview'];
        $this->assertSame(self::PERIODE, $data['periode']);
        $this->assertSame($count, $overview['total_active_employees']);
        $this->assertEqualsWithDelta(round($sum['kpi_score_70pct'] / $count, 2), $overview['average_kpi_score'], self::DELTA);
        $this->assertEqualsWithDelta(round($sum['attendance_score_15pct'] / $count, 2), $overview['average_attendance_score'], self::DELTA);
        $this->assertEqualsWithDelta(round($sum['review_score_15pct'] / $count, 2), $overview['average_review_score'], self::DELTA);
        $this->assertEqualsWithDelta(round($sum['total_score'] / $count, 2), $overview['average_total_score'], self::DELTA);

        $ranked = array_map(fn (string $key, int $index): array => [
            'rank' => $index + 1,
            'user_id' => (int) $this->users[$key]->id,
            'total_score' => round($expected[$key]['total_score'], 2),
            'grade' => $expected[$key]['grade'],
        ], $order, array_keys($order));
        foreach (['top_performers' => array_slice($ranked, 0, 5), 'bottom_performers' => array_slice(array_reverse($ranked), 0, 5)] as $list => $rows) {
            $this->assertCount(count($rows), $data[$list], $list);
            foreach ($rows as $index => $row) {
                $actual = $data[$list][$index];
                $this->assertSame($row['rank'], $actual['rank'], "{$list}.{$index}");
                $this->assertSame($row['user_id'], $actual['user_id'], "{$list}.{$index}");
                $this->assertEqualsWithDelta($row['total_score'], $actual['total_score'], self::DELTA, "{$list}.{$index}");
                $this->assertSame($row['grade'], $actual['grade'], "{$list}.{$index}");
            }
        }

        $stats = $this->getJson('/api/v1/analytics/department-stats?periode='.self::PERIODE)->assertOk()->json('data');
        $groups = [
            'by_area' => fn (string $key): string => self::USERS[$key][1],
            'by_division' => fn (string $key): string => (string) $this->users[$key]->divisi?->name,
        ];
        foreach ($groups as $section => $groupOf) {
            $buckets = [];
            foreach ($order as $key) {
                $buckets[$groupOf($key)][] = (float) $expected[$key]['total_score'];
            }
            $expectedStats = [];
            foreach ($buckets as $name => $values) {
                $expectedStats[] = [
                    'name' => $name,
                    'employee_count' => count($values),
                    'average_score' => round(array_sum($values) / count($values), 2),
                    'highest_score' => round(max($values), 2),
                    'lowest_score' => round(min($values), 2),
                ];
            }
            usort($expectedStats, fn (array $a, array $b): int => $b['average_score'] <=> $a['average_score']);

            $this->assertSame(array_column($expectedStats, 'name'), array_column($stats[$section], 'name'), $section);
            foreach ($expectedStats as $index => $row) {
                $actual = $stats[$section][$index];
                $this->assertSame($row['employee_count'], $actual['employee_count'], "{$section}.{$row['name']}");
                foreach (['average_score', 'highest_score', 'lowest_score'] as $field) {
                    $this->assertEqualsWithDelta($row[$field], $actual[$field], self::DELTA, "{$section}.{$row['name']}.{$field}");
                }
            }
        }
        $this->assertSame(['COO', 'ULS'], array_column($stats['by_area'], 'name'));
    }

    public function test_junk_periodes_keep_legacy_scores(): void
    {
        $this->actingAs($this->users['boss']);

        foreach (self::JUNK_PERIODES as $periode) {
            $widget = resolve(LeaderboardKPI::class);
            $widget->mount(month: $periode);
            $panel = (new ReflectionMethod($widget, 'getLeaderboardData'))->invoke($widget);
            $this->assertCount(count(self::USERS), $panel, $periode);
            $panel = $this->rowsByKey($panel, fn (array $row): int => (int) $row['user']->id);

            $service = resolve(LeaderboardScoreService::class)->rankedScores($periode);
            $this->assertCount(count(self::USERS), $service, $periode);
            $service = $this->rowsByKey($service, fn (array $row): int => (int) $row['user_id']);
            $expectedService = $this->expectedServiceScores($periode);

            foreach (array_keys(self::USERS) as $key) {
                $label = "{$key} {$periode}";
                $kpi = self::legacyKpiFinal($this->panelKpiRaw($key, $periode));
                $attendance = self::legacyAttendanceScore($this->activeAttendance($key, $periode));
                $activity = self::legacyActivityScore($this->activeReview($key, $periode));

                $this->assertEqualsWithDelta($kpi, $panel[$key]['kpiScore'], self::DELTA, $label);
                $this->assertEqualsWithDelta($attendance, $panel[$key]['attendanceScore'], self::DELTA, $label);
                $this->assertEqualsWithDelta($activity, $panel[$key]['activityScore'], self::DELTA, $label);
                $this->assertSame($this->cutpointSum($key, $periode), $panel[$key]['cutpoint'], $label);
                $this->assertEqualsWithDelta(max(0, $kpi + $attendance + $activity - $this->cutpointSum($key, $periode)), $panel[$key]['totalScore'], self::DELTA, $label);

                foreach (['kpi_raw', 'kpi_score_70pct', 'attendance_score_15pct', 'review_score_15pct', 'total_score'] as $field) {
                    $this->assertEqualsWithDelta($expectedService[$key][$field], $service[$key][$field], self::DELTA, "{$label}.{$field}");
                }
                $this->assertSame($expectedService[$key]['grade'], $service[$key]['grade'], $label);
            }
        }

        // Titik jangkar aturan lama (versi baru akan memberi 12.6 dan 13.636...).
        $this->assertEqualsWithDelta(11.1, $this->expectedServiceScores('1900-01')['charlie']['attendance_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(((22 - 2) / 22 * 100 - 8) / 100 * 15, $this->expectedServiceScores('1970-01')['delta']['attendance_score_15pct'], self::DELTA);

        Sanctum::actingAs($this->users['boss']);
        [, , $achievement, $score] = self::legacyClampedAttendance([20, 1, 0, 2]);
        $data = $this->getJson('/api/v1/evaluations/user/'.$this->users['charlie']->id.'/score?periode=1900-01')->assertOk()->json('data');
        $this->assertTrue($data['attendance']['has_data']);
        $this->assertSame(20, $data['attendance']['work_days']);
        $this->assertEqualsWithDelta(round($achievement, 2), $data['attendance']['achievement_pct'], self::DELTA);
        $this->assertEqualsWithDelta(round($score, 2), $data['attendance']['weighted_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(round(self::legacyReviewScore([1, 2, 3, 4]), 2), $data['review']['weighted_score_15pct'], self::DELTA);
    }

    // ---------------------------------------------------------------------
    // Salinan rumus lama (JANGAN diganti dengan pemanggilan kode aplikasi).
    // ---------------------------------------------------------------------

    /**
     * LeaderboardKPI::calculateAttendanceScore / LeaderboardScoreService: base tidak dijepit.
     *
     * @param  list<int>|null  $counters
     */
    private static function legacyAttendanceScore(?array $counters): float
    {
        if ($counters === null) {
            return 0.0;
        }

        [$workDays, $lateLess30, $lateMore30, $sickDays] = $counters;

        if ($workDays <= 0) {
            return 0.0;
        }

        $initial = (($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays) * 100;
        $penalty = ($lateLess30 * 1) + ($lateMore30 * 3) + ($sickDays * 5);
        $final = max(0, $initial - $penalty);

        return ($final / 100) * 15;
    }

    /**
     * AttendanceResource / integratedScore: base dijepit ke 0 sebelum penalti; hari kerja 0 => semua 0.
     *
     * @param  list<int>  $counters
     * @return array{0: float|int, 1: int, 2: float|int, 3: float|int} [initial, penalty, final, score]
     */
    private static function legacyClampedAttendance(array $counters): array
    {
        [$workDays, $lateLess30, $lateMore30, $sickDays] = $counters;

        if ($workDays <= 0) {
            return [0, 0, 0, 0];
        }

        $initial = max(0, ($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays * 100);
        $penalty = ($lateLess30 * 1) + ($lateMore30 * 3) + ($sickDays * 5);
        $final = max(0, $initial - $penalty);

        return [$initial, $penalty, $final, ($final / 100) * 15];
    }

    /**
     * LeaderboardExportController / LeaderboardAttendanceDetailSheet: base 0 bila hari kerja 0
     * (tidak dijepit bila negatif), penalti tetap dihitung.
     *
     * @param  list<int>|null  $counters
     * @return array{0: float|int, 1: int, 2: float|int, 3: float|int} [initial, penalty, final, score]
     */
    private static function legacyExportAttendance(?array $counters): array
    {
        [$workDays, $lateLess30, $lateMore30, $sickDays] = $counters ?? [0, 0, 0, 0];

        $initial = $workDays > 0 ? ($workDays - $lateLess30 - $lateMore30 - $sickDays) / $workDays * 100 : 0;
        $penalty = ($lateLess30 * 1) + ($lateMore30 * 3) + ($sickDays * 5);
        $final = max(0, $initial - $penalty);

        return [$initial, $penalty, $final, ($final / 100) * 15];
    }

    /** @param  list<int>|null  $points */
    private static function legacyReviewScore(?array $points): float
    {
        return $points === null ? 0.0 : (array_sum($points) / 20) * 15;
    }

    /** Panel & export: (jumlah) / 20 * 100 * 0.15. */
    private static function legacyActivityScore(?array $points): float
    {
        return $points === null ? 0.0 : array_sum($points) / 20 * 100 * 0.15;
    }

    private static function legacyKpiFinal(float $raw): float
    {
        return min(70, $raw * 0.7);
    }

    /** Skor satu KPI (pecahan): (persentase / 100) * rata-rata value_result yang valid (>= 0). */
    private static function legacyPerKpiScore(array $kpi): float
    {
        $valid = self::validDetails($kpi);

        return $valid === [] ? 0.0 : ($kpi['percentage'] / 100) * (array_sum($valid) / count($valid));
    }

    /** @return list<float> */
    private static function validDetails(array $kpi): array
    {
        return array_values(array_filter($kpi['details'], fn (?float $value): bool => $value !== null && $value >= 0));
    }

    private static function legacyServiceGrade(float $total): string
    {
        return match (true) {
            $total >= 85 => 'A (Istimewa / Outstanding)',
            $total >= 75 => 'B (Baik / Good)',
            $total >= 60 => 'C (Cukup / Satisfactory)',
            $total >= 50 => 'D (Kurang / Needs Improvement)',
            default => 'E (Sangat Kurang / Poor)',
        };
    }

    private static function legacyEvaluationGrade(float $total): string
    {
        return match (true) {
            $total >= 90 => 'A (Sangat Memuaskan / Outstanding)',
            $total >= 80 => 'B (Baik / Good)',
            $total >= 70 => 'C (Cukup / Satisfactory)',
            $total >= 60 => 'D (Kurang / Needs Improvement)',
            default => 'E (Sangat Kurang / Poor)',
        };
    }

    // ---------------------------------------------------------------------
    // Harapan per permukaan, dihitung dari data fixture.
    // ---------------------------------------------------------------------

    /** @return array<string, array<string, float|string>> */
    private function expectedServiceScores(string $periode = self::PERIODE): array
    {
        $expected = [];
        foreach (array_keys(self::USERS) as $key) {
            // SUM(kpi_details.value_result) semua tipe KPI pada bulan itu, termasuk nilai negatif.
            $raw = 0.0;
            foreach ($this->activeKpis($key, $periode, null) as $kpi) {
                foreach ($kpi['details'] as $value) {
                    $raw += $value ?? 0;
                }
            }

            $kpi70 = self::legacyKpiFinal($raw);
            $attendance = self::legacyAttendanceScore($this->activeAttendance($key, $periode));
            $review = self::legacyReviewScore($this->activeReview($key, $periode));
            $total = $kpi70 + $attendance + $review;

            $expected[$key] = [
                'kpi_raw' => $raw,
                'kpi_score_70pct' => $kpi70,
                'attendance_score_15pct' => $attendance,
                'review_score_15pct' => $review,
                'total_score' => $total,
                'grade' => self::legacyServiceGrade($total),
            ];
        }

        return $expected;
    }

    /** @return array{kpiScore: float, attendanceScore: float, activityScore: float, totalScore: float} */
    private function expectedExportMonth(string $key, string $periode): array
    {
        $kpi = self::legacyKpiFinal($this->panelKpiRaw($key, $periode));
        $attendance = (float) self::legacyExportAttendance($this->activeAttendance($key, $periode))[3];
        $activity = self::legacyActivityScore($this->activeReview($key, $periode));

        return [
            'kpiScore' => $kpi,
            'attendanceScore' => $attendance,
            'activityScore' => $activity,
            'totalScore' => $kpi + $attendance + $activity,
        ];
    }

    /** Panel & export: Σ skor KPI Bulanan (tipe 3) x 100. */
    private function panelKpiRaw(string $key, string $periode): float
    {
        $raw = 0.0;
        foreach ($this->activeKpis($key, $periode, 3) as $kpi) {
            $raw += self::legacyPerKpiScore($kpi) * 100;
        }

        return $raw;
    }

    /** @return list<int>|null */
    private function activeAttendance(string $key, string $periode): ?array
    {
        foreach (self::ATTENDANCES as $spec) {
            if ($spec['user'] === $key && $spec['periode'] === $periode && ! ($spec['deleted'] ?? false)) {
                return $spec['counters'];
            }
        }

        return null;
    }

    /** @return list<int>|null */
    private function activeReview(string $key, string $periode): ?array
    {
        foreach (self::REVIEWS as $spec) {
            if ($spec['user'] === $key && $spec['periode'] === $periode && ! ($spec['deleted'] ?? false)) {
                return $spec['points'];
            }
        }

        return null;
    }

    /**
     * KPI aktif (tidak terhapus) milik pengguna pada bulan itu, dikunci dengan indeks KPIS.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activeKpis(string $key, string $periode, ?int $type): array
    {
        return array_filter(
            self::KPIS,
            fn (array $spec): bool => $spec['user'] === $key
                && str_starts_with($spec['date'], $periode.'-')
                && ($type === null || $spec['type'] === $type)
                && ! ($spec['deleted'] ?? false),
        );
    }

    private function cutpointSum(string $key, string $periode): int
    {
        $sum = 0;
        foreach (self::CUTPOINTS as $spec) {
            if ($spec['user'] === $key && $spec['periode'] === $periode && ! ($spec['deleted'] ?? false)) {
                $sum += $spec['point'];
            }
        }

        return $sum;
    }

    // ---------------------------------------------------------------------
    // Pemeriksaan sheet detail export.
    // ---------------------------------------------------------------------

    /** @param  list<string>  $periodes */
    private function assertAttendanceDetailSheet(LeaderboardAttendanceDetailSheet $sheet, array $periodes): void
    {
        $col = array_flip($sheet->headings());
        $expected = [];
        foreach (self::ATTENDANCES as $spec) {
            if (($spec['deleted'] ?? false) || ! in_array($spec['periode'], $periodes, true)) {
                continue;
            }
            $expected["{$spec['user']}|{$spec['periode']}"] = [$spec['counters'][0], ...self::legacyExportAttendance($spec['counters'])];
        }

        $rows = $sheet->array();
        $this->assertCount(count($expected), $rows);
        foreach ($rows as $row) {
            $id = $this->keyOfName((string) $row[$col['Nama Lengkap']]).'|'.$row[$col['Periode']];
            $this->assertArrayHasKey($id, $expected);
            [$workDays, $initial, $penalty, $final, $score] = $expected[$id];
            $this->assertEqualsWithDelta($workDays, $row[$col['Work Days']], self::DELTA, "{$id} work days");
            $this->assertEqualsWithDelta($initial, $row[$col['Initial Achievement']], self::DELTA, "{$id} initial");
            $this->assertEqualsWithDelta($penalty, $row[$col['Penalty']], self::DELTA, "{$id} penalty");
            $this->assertEqualsWithDelta($final, $row[$col['Final Achievement']], self::DELTA, "{$id} final");
            $this->assertEqualsWithDelta($score, $row[$col['Attendance Score']], self::DELTA, "{$id} score");
        }

        // Titik jangkar: base negatif tidak dijepit; hari kerja 0 tetap menghitung penalti.
        if (in_array(self::PERIODE, $periodes, true)) {
            $this->assertEqualsWithDelta(-40.0, $expected['bravo|'.self::PERIODE][1], self::DELTA);
            $this->assertEqualsWithDelta(1, $expected['charlie|'.self::PERIODE][2], self::DELTA);
        }
    }

    /** @param  list<string>  $periodes */
    private function assertReviewDetailSheet(LeaderboardEmployeeReviewDetailSheet $sheet, array $periodes): void
    {
        $col = array_flip($sheet->headings());
        $expected = [];
        foreach (self::REVIEWS as $spec) {
            if (($spec['deleted'] ?? false) || ! in_array($spec['periode'], $periodes, true)) {
                continue;
            }
            $expected["{$spec['user']}|{$spec['periode']}"] = self::legacyActivityScore($spec['points']);
        }

        $rows = $sheet->array();
        $this->assertCount(count($expected), $rows);
        foreach ($rows as $row) {
            $id = $this->keyOfName((string) $row[$col['Nama Lengkap']]).'|'.$row[$col['Periode']];
            $this->assertArrayHasKey($id, $expected);
            $this->assertEqualsWithDelta($expected[$id], $row[$col['Activity Score']], self::DELTA, $id);
        }
    }

    /** @param  list<string>  $periodes */
    private function assertKpiDetailSheet(LeaderboardKpiDetailSheet $sheet, array $periodes): void
    {
        $col = array_flip($sheet->headings());
        $expected = [];
        foreach (self::KPIS as $spec) {
            $periode = substr($spec['date'], 0, 7);
            if ($spec['type'] !== 3 || ($spec['deleted'] ?? false) || ! in_array($periode, $periodes, true)) {
                continue;
            }

            // Sheet detail membatasi rasio maksimal 1 (berbeda dengan ringkasan).
            $valid = self::validDetails($spec);
            $ratio = $valid === [] ? 0 : min(1, array_sum($valid) / count($valid));
            foreach ($spec['details'] as $value) {
                $expected[] = [
                    'id' => sprintf('%s|%s|%s|%s', $spec['user'], $periode, number_format($spec['percentage'], 2), $value === null ? 'null' : number_format($value, 4)),
                    'actual' => array_sum($valid),
                    'count' => count($valid),
                    'score' => ($spec['percentage'] / 100) * $ratio * 100,
                ];
            }
        }

        $actual = [];
        foreach ($sheet->array() as $row) {
            $value = $row[$col['Value Result']];
            $actual[] = [
                'id' => sprintf('%s|%s|%s|%s', $this->keyOfName((string) $row[$col['Nama Lengkap']]), $row[$col['Periode']], number_format((float) $row[$col['KPI Percentage']], 2), $value === null ? 'null' : number_format((float) $value, 4)),
                'actual' => $row[$col['KPI Actual Count']],
                'count' => $row[$col['KPI Item Count']],
                'score' => $row[$col['KPI Score']],
            ];
        }

        $sort = fn (array $a, array $b): int => strcmp($a['id'], $b['id']);
        usort($expected, $sort);
        usort($actual, $sort);

        $this->assertSame(array_column($expected, 'id'), array_column($actual, 'id'));
        foreach ($expected as $index => $row) {
            $this->assertEqualsWithDelta($row['actual'], $actual[$index]['actual'], self::DELTA, $row['id']);
            $this->assertSame($row['count'], $actual[$index]['count'], $row['id']);
            $this->assertEqualsWithDelta($row['score'], $actual[$index]['score'], self::DELTA, $row['id']);
        }
    }

    // ---------------------------------------------------------------------
    // Utilitas.
    // ---------------------------------------------------------------------

    /**
     * @template T of object
     *
     * @param  array<int, object>  $sheets
     * @param  class-string<T>  $class
     * @return T
     */
    private function sheetOf(array $sheets, string $class): object
    {
        $matches = array_values(array_filter($sheets, fn (object $sheet): bool => $sheet instanceof $class));
        $this->assertCount(1, $matches, $class);

        return $matches[0];
    }

    /** @return list<string> */
    private function nonAdminKeys(): array
    {
        return array_keys(array_filter(self::USERS, fn (array $spec): bool => $spec[0] !== 'ADMIN'));
    }

    /**
     * Urutan kunci pengguna menurut skor menurun (fixture dirancang tanpa nilai kembar).
     *
     * @param  array<string, array<string, mixed>>  $expected
     * @return list<string>
     */
    private function expectedOrder(array $expected, string $field): array
    {
        uasort($expected, fn (array $a, array $b): int => $b[$field] <=> $a[$field]);

        return array_keys($expected);
    }

    private function keyOf(int $userId): string
    {
        foreach ($this->users as $key => $user) {
            if ((int) $user->id === $userId) {
                return $key;
            }
        }

        $this->fail("Pengguna {$userId} tidak dikenal.");
    }

    private function keyOfName(string $name): string
    {
        return strtolower($name);
    }

    /**
     * @param  array<int, int>  $userIds
     * @return list<string>
     */
    private function keysOf(array $userIds): array
    {
        return array_values(array_map(fn (int $id): string => $this->keyOf($id), $userIds));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsByKey(array $rows, callable $userId): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$this->keyOf($userId($row))] = $row;
        }

        return $byKey;
    }
}
