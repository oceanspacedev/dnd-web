<?php

namespace Tests\Feature\Scoring;

use App\Exports\LeaderboardAttendanceDetailSheet;
use App\Exports\LeaderboardEmployeeReviewDetailSheet;
use App\Exports\LeaderboardExport;
use App\Exports\LeaderboardMonthlySummarySheet;
use App\Exports\LeaderboardSummarySheet;
use App\Filament\Widgets\LeaderboardKPI;
use App\Models\Attendance;
use App\Models\AttendanceComponent;
use App\Models\EmployeeReview;
use App\Models\Kpi;
use App\Models\KpiDetail;
use App\Models\ScoringRule;
use App\Models\User;
use App\Services\LeaderboardScoreService;
use App\Services\ScoringRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use ReflectionMethod;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Semua permukaan skor (widget panel, API leaderboard/dashboard/department-stats, export Excel, evaluasi
 * terpadu, review, ringkasan KPI, checklist KPI) memakai versi aturan penilaian dari periode datanya sendiri.
 *
 * Bulan berjalan dibekukan di Oktober 2026 (aturan lama), sehingga versi November 2026 dan versi uji
 * Desember 2026 adalah versi mendatang: permukaan yang memakai "aturan hari ini" akan gagal di sini.
 *
 * Fixture alpha:
 * - 2026-10 (aturan lama): telat ≤30 1, sakit 1, izin 2, mangkir 1 => izin/mangkir diabaikan, sakit 5 poin.
 * - 2026-11 (versi Nov 2026): telat ≤30 1, telat >30 1, sakit 1, izin 2, mangkir 1, cuti tahunan 2
 *   => penalti 1 + 3 + 0 + 10 + 5 + 0 = 19.
 * - 2026-12: telat ≤30 1, izin 1, pulang_cepat 2 (komponen baru dari master data, hanya di versi uji).
 * bravo 2026-11: sakit 3 => tanpa penalti (aturan lama akan memberi 15 poin).
 */
class ScoreSurfacesTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private const DELTA = 1e-9;

    private const LEGACY_DETAIL_HEADINGS = [
        'Periode',
        'ID Karyawan',
        'Nama Lengkap',
        'Division',
        'Area',
        'Work Days',
        'Late < 30',
        'Late > 30',
        'Sick Days',
        'Initial Achievement',
        'Penalty',
        'Final Achievement',
        'Attendance Score',
    ];

    private const CUSTOM_RULE_NAME = 'Aturan Uji Desember 2026';

    private User $boss;

    private User $alpha;

    private User $bravo;

    /** @var array<string, int> periode => id review alpha */
    private array $alphaReviewIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Date::create(2026, 10, 17, 9));
        $this->resetAccessMemos();
        Cache::flush();

        $coo = $this->fixtureArea('COO');
        $uls = $this->fixtureArea('ULS');
        $this->boss = $this->fixtureGlobalAdmin('boss', $coo);
        $this->alpha = $this->fixtureUser('alpha', 'STAFF', $coo, null, ['employee_id' => 'EMP-ALPHA']);
        $this->bravo = $this->fixtureUser('bravo', 'STAFF', $uls, null, ['employee_id' => 'EMP-BRAVO']);

        $this->attendance($this->alpha, '2026-10', [20, 1, 0, 1], ['izin' => 2, 'mangkir' => 1]);
        $this->attendance($this->alpha, '2026-11', [20, 1, 1, 1], ['izin' => 2, 'mangkir' => 1, 'cuti_tahunan' => 2]);
        $this->attendance($this->bravo, '2026-11', [20, 0, 0, 3], null);
        $this->attendance($this->alpha, '2026-12', [20, 1, 0, 0], ['izin' => 1, 'pulang_cepat' => 2]);

        $this->alphaReviewIds['2026-10'] = $this->review($this->alpha, '2026-10', 3);
        $this->alphaReviewIds['2026-11'] = $this->review($this->alpha, '2026-11', 4);
        $this->alphaReviewIds['2026-12'] = $this->review($this->alpha, '2026-12', 5);

        $this->kpi($this->alpha, '2026-10-05 10:00:00', [0.5]);
        $this->kpi($this->alpha, '2026-11-10 10:00:00', [1.0, 0.8]);
        $this->kpi($this->alpha, '2026-12-10 10:00:00', [1.0]);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_panel_widget_uses_the_rule_of_the_selected_month(): void
    {
        $this->actingAs($this->boss);

        $november = $this->panelRows('2026-11');
        // Versi November 2026: izin/mangkir 5 poin per hari, sakit & cuti 0 poin.
        $this->assertEqualsWithDelta(((20 - 3) / 20 * 100 - 19) / 100 * 15, $november['alpha']['attendanceScore'], self::DELTA);
        $this->assertEqualsWithDelta(9.9, $november['alpha']['attendanceScore'], self::DELTA);
        $this->assertEqualsWithDelta(12.75, $november['bravo']['attendanceScore'], self::DELTA);
        $this->assertEqualsWithDelta(63.0, $november['alpha']['kpiScore'], self::DELTA);
        $this->assertEqualsWithDelta(12.0, $november['alpha']['activityScore'], self::DELTA);
        $this->assertEqualsWithDelta(63 + 9.9 + 12, $november['alpha']['totalScore'], self::DELTA);

        // Oktober tetap aturan lama walaupun baris yang sama menyimpan izin/mangkir.
        $october = $this->panelRows('2026-10');
        $this->assertEqualsWithDelta(12.6, $october['alpha']['attendanceScore'], self::DELTA);
        $this->assertEqualsWithDelta(35.0, $october['alpha']['kpiScore'], self::DELTA);
        $this->assertEqualsWithDelta(9.0, $october['alpha']['activityScore'], self::DELTA);

        Livewire::test(LeaderboardKPI::class, ['month' => '2026-11'])
            ->assertSuccessful()
            ->assertSee('KPI Score (70%)')
            ->assertSee('Attendance (15%)')
            ->assertSee('Activity (15%)')
            ->assertSee('Aturan Kehadiran November 2026');
    }

    public function test_panel_widget_follows_custom_weights_and_review_scale(): void
    {
        $this->actingAs($this->boss);
        $this->createCustomVersion();

        $december = $this->panelRows('2026-12');
        // 60/20/20, poin maksimal penilaian 25, komponen baru pulang_cepat 2 poin per kejadian.
        $this->assertEqualsWithDelta(60.0, $december['alpha']['kpiScore'], self::DELTA);
        $this->assertEqualsWithDelta((95 - 10) / 100 * 20, $december['alpha']['attendanceScore'], self::DELTA);
        $this->assertEqualsWithDelta(20 / 25 * 20, $december['alpha']['activityScore'], self::DELTA);
        $this->assertEqualsWithDelta(60 + 17 + 16, $december['alpha']['totalScore'], self::DELTA);

        Livewire::test(LeaderboardKPI::class, ['month' => '2026-12'])
            ->assertSuccessful()
            ->assertSee('KPI Score (60%)')
            ->assertSee('Attendance (20%)')
            ->assertSee('Activity (20%)')
            ->assertDontSee('KPI Score (70%)')
            ->assertSee(self::CUSTOM_RULE_NAME);

        // Bulan lain tetap memakai versinya sendiri.
        Livewire::test(LeaderboardKPI::class, ['month' => '2026-10'])
            ->assertSee('KPI Score (70%)')
            ->assertSee('Aturan Lama');
    }

    public function test_api_leaderboard_dashboard_and_department_stats_use_the_rule_of_the_periode(): void
    {
        Sanctum::actingAs($this->boss);

        $november = $this->serviceRows('2026-11');
        $this->assertEqualsWithDelta(1.8 * 0.7, $november['alpha']['kpi_score_70pct'], self::DELTA);
        $this->assertEqualsWithDelta(9.9, $november['alpha']['attendance_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(12.0, $november['alpha']['review_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(12.75, $november['bravo']['attendance_score_15pct'], self::DELTA);
        $this->assertSame($this->ruleId('2026-11'), $november['alpha']['scoring_rule_id']);

        $this->createCustomVersion();
        $customId = $this->ruleId('2026-12');

        $december = $this->serviceRows('2026-12');
        $this->assertEqualsWithDelta(0.6, $december['alpha']['kpi_score_70pct'], self::DELTA);
        $this->assertEqualsWithDelta(17.0, $december['alpha']['attendance_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(16.0, $december['alpha']['review_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(0.6 + 17 + 16, $december['alpha']['total_score'], self::DELTA);
        $this->assertSame($customId, $december['alpha']['scoring_rule_id']);

        $leaderboard = $this->getJson('/api/v1/analytics/leaderboard?periode=2026-12')
            ->assertOk()
            ->assertJsonPath('scoring_rule.id', $customId)
            ->assertJsonPath('scoring_rule.name', self::CUSTOM_RULE_NAME)
            ->assertJsonPath('scoring_rule.effective_from', '2026-12')
            ->assertJsonPath('scoring_rule.kpi_weight', 60)
            ->assertJsonPath('scoring_rule.attendance_weight', 20)
            ->assertJsonPath('scoring_rule.review_weight', 20)
            ->assertJsonPath('scoring_rule.review_max_points', 25)
            ->assertJsonPath('data.0.user.id', $this->alpha->id)
            ->json('data.0.scores');
        // Kunci lama dipertahankan, nilainya mengikuti bobot versi periode itu.
        $this->assertEqualsWithDelta(17.0, $leaderboard['attendance_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(16.0, $leaderboard['review_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(0.6, $leaderboard['kpi_score_70pct'], self::DELTA);
        $this->assertEqualsWithDelta(33.6, $leaderboard['total_score_100pct'], self::DELTA);

        $this->getJson('/api/v1/analytics/leaderboard?periode=2026-11')
            ->assertOk()
            ->assertJsonPath('scoring_rule.name', 'Aturan Kehadiran November 2026')
            ->assertJsonPath('scoring_rule.kpi_weight', 70);

        $dashboard = $this->getJson('/api/v1/analytics/dashboard?periode=2026-12')
            ->assertOk()
            ->assertJsonPath('data.scoring_rule.id', $customId)
            ->assertJsonPath('data.scoring_rule.attendance_weight', 20)
            ->json('data.company_overview');
        // boss, alpha, bravo: hanya alpha yang punya data Desember.
        $this->assertEqualsWithDelta(round(17 / 3, 2), $dashboard['average_attendance_score'], self::DELTA);
        $this->assertEqualsWithDelta(round(16 / 3, 2), $dashboard['average_review_score'], self::DELTA);

        $this->getJson('/api/v1/analytics/department-stats?periode=2026-12')
            ->assertOk()
            ->assertJsonPath('scoring_rule.id', $customId)
            ->assertJsonPath('scoring_rule.review_max_points', 25)
            ->assertJsonPath('data.by_area.0.name', 'COO')
            ->assertJsonPath('data.by_area.0.highest_score', round(0.6 + 17 + 16, 2));
    }

    public function test_monthly_export_uses_the_rule_of_the_month_and_appends_component_columns(): void
    {
        Excel::fake();
        $this->actingAs($this->boss);

        $this->get(route('leaderboard.export', ['month' => '2026-11']))->assertOk();

        Excel::assertDownloaded('leaderboard.xlsx', function (LeaderboardExport $export): bool {
            $sheets = $export->sheets();
            $summary = $this->sheetOf($sheets, LeaderboardSummarySheet::class);
            $this->assertSame(['ID Karyawan', 'Nama Lengkap', 'Division', 'Area', 'KPI Score (70%)', 'Attendance Score (15%)', 'Activity Score (15%)', 'Total Score'], $summary->headings());

            $rows = $this->rowsByUser($summary->collection()->values()->all(), fn (array $row): int => (int) $row['user']->id);
            $this->assertEqualsWithDelta(63.0, $rows['alpha']['kpiScore'], self::DELTA);
            $this->assertEqualsWithDelta(9.9, $rows['alpha']['attendanceScore'], self::DELTA);
            $this->assertEqualsWithDelta(12.0, $rows['alpha']['activityScore'], self::DELTA);
            $this->assertEqualsWithDelta(12.75, $rows['bravo']['attendanceScore'], self::DELTA);

            $detail = $this->sheetOf($sheets, LeaderboardAttendanceDetailSheet::class);
            $headings = $detail->headings();
            // Kolom lama tetap di posisinya; kolom baru di belakang.
            $this->assertSame(self::LEGACY_DETAIL_HEADINGS, array_slice($headings, 0, 13));
            $this->assertSame(['Aturan Penilaian', 'Izin', 'Mangkir (tanpa keterangan)', 'Cuti tahunan', 'Cuti lainnya'], array_slice($headings, 13));

            $col = array_flip($headings);
            $detailRows = $this->rowsByUser($detail->array(), fn (array $row): int => $this->userIdByName((string) $row[$col['Nama Lengkap']]));
            $alpha = $detailRows['alpha'];
            $this->assertSame('2026-11', $alpha[$col['Periode']]);
            $this->assertEqualsWithDelta(85.0, $alpha[$col['Initial Achievement']], self::DELTA);
            $this->assertSame(19, $alpha[$col['Penalty']]);
            $this->assertEqualsWithDelta(66.0, $alpha[$col['Final Achievement']], self::DELTA);
            $this->assertEqualsWithDelta(9.9, $alpha[$col['Attendance Score']], self::DELTA);
            $this->assertSame('Aturan Kehadiran November 2026', $alpha[$col['Aturan Penilaian']]);
            $this->assertSame([2, 1, 2, 0], [$alpha[$col['Izin']], $alpha[$col['Mangkir (tanpa keterangan)']], $alpha[$col['Cuti tahunan']], $alpha[$col['Cuti lainnya']]]);
            $this->assertSame(0, $detailRows['bravo'][$col['Penalty']]);
            $this->assertEqualsWithDelta(12.75, $detailRows['bravo'][$col['Attendance Score']], self::DELTA);

            $review = $this->sheetOf($sheets, LeaderboardEmployeeReviewDetailSheet::class);
            $reviewCol = array_flip($review->headings());
            $this->assertEqualsWithDelta(12.0, $review->array()[0][$reviewCol['Activity Score']], self::DELTA);

            return true;
        });

        // Bulan dengan versi uji: judul kolom mengikuti bobot versi itu.
        $this->createCustomVersion();
        Excel::fake();
        $this->get(route('leaderboard.export', ['month' => '2026-12']))->assertOk();

        Excel::assertDownloaded('leaderboard.xlsx', function (LeaderboardExport $export): bool {
            $sheets = $export->sheets();
            $summary = $this->sheetOf($sheets, LeaderboardSummarySheet::class);
            $this->assertSame(['KPI Score (60%)', 'Attendance Score (20%)', 'Activity Score (20%)'], array_slice($summary->headings(), 4, 3));
            $rows = $this->rowsByUser($summary->collection()->values()->all(), fn (array $row): int => (int) $row['user']->id);
            $this->assertEqualsWithDelta(60.0, $rows['alpha']['kpiScore'], self::DELTA);
            $this->assertEqualsWithDelta(17.0, $rows['alpha']['attendanceScore'], self::DELTA);
            $this->assertEqualsWithDelta(16.0, $rows['alpha']['activityScore'], self::DELTA);

            $detail = $this->sheetOf($sheets, LeaderboardAttendanceDetailSheet::class);
            $col = array_flip($detail->headings());
            $this->assertSame('Pulang cepat', $detail->headings()[count($detail->headings()) - 1]);
            $alpha = $detail->array()[0];
            $this->assertSame(10, $alpha[$col['Penalty']]);
            $this->assertSame(2, $alpha[$col['Pulang cepat']]);
            $this->assertSame(self::CUSTOM_RULE_NAME, $alpha[$col['Aturan Penilaian']]);

            return true;
        });
    }

    public function test_yearly_export_resolves_the_rule_per_month(): void
    {
        $this->createCustomVersion();
        Excel::fake();
        $this->actingAs($this->boss);

        $queries = $this->captureQueries(function (): void {
            $this->get(route('leaderboard.export', ['period_type' => 'year', 'year' => '2026']))->assertOk();
        });
        // Aturan & komponen dimuat sekali, bukan per baris/bulan.
        $this->assertLessThanOrEqual(1, $this->countQueriesOn($queries, 'scoring_rules'));
        $this->assertLessThanOrEqual(1, $this->countQueriesOn($queries, 'attendance_components'));

        Excel::assertDownloaded('leaderboard.xlsx', function (LeaderboardExport $export): bool {
            $sheets = $export->sheets();

            // Tahun yang melewati perubahan bobot: semua bobot yang dipakai tampil di judul kolom.
            $summary = $this->sheetOf($sheets, LeaderboardSummarySheet::class);
            $this->assertSame(['KPI Score (60/70%)', 'Attendance Score (15/20%)', 'Activity Score (15/20%)'], array_slice($summary->headings(), 4, 3));

            // Oktober = aturan lama, November = versi Nov 2026, Desember = versi uji.
            $expected = [
                '2026-10' => ['kpi' => 35.0, 'attendance' => 12.6, 'activity' => 9.0, 'rule' => 'Aturan Lama', 'weights' => [70.0, 15.0, 15.0]],
                '2026-11' => ['kpi' => 63.0, 'attendance' => 9.9, 'activity' => 12.0, 'rule' => 'Aturan Kehadiran November 2026', 'weights' => [70.0, 15.0, 15.0]],
                '2026-12' => ['kpi' => 60.0, 'attendance' => 17.0, 'activity' => 16.0, 'rule' => self::CUSTOM_RULE_NAME, 'weights' => [60.0, 20.0, 20.0]],
            ];

            $monthly = $this->sheetOf($sheets, LeaderboardMonthlySummarySheet::class);
            $headings = $monthly->headings();
            $this->assertSame(['Aturan Penilaian', 'Bobot KPI (%)', 'Bobot Kehadiran (%)', 'Bobot Penilaian (%)'], array_slice($headings, 9));
            $alphaRows = [];
            foreach ($monthly->array() as $row) {
                $this->assertCount(count($headings), $row);
                if ($row[2] === 'ALPHA') {
                    $alphaRows[$row[0]] = $row;
                }
            }
            $this->assertCount(12, $alphaRows);
            $this->assertSame('Aturan Lama', $alphaRows['2026-01'][9]);
            foreach ($expected as $ym => $month) {
                $row = $alphaRows[$ym];
                $this->assertEqualsWithDelta($month['kpi'], $row[5], self::DELTA, "{$ym} kpi");
                $this->assertEqualsWithDelta($month['attendance'], $row[6], self::DELTA, "{$ym} attendance");
                $this->assertEqualsWithDelta($month['activity'], $row[7], self::DELTA, "{$ym} activity");
                $this->assertEqualsWithDelta($month['kpi'] + $month['attendance'] + $month['activity'], $row[8], self::DELTA, "{$ym} total");
                $this->assertSame($month['rule'], $row[9], $ym);
                $this->assertSame($month['weights'], [$row[10], $row[11], $row[12]], $ym);
            }

            $rows = $this->rowsByUser($summary->collection()->values()->all(), fn (array $row): int => (int) $row['user']->id);
            $this->assertEqualsWithDelta((35 + 63 + 60) / 12, $rows['alpha']['kpiScore'], self::DELTA);
            $this->assertEqualsWithDelta((12.6 + 9.9 + 17) / 12, $rows['alpha']['attendanceScore'], self::DELTA);
            $this->assertEqualsWithDelta((9 + 12 + 16) / 12, $rows['alpha']['activityScore'], self::DELTA);
            $this->assertEqualsWithDelta(12.75 / 12, $rows['bravo']['attendanceScore'], self::DELTA);

            // Detail kehadiran: tiap baris memakai aturan periodenya sendiri.
            $detail = $this->sheetOf($sheets, LeaderboardAttendanceDetailSheet::class);
            $col = array_flip($detail->headings());
            $this->assertSame(self::LEGACY_DETAIL_HEADINGS, array_slice($detail->headings(), 0, 13));
            $byPeriode = [];
            foreach ($detail->array() as $row) {
                $byPeriode[$row[$col['Nama Lengkap']].'|'.$row[$col['Periode']]] = $row;
            }
            $this->assertSame(6, $byPeriode['ALPHA|2026-10'][$col['Penalty']]);
            $this->assertEqualsWithDelta(12.6, $byPeriode['ALPHA|2026-10'][$col['Attendance Score']], self::DELTA);
            $this->assertSame('Aturan Lama', $byPeriode['ALPHA|2026-10'][$col['Aturan Penilaian']]);
            $this->assertSame(2, $byPeriode['ALPHA|2026-10'][$col['Izin']]);
            $this->assertSame(19, $byPeriode['ALPHA|2026-11'][$col['Penalty']]);
            $this->assertSame(10, $byPeriode['ALPHA|2026-12'][$col['Penalty']]);
            $this->assertEqualsWithDelta(17.0, $byPeriode['ALPHA|2026-12'][$col['Attendance Score']], self::DELTA);
            $this->assertSame(2, $byPeriode['ALPHA|2026-12'][$col['Pulang cepat']]);
            $this->assertSame(0, $byPeriode['ALPHA|2026-10'][$col['Pulang cepat']]);
            $this->assertEqualsWithDelta(12.75, $byPeriode['BRAVO|2026-11'][$col['Attendance Score']], self::DELTA);

            $review = $this->sheetOf($sheets, LeaderboardEmployeeReviewDetailSheet::class);
            $reviewCol = array_flip($review->headings());
            $scores = [];
            foreach ($review->array() as $row) {
                $scores[$row[$reviewCol['Periode']]] = $row[$reviewCol['Activity Score']];
            }
            $this->assertEqualsWithDelta(9.0, $scores['2026-10'], self::DELTA);
            $this->assertEqualsWithDelta(12.0, $scores['2026-11'], self::DELTA);
            $this->assertEqualsWithDelta(16.0, $scores['2026-12'], self::DELTA);

            return true;
        });
    }

    public function test_integrated_evaluation_score_uses_the_rule_of_the_requested_periode(): void
    {
        Sanctum::actingAs($this->boss);

        $november = $this->getJson('/api/v1/evaluations/user/'.$this->alpha->id.'/score?periode=2026-11')
            ->assertOk()
            ->assertJsonPath('data.periode', '2026-11')
            ->assertJsonPath('data.scoring_rule.name', 'Aturan Kehadiran November 2026')
            ->assertJsonPath('data.scoring_rule.effective_from', '2026-11')
            ->assertJsonPath('data.attendance.has_data', true)
            ->assertJsonPath('data.attendance.work_days', 20)
            ->assertJsonPath('data.attendance.penalty_points', 19)
            ->assertJsonPath('data.review.max_points', 20)
            ->json('data');
        $this->assertEqualsWithDelta(66.0, $november['attendance']['achievement_pct'], self::DELTA);
        $this->assertEqualsWithDelta(9.9, $november['attendance']['weighted_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(80.0, $november['review']['rating_percentage'], self::DELTA);
        $this->assertEqualsWithDelta(12.0, $november['review']['weighted_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(0.63, $november['kpi']['weighted_score_70pct'], self::DELTA);
        $this->assertEqualsWithDelta(round(0.63 + 9.9 + 12, 2), $november['total_performance']['final_score_100pct'], self::DELTA);

        $this->createCustomVersion();

        $december = $this->getJson('/api/v1/evaluations/user/'.$this->alpha->id.'/score?periode=2026-12')
            ->assertOk()
            ->assertJsonPath('data.scoring_rule.name', self::CUSTOM_RULE_NAME)
            ->assertJsonPath('data.scoring_rule.kpi_weight', 60)
            ->assertJsonPath('data.scoring_rule.attendance_weight', 20)
            ->assertJsonPath('data.scoring_rule.review_weight', 20)
            ->assertJsonPath('data.attendance.penalty_points', 10)
            ->assertJsonPath('data.review.max_points', 25)
            ->json('data');
        $this->assertEqualsWithDelta(0.6, $december['kpi']['weighted_score_70pct'], self::DELTA);
        $this->assertEqualsWithDelta(85.0, $december['attendance']['achievement_pct'], self::DELTA);
        $this->assertEqualsWithDelta(17.0, $december['attendance']['weighted_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(80.0, $december['review']['rating_percentage'], self::DELTA);
        $this->assertEqualsWithDelta(16.0, $december['review']['weighted_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(33.6, $december['total_performance']['final_score_100pct'], self::DELTA);

        // Periode tidak berbentuk 'Y-m' (mis. '2026-1') dinormalisasi untuk memilih aturan dan mencocokkan data:
        // Januari 2026 tidak punya data kehadiran/penilaian (LIKE '2026-1%' dulu memilih baris Oktober-Desember).
        $this->getJson('/api/v1/evaluations/user/'.$this->alpha->id.'/score?periode=2026-1')
            ->assertOk()
            ->assertJsonPath('data.periode', '2026-1')
            ->assertJsonPath('data.scoring_rule.name', 'Aturan Lama')
            ->assertJsonPath('data.attendance.has_data', false)
            ->assertJsonPath('data.attendance.penalty_points', 0)
            ->assertJsonPath('data.review.has_data', false);
    }

    public function test_integrated_evaluation_score_matches_the_normalized_month_instead_of_a_like_prefix(): void
    {
        Sanctum::actingAs($this->boss);
        // Januari 2026 (aturan lama): telat ≤30 2 dan sakit 1 => dasar 85%, penalti 2 + 5 = 7 => 78%.
        $this->attendance($this->alpha, '2026-01', [20, 2, 0, 1], null);
        $this->review($this->alpha, '2026-01', 2);
        $this->kpi($this->alpha, '2026-01-12 10:00:00', [0.5]);

        foreach (['2026-1', '2026-01', '2026-01-15'] as $periode) {
            $data = $this->getJson('/api/v1/evaluations/user/'.$this->alpha->id.'/score?periode='.$periode)
                ->assertOk()
                ->assertJsonPath('data.periode', $periode)
                ->assertJsonPath('data.scoring_rule.name', 'Aturan Lama')
                ->assertJsonPath('data.kpi.kpis_count', 1)
                ->assertJsonPath('data.attendance.has_data', true)
                ->assertJsonPath('data.attendance.work_days', 20)
                ->assertJsonPath('data.attendance.penalty_points', 7)
                ->assertJsonPath('data.review.has_data', true)
                ->assertJsonPath('data.review.total_points', 8)
                ->json('data');
            $this->assertEqualsWithDelta(78.0, $data['attendance']['achievement_pct'], self::DELTA, $periode);
            $this->assertEqualsWithDelta(11.7, $data['attendance']['weighted_score_15pct'], self::DELTA, $periode);
        }

        // Periode hanya tahun tetap dicocokkan dengan prefix (perilaku lama).
        $this->getJson('/api/v1/evaluations/user/'.$this->alpha->id.'/score?periode=2026')
            ->assertOk()
            ->assertJsonPath('data.attendance.has_data', true)
            ->assertJsonPath('data.review.has_data', true);
    }

    public function test_fractional_penalties_are_rounded_in_api_responses(): void
    {
        Sanctum::actingAs($this->boss);
        ScoringRule::query()->create([
            'name' => 'Aturan Uji Penalti Pecahan',
            'effective_from' => '2027-01',
            'kpi_weight' => 70,
            'attendance_weight' => 15,
            'review_weight' => 15,
            'review_max_points' => 20,
            'attendance_rules' => [
                ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 0.1],
                ['component' => 'late_more_30', 'reduces_base' => true, 'penalty_points' => 0.2],
                ['component' => 'sick_days', 'reduces_base' => true, 'penalty_points' => 0],
            ],
        ]);
        // Dasar (20 - 2) / 20 = 90%, penalti 0,1 + 0,2 (floating point: 0.30000000000000004) => 89,7%.
        $this->attendance($this->bravo, '2027-01', [20, 1, 1, 0], null);
        $attendanceId = (int) Attendance::query()->where('user_id', $this->bravo->id)->where('periode', '2027-01')->value('id');

        $detail = $this->getJson('/api/v1/attendances/'.$attendanceId)
            ->assertOk()
            ->assertJsonPath('data.metrics.final_achievement_pct', 89.7);
        $this->assertStringContainsString('"penalty_points":0.3,', $detail->getContent());
        $this->assertSame(0.3, $detail->json('data.metrics.penalty_points'));

        $evaluation = $this->getJson('/api/v1/evaluations/user/'.$this->bravo->id.'/score?periode=2027-01')
            ->assertOk()
            ->assertJsonPath('data.attendance.achievement_pct', 89.7);
        $this->assertStringContainsString('"penalty_points":0.3}', $evaluation->getContent());
        $this->assertSame(0.3, $evaluation->json('data.attendance.penalty_points'));
    }

    public function test_employee_review_resource_uses_scale_and_weight_of_its_own_periode_without_per_row_queries(): void
    {
        $this->createCustomVersion();
        Sanctum::actingAs($this->boss);

        $this->getJson('/api/v1/employee-reviews/'.$this->alphaReviewIds['2026-11'])
            ->assertOk()
            ->assertJsonPath('data.ratings.total_points', 16)
            ->assertJsonPath('data.ratings.max_points', 20)
            ->assertJsonPath('data.metrics.rating_percentage', 80)
            ->assertJsonPath('data.metrics.review_score_15pct', 12)
            ->assertJsonPath('data.metrics.review_weight', 15)
            ->assertJsonPath('data.scoring_rule_id', $this->ruleId('2026-11'));

        $this->getJson('/api/v1/employee-reviews/'.$this->alphaReviewIds['2026-12'])
            ->assertOk()
            ->assertJsonPath('data.ratings.total_points', 20)
            ->assertJsonPath('data.ratings.max_points', 25)
            ->assertJsonPath('data.metrics.rating_percentage', 80)
            ->assertJsonPath('data.metrics.review_score_15pct', 16)
            ->assertJsonPath('data.metrics.review_weight', 20);

        // Daftar lintas periode (tiga versi aturan): aturan dimuat sekali per request.
        Cache::flush();
        $this->resetAccessMemos();
        $queries = $this->captureQueries(function (): void {
            $data = $this->getJson('/api/v1/employee-reviews/user/'.$this->alpha->id)->assertOk()->json('data');
            $this->assertSame([25, 20, 20], array_column(array_column($data, 'ratings'), 'max_points'));
            $this->assertEqualsWithDelta(9.0, $data[2]['metrics']['review_score_15pct'], self::DELTA);
        });
        $this->assertSame(1, $this->countQueriesOn($queries, 'scoring_rules'));
    }

    /**
     * Kunci 'scoring_rule' punya satu bentuk di semua endpoint (LeaderboardScoreService::ruleSummary), dan semua
     * kuncinya juga ada dengan nilai yang sama di resource master /master/scoring-rules/resolve.
     */
    public function test_every_scoring_rule_summary_has_one_shape_that_matches_the_master_resource(): void
    {
        $this->createCustomVersion();
        Sanctum::actingAs($this->boss);
        $keys = ['id', 'name', 'effective_from', 'kpi_weight', 'attendance_weight', 'review_weight', 'review_max_points'];

        foreach (['2026-10', '2026-11', '2026-12'] as $periode) {
            [$year, $month] = explode('-', $periode);
            $attendanceId = Attendance::query()->where('user_id', $this->alpha->id)->where('periode', $periode)->value('id');
            $summaries = [
                'attendance' => $this->getJson('/api/v1/attendances/'.$attendanceId)->assertOk()->json('data.scoring_rule'),
                'review' => $this->getJson('/api/v1/employee-reviews/'.$this->alphaReviewIds[$periode])->assertOk()->json('data.scoring_rule'),
                'evaluation' => $this->getJson('/api/v1/evaluations/user/'.$this->alpha->id.'/score?periode='.$periode)->assertOk()->json('data.scoring_rule'),
                'kpi_summary' => $this->getJson('/api/v1/kpis/user/'.$this->alpha->id.'/summary?year='.$year.'&month='.((int) $month))->assertOk()->json('data.scoring_rule'),
                'kpi_checklist' => $this->getJson('/api/v1/analytics/kpi-checklist?user_id='.$this->alpha->id.'&periode='.$periode)->assertOk()->json('scoring_rule'),
            ];
            $master = $this->getJson('/api/v1/master/scoring-rules/resolve?periode='.$periode)->assertOk()->json('data');
            $expected = array_intersect_key($master, array_flip($keys));

            $this->assertSame($keys, array_keys($expected), $periode);
            $this->assertSame($this->ruleId($periode === '2026-10' ? '2000-01' : $periode), $expected['id'], $periode);

            foreach ($summaries as $surface => $summary) {
                $this->assertSame($keys, array_keys($summary), $surface.' '.$periode);
                $this->assertEquals($expected, $summary, $surface.' '.$periode);
            }
        }

        // Review tetap mengirim scoring_rule_id lama.
        $this->getJson('/api/v1/employee-reviews/'.$this->alphaReviewIds['2026-12'])
            ->assertJsonPath('data.scoring_rule_id', $this->ruleId('2026-12'))
            ->assertJsonPath('data.scoring_rule.kpi_weight', 60);
    }

    public function test_kpi_summary_and_checklist_use_the_kpi_weight_of_the_requested_periode(): void
    {
        $this->createCustomVersion();
        Sanctum::actingAs($this->boss);

        // Versi uji: min(60, 1,0 x 0,6) (unit pecahan lama dipertahankan).
        $this->getJson('/api/v1/kpis/user/'.$this->alpha->id.'/summary?year=2026&month=12')
            ->assertOk()
            ->assertJsonPath('data.summary.raw_kpi_score', 1)
            ->assertJsonPath('data.summary.final_kpi_score_capped', 0.6)
            ->assertJsonPath('data.scoring_rule.kpi_weight', 60);
        $this->getJson('/api/v1/kpis/user/'.$this->alpha->id.'/summary?year=2026&month=11')
            ->assertOk()
            ->assertJsonPath('data.summary.final_kpi_score_capped', 0.63)
            ->assertJsonPath('data.scoring_rule.effective_from', '2026-11');

        // Tanpa tahun/bulan (seluruh waktu): aturan periode KPI terakhir (Desember 2026), bukan bulan berjalan,
        // sehingga nilainya tidak berubah seiring waktu.
        foreach ([Date::create(2026, 10, 17, 9), Date::create(2026, 12, 5, 9), Date::create(2027, 6, 1, 9)] as $now) {
            $this->travelTo($now);
            $allTime = $this->getJson('/api/v1/kpis/user/'.$this->alpha->id.'/summary')
                ->assertOk()
                ->assertJsonPath('data.scoring_rule.effective_from', '2026-12')
                ->json('data.summary');
            $this->assertEqualsWithDelta(2.4, $allTime['raw_kpi_score'], self::DELTA, $now->toDateString());
            $this->assertEqualsWithDelta(round(2.4 * 0.6, 2), $allTime['final_kpi_score_capped'], self::DELTA, $now->toDateString());
        }

        $this->travelTo(Date::create(2026, 12, 5, 9));
        // Hanya tahun yang sudah selesai dan tanpa data: aturan akhir tahun itu.
        $this->getJson('/api/v1/kpis/user/'.$this->alpha->id.'/summary?year=2025')
            ->assertOk()
            ->assertJsonPath('data.scoring_rule.effective_from', '2000-01');

        $checklist = $this->getJson('/api/v1/analytics/kpi-checklist?user_id='.$this->alpha->id.'&periode=2026-12')
            ->assertOk()
            ->assertJsonPath('scoring_rule.kpi_weight', 60)
            ->json('data.summary');
        $this->assertEqualsWithDelta(1.0, $checklist['total_kpi_score'], self::DELTA);
        $this->assertEqualsWithDelta(0.6, $checklist['final_kpi_score_70pct'], self::DELTA);

        $checklist = $this->getJson('/api/v1/analytics/kpi-checklist?user_id='.$this->alpha->id.'&periode=2026-11')
            ->assertOk()
            ->assertJsonPath('scoring_rule.kpi_weight', 70)
            ->json('data.summary');
        $this->assertEqualsWithDelta(1.26, $checklist['final_kpi_score_70pct'], self::DELTA);
    }

    /**
     * Ringkasan KPI tanpa bulan (seluruh waktu / hanya tahun) dan evaluasi terpadu dengan periode yang bukan
     * 'Y-m' (mis. hanya tahun) memilih aturan dari periode datanya, bukan dari tanggal hari ini atau dari
     * baris kehadiran yang kebetulan ditemukan.
     */
    public function test_multi_month_kpi_values_take_the_rule_from_their_data_not_from_today(): void
    {
        $this->createCustomVersion();
        $this->createVersion('Aturan Uji Januari 2027', '2027-01', [50, 25, 25]);
        $this->createVersion('Aturan Uji Maret 2027', '2027-03', [40, 30, 30]);

        $gamma = $this->fixtureUser('gamma', 'STAFF', $this->fixtureArea('COO'));
        $this->kpi($gamma, '2025-05-10 10:00:00', [1.0]);
        $this->kpi($this->bravo, '2027-02-10 10:00:00', [1.0]);
        Sanctum::actingAs($this->boss);

        $expectations = function () use ($gamma): void {
            // Data 2025 (aturan lama) tetap 0,7 walaupun versi baru sudah berlaku hari ini.
            foreach (['', '?year=2025', '?month=5'] as $query) {
                $summary = $this->getJson('/api/v1/kpis/user/'.$gamma->id.'/summary'.$query)
                    ->assertOk()
                    ->assertJsonPath('data.scoring_rule.effective_from', '2000-01')
                    ->json('data.summary');
                $this->assertEqualsWithDelta(0.7, $summary['final_kpi_score_capped'], self::DELTA, $query);
            }

            // Tahun mendatang: aturan bulan datanya (Februari 2027 => versi Januari 2027), bukan aturan hari ini.
            $this->getJson('/api/v1/kpis/user/'.$this->bravo->id.'/summary?year=2027')
                ->assertOk()
                ->assertJsonPath('data.summary.final_kpi_score_capped', 0.5)
                ->assertJsonPath('data.scoring_rule.effective_from', '2027-01');

            $evaluation = $this->getJson('/api/v1/evaluations/user/'.$this->bravo->id.'/score?periode=2027')
                ->assertOk()
                ->assertJsonPath('data.periode', '2027')
                ->assertJsonPath('data.scoring_rule.effective_from', '2027-01')
                ->json('data');
            $this->assertNotNull($evaluation['scoring_rule']['id']);
            $this->assertEqualsWithDelta(0.5, $evaluation['kpi']['weighted_score_70pct'], self::DELTA);

            $this->getJson('/api/v1/evaluations/user/'.$gamma->id.'/score?periode=2025')
                ->assertOk()
                ->assertJsonPath('data.scoring_rule.effective_from', '2000-01')
                ->assertJsonPath('data.kpi.weighted_score_70pct', 0.7);
        };

        $expectations();

        // Baris kehadiran Maret 2027 (versi 40/30/30) tidak mengubah bobot KPI tahunan: KPI tetap memakai
        // aturan periode KPI-nya; kehadiran memakai aturan periodenya sendiri.
        $this->attendance($this->bravo, '2027-03', [20, 0, 0, 0], null);
        $evaluation = $this->getJson('/api/v1/evaluations/user/'.$this->bravo->id.'/score?periode=2027')
            ->assertOk()
            ->assertJsonPath('data.attendance.has_data', true)
            ->json('data');
        $this->assertEqualsWithDelta(0.5, $evaluation['kpi']['weighted_score_70pct'], self::DELTA);
        $this->assertEqualsWithDelta(30.0, $evaluation['attendance']['weighted_score_15pct'], self::DELTA);

        // Tahun tanpa data sama sekali: bulan berjalan dijepit ke tahun itu (bukan aturan lama bawaan kode).
        $empty = $this->getJson('/api/v1/evaluations/user/'.$gamma->id.'/score?periode=2028')
            ->assertOk()
            ->assertJsonPath('data.scoring_rule.effective_from', '2027-03')
            ->json('data');
        $this->assertEqualsWithDelta(0.0, $empty['total_performance']['final_score_100pct'], self::DELTA);

        // Waktu berjalan melewati semua versi: nilai yang sama.
        $this->travelTo(Date::create(2027, 6, 1, 9));
        $expectations();
    }

    public function test_panel_widget_falls_back_to_the_current_month_when_the_month_property_is_tampered(): void
    {
        $this->actingAs($this->boss);

        foreach ([['x' => 'y'], '2026-13', '', null] as $tampered) {
            Livewire::test(LeaderboardKPI::class, ['month' => '2026-11'])
                ->set('month', $tampered)
                ->assertSuccessful()
                ->assertSee('Aturan Lama')
                ->assertSeeHtml("month: '2026-10'")
                ->assertSeeHtml("year: '2026'");
        }
    }

    public function test_creating_or_editing_a_future_version_is_reflected_without_waiting_for_the_cache_ttl(): void
    {
        $this->actingAs($this->boss);
        $service = resolve(LeaderboardScoreService::class);

        // Sebelum versi uji ada, Desember 2026 memakai versi November 2026 (70/15/15) dan hasilnya di-cache.
        $panel = $this->panelRows('2026-12');
        $this->assertEqualsWithDelta(70.0, $panel['alpha']['kpiScore'], self::DELTA);
        $this->assertEqualsWithDelta(89 / 100 * 15, $panel['alpha']['attendanceScore'], self::DELTA);
        $this->assertEqualsWithDelta(15.0, $panel['alpha']['activityScore'], self::DELTA);
        $api = $this->serviceRows('2026-12');
        $this->assertEqualsWithDelta(13.35, $api['alpha']['attendance_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(15.0, $api['alpha']['review_score_15pct'], self::DELTA);
        $version = ScoringRuleService::cacheVersion();

        // Cache leaderboard masih berlaku (TTL belum lewat), tetapi versi aturan baru langsung terlihat.
        $rule = $this->createCustomVersion();
        $this->assertGreaterThan($version, ScoringRuleService::cacheVersion());

        $panel = $this->panelRows('2026-12');
        $this->assertEqualsWithDelta(60.0, $panel['alpha']['kpiScore'], self::DELTA);
        $this->assertEqualsWithDelta(17.0, $panel['alpha']['attendanceScore'], self::DELTA);
        $this->assertEqualsWithDelta(16.0, $panel['alpha']['activityScore'], self::DELTA);
        $api = $this->serviceRows('2026-12');
        $this->assertEqualsWithDelta(17.0, $api['alpha']['attendance_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(0.6, $api['alpha']['kpi_score_70pct'], self::DELTA);

        // Versi mendatang masih boleh dikoreksi; perubahan bobot juga langsung terlihat.
        $rule->update(['kpi_weight' => 50, 'attendance_weight' => 30, 'review_weight' => 20]);

        $panel = $this->panelRows('2026-12');
        $this->assertEqualsWithDelta(50.0, $panel['alpha']['kpiScore'], self::DELTA);
        $this->assertEqualsWithDelta(85 / 100 * 30, $panel['alpha']['attendanceScore'], self::DELTA);
        $api = $this->serviceRows('2026-12');
        $this->assertEqualsWithDelta(25.5, $api['alpha']['attendance_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(0.5, $api['alpha']['kpi_score_70pct'], self::DELTA);

        // Periode lain tidak terpengaruh.
        $this->assertEqualsWithDelta(9.9, $this->serviceRows('2026-11')['alpha']['attendance_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(12.6, $this->serviceRows('2026-10')['alpha']['attendance_score_15pct'], self::DELTA);

        // Menghapus versi mendatang mengembalikan Desember ke versi November 2026.
        $rule->delete();
        $api = $service->rankedScores('2026-12');
        $alpha = collect($api)->firstWhere('user_id', $this->alpha->id);
        $this->assertEqualsWithDelta(13.35, $alpha['attendance_score_15pct'], self::DELTA);
    }

    // ---------------------------------------------------------------------
    // Fixture & utilitas.
    // ---------------------------------------------------------------------

    /**
     * Versi uji mulai Desember 2026: bobot 60/20/20, poin maksimal penilaian 25, dan komponen baru
     * 'pulang_cepat' (2 poin per kejadian) yang ditambahkan lewat master data tanpa perubahan kode.
     */
    private function createCustomVersion(): ScoringRule
    {
        AttendanceComponent::query()->create([
            'code' => 'pulang_cepat',
            'name' => 'Pulang cepat',
            'unit' => AttendanceComponent::UNIT_OCCURRENCE,
            'sort_order' => 8,
            'is_active' => true,
        ]);

        return ScoringRule::query()->create([
            'name' => self::CUSTOM_RULE_NAME,
            'effective_from' => '2026-12',
            'kpi_weight' => 60,
            'attendance_weight' => 20,
            'review_weight' => 20,
            'review_max_points' => 25,
            'attendance_rules' => [
                ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 1],
                ['component' => 'late_more_30', 'reduces_base' => true, 'penalty_points' => 3],
                ['component' => 'sick_days', 'reduces_base' => true, 'penalty_points' => 0],
                ['component' => 'izin', 'reduces_base' => false, 'penalty_points' => 5],
                ['component' => 'mangkir', 'reduces_base' => false, 'penalty_points' => 5],
                ['component' => 'cuti_tahunan', 'reduces_base' => false, 'penalty_points' => 0],
                ['component' => 'cuti_lainnya', 'reduces_base' => false, 'penalty_points' => 0],
                ['component' => 'pulang_cepat', 'reduces_base' => false, 'penalty_points' => 2],
            ],
        ]);
    }

    /**
     * Versi uji dengan bobot [KPI, Kehadiran, Penilaian] tertentu; parameter kehadiran sama dengan versi November 2026.
     *
     * @param  array{0: int, 1: int, 2: int}  $weights
     */
    private function createVersion(string $name, string $effectiveFrom, array $weights): ScoringRule
    {
        return ScoringRule::query()->create([
            'name' => $name,
            'effective_from' => $effectiveFrom,
            'kpi_weight' => $weights[0],
            'attendance_weight' => $weights[1],
            'review_weight' => $weights[2],
            'review_max_points' => 20,
            'attendance_rules' => [
                ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 1],
                ['component' => 'late_more_30', 'reduces_base' => true, 'penalty_points' => 3],
                ['component' => 'sick_days', 'reduces_base' => true, 'penalty_points' => 0],
                ['component' => 'izin', 'reduces_base' => false, 'penalty_points' => 5],
                ['component' => 'mangkir', 'reduces_base' => false, 'penalty_points' => 5],
                ['component' => 'cuti_tahunan', 'reduces_base' => false, 'penalty_points' => 0],
                ['component' => 'cuti_lainnya', 'reduces_base' => false, 'penalty_points' => 0],
            ],
        ]);
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: int}  $counters  [work_days, late_less_30, late_more_30, sick_days]
     * @param  array<string, int>|null  $components
     */
    private function attendance(User $user, string $periode, array $counters, ?array $components): void
    {
        [$workDays, $lateLess30, $lateMore30, $sickDays] = $counters;

        Attendance::query()->create([
            'user_id' => $user->id,
            'periode' => $periode,
            'work_days' => $workDays,
            'late_less_30' => $lateLess30,
            'late_more_30' => $lateMore30,
            'sick_days' => $sickDays,
            'component_counts' => $components,
        ]);
    }

    private function review(User $user, string $periode, int $points): int
    {
        return (int) EmployeeReview::query()->create([
            'user_id' => $user->id,
            'periode' => $periode,
            'responsiveness' => $points,
            'problem_solver' => $points,
            'helpfulness' => $points,
            'initiative' => $points,
        ])->id;
    }

    /**
     * @param  list<float>  $details
     */
    private function kpi(User $user, string $date, array $details): void
    {
        $kpi = Kpi::query()->create([
            'user_id' => $user->id,
            'kpi_category_id' => 1,
            'kpi_type_id' => 3,
            'date' => $date,
            'percentage' => 100,
        ]);

        foreach ($details as $value) {
            KpiDetail::query()->create(['kpi_id' => $kpi->id, 'kpi_description_id' => 1, 'value_result' => $value]);
        }
    }

    private function ruleId(string $effectiveFrom): int
    {
        return (int) ScoringRule::query()->where('effective_from', $effectiveFrom)->value('id');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function panelRows(string $month): array
    {
        $widget = resolve(LeaderboardKPI::class);
        $widget->mount(month: $month);
        $rows = (new ReflectionMethod($widget, 'getLeaderboardData'))->invoke($widget);

        return $this->rowsByUser($rows, fn (array $row): int => (int) $row['user']->id);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function serviceRows(string $periode): array
    {
        return $this->rowsByUser(
            resolve(LeaderboardScoreService::class)->rankedScores($periode),
            fn (array $row): int => (int) $row['user_id'],
        );
    }

    /**
     * @param  array<int, array<int|string, mixed>>  $rows
     * @return array<string, array<int|string, mixed>>
     */
    private function rowsByUser(array $rows, callable $userId): array
    {
        $keys = [(int) $this->boss->id => 'boss', (int) $this->alpha->id => 'alpha', (int) $this->bravo->id => 'bravo'];
        $byKey = [];

        foreach ($rows as $row) {
            $byKey[$keys[$userId($row)]] = $row;
        }

        return $byKey;
    }

    private function userIdByName(string $name): int
    {
        return (int) User::query()->where('nama_lengkap', $name)->value('id');
    }

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

    /**
     * @return list<string>
     */
    private function captureQueries(callable $callback): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $callback();

        return $queries;
    }

    /**
     * Query baca data dari tabel (bukan pengecekan skema).
     *
     * @param  list<string>  $queries
     */
    private function countQueriesOn(array $queries, string $table): int
    {
        return count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"')));
    }
}
