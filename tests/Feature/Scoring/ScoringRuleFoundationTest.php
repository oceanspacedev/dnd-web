<?php

namespace Tests\Feature\Scoring;

use App\Models\Attendance;
use App\Models\AttendanceComponent;
use App\Models\ScoringRule;
use App\Services\KpiScoringService;
use App\Services\PerformanceScoreCalculator;
use App\Services\ScoringRuleService;
use App\Support\Periode;
use App\Support\Scoring\AttendanceComponentData;
use App\Support\Scoring\ScoringRuleData;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

class ScoringRuleFoundationTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private const COMPONENT_CODES = ['late_less_30', 'late_more_30', 'sick_days', 'izin', 'mangkir', 'cuti_tahunan', 'cuti_lainnya'];

    protected function setUp(): void
    {
        parent::setUp();

        // Bulan berjalan tetap Oktober 2026: versi November 2026 masih versi mendatang.
        $this->travelTo(Date::create(2026, 10, 17, 9));
        Cache::flush();
        ScoringRuleService::clearMemo();
    }

    protected function tearDown(): void
    {
        ScoringRuleService::clearMemo();
        parent::tearDown();
    }

    public function test_migrations_seed_the_legacy_and_november_2026_versions_and_seven_components(): void
    {
        $rules = ScoringRule::query()->orderBy('effective_from')->get();

        $this->assertSame(['2000-01', '2026-11'], $rules->pluck('effective_from')->all());
        $this->assertSame(['Aturan Lama', 'Aturan Kehadiran November 2026'], $rules->pluck('name')->all());

        // Seed versi lama identik dengan fallback tertanam di kode.
        $legacy = ScoringRuleData::fromModel($rules[0])->toArray();
        $this->assertSame(array_merge(ScoringRuleData::legacy()->toArray(), ['id' => $rules[0]->id]), $legacy);

        $v2 = ScoringRuleData::fromModel($rules[1]);
        $this->assertSame([70.0, 15.0, 15.0, 20], [$v2->kpiWeight, $v2->attendanceWeight, $v2->reviewWeight, $v2->reviewMaxPoints]);
        $this->assertSame([
            'late_less_30' => ['reduces_base' => true, 'penalty_points' => 1.0],
            'late_more_30' => ['reduces_base' => true, 'penalty_points' => 3.0],
            'sick_days' => ['reduces_base' => true, 'penalty_points' => 0.0],
            'izin' => ['reduces_base' => false, 'penalty_points' => 5.0],
            'mangkir' => ['reduces_base' => false, 'penalty_points' => 5.0],
            'cuti_tahunan' => ['reduces_base' => false, 'penalty_points' => 0.0],
            'cuti_lainnya' => ['reduces_base' => false, 'penalty_points' => 0.0],
        ], $v2->attendanceRules);

        $components = AttendanceComponent::query()->orderBy('sort_order')->get();
        $this->assertSame(self::COMPONENT_CODES, $components->pluck('code')->all());
        $this->assertSame(
            ['late_less_30' => 'late_less_30', 'late_more_30' => 'late_more_30', 'sick_days' => 'sick_days'],
            $components->whereNotNull('legacy_column')->pluck('legacy_column', 'code')->all(),
        );
        $this->assertSame('kejadian', $components->firstWhere('code', 'late_less_30')->unit);
        $this->assertSame('hari', $components->firstWhere('code', 'mangkir')->unit);
        $this->assertTrue($components->every(fn (AttendanceComponent $component): bool => $component->is_active));
        $this->assertTrue(Schema::hasColumn('attendances', 'component_counts'));

        // Fallback komponen identik dengan seed tiga kolom lama.
        foreach (AttendanceComponentData::legacyDefaults() as $code => $default) {
            $this->assertSame($default->toArray(), AttendanceComponentData::fromModel($components->firstWhere('code', $code))->toArray());
        }
    }

    public function test_resolver_picks_the_latest_version_effective_on_or_before_the_periode(): void
    {
        $this->assertSame('2000-01', ScoringRuleService::forPeriode('2026-10')->effectiveFrom);
        $this->assertSame('2000-01', ScoringRuleService::forPeriode('2026-09')->effectiveFrom);
        $this->assertSame('2000-01', ScoringRuleService::forPeriode('1900-01')->effectiveFrom);
        $this->assertSame('2000-01', ScoringRuleService::forPeriode('1970-01')->effectiveFrom);
        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2026-11')->effectiveFrom);
        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2026-12')->effectiveFrom);
        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2099-01')->effectiveFrom);

        // Normalisasi: 'Y-n', 'Y-m-d', tanggal-jam, DateTimeInterface.
        $this->assertSame('2000-01', ScoringRuleService::forPeriode('2026-9')->effectiveFrom);
        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2026-11-30')->effectiveFrom);
        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2026-11-01 00:00:00')->effectiveFrom);
        $this->assertSame('2026-11', ScoringRuleService::forPeriode(new DateTimeImmutable('2027-03-15'))->effectiveFrom);
        $this->assertSame('2000-01', ScoringRuleService::forPeriode(Date::create(2026, 10, 31, 23, 59, 59))->effectiveFrom);

        // Periode tidak valid => versi paling awal.
        foreach (['', 'abc', '2026-13', '2026-00', '2026', '2026-02-30', null, 202611] as $invalid) {
            $this->assertSame('2000-01', ScoringRuleService::forPeriode($invalid)->effectiveFrom, var_export($invalid, true));
        }

        // Versi dari database (bukan fallback), dimuat sekali per request.
        $this->assertFalse(ScoringRuleService::forPeriode('2026-11')->isFallback());
        $queries = $this->countQueries(function (): void {
            ScoringRuleService::forPeriode('2031-05');
            ScoringRuleService::forPeriode('2001-01');
            ScoringRuleService::components();
            ScoringRuleService::cacheVersion();
        });
        $this->assertSame(0, $queries);
    }

    public function test_periode_helper_normalizes_and_validates(): void
    {
        $this->assertSame('2026-09', Periode::normalize('2026-09'));
        $this->assertSame('2026-09', Periode::normalize('2026-9'));
        $this->assertSame('2026-09', Periode::normalize(' 2026-09-30 '));
        $this->assertSame('2026-09', Periode::normalize('2026-09-30 23:59:59'));
        $this->assertSame('2026-09', Periode::normalize(new DateTimeImmutable('2026-09-01')));
        $this->assertNull(Periode::normalize('2026-13'));
        $this->assertNull(Periode::normalize('2026-02-30'));
        $this->assertNull(Periode::normalize('09/2026'));
        $this->assertNull(Periode::normalize(null));
        $this->assertNull(Periode::normalize(202609));

        $this->assertTrue(Periode::isValid('2026-09'));
        $this->assertFalse(Periode::isValid('2026-9'));
        $this->assertFalse(Periode::isValid("2026-09\n"));
        $this->assertSame('2026-10', Periode::current());
    }

    public function test_november_2026_rule_worked_example(): void
    {
        $rule = ScoringRuleService::forPeriode('2026-11');

        $score = PerformanceScoreCalculator::attendance(
            ['late_less_30' => 2, 'late_more_30' => 1, 'sick_days' => 1, 'izin' => 1],
            22,
            $rule,
        );

        $base = (22 - 2 - 1 - 1) / 22 * 100;
        $this->assertEqualsWithDelta(81.818181818181, $base, 1e-9);
        $this->assertEqualsWithDelta($base, $score->basePct, 1e-9);
        // 2 x 1 (telat > 5 menit) + 1 x 3 (telat > 30 menit) + 1 x 5 (izin); sakit 0.
        $this->assertEqualsWithDelta(10.0, $score->penaltyPoints, 1e-9);
        $this->assertEqualsWithDelta($base - 10, $score->finalPct, 1e-9);
        $this->assertEqualsWithDelta(($base - 10) / 100 * 15, $score->score, 1e-9);
        $this->assertEqualsWithDelta(0.0, $score->components['sick_days']['penalty'], 1e-9);
        $this->assertEqualsWithDelta(5.0, $score->components['izin']['penalty'], 1e-9);

        // Data yang sama lewat model: izin disimpan di component_counts.
        $user = $this->fixtureUser('karyawan', 'STAFF', $this->fixtureArea('COO'));
        $attendance = Attendance::query()->create([
            'user_id' => $user->id,
            'periode' => '2026-11',
            'work_days' => 22,
            'late_less_30' => 2,
            'late_more_30' => 1,
            'sick_days' => 1,
            'component_counts' => ['izin' => 1],
        ])->refresh();
        $this->assertEquals($score, PerformanceScoreCalculator::attendanceFor($attendance, $rule));

        // Aturan lama untuk baris yang sama mengabaikan izin dan memenalti sakit 5 poin.
        $legacy = PerformanceScoreCalculator::attendanceFor($attendance, ScoringRuleService::forPeriode('2026-10'));
        $this->assertEqualsWithDelta($base - (2 + 3 + 5), $legacy->finalPct, 1e-9);
    }

    public function test_november_2026_rule_counts_mangkir_per_day_and_cuti_sakit_without_penalty(): void
    {
        $rule = ScoringRuleService::forPeriode('2026-11');

        // Mangkir 5 poin per hari, tidak mengurangi hari kerja.
        $mangkir = PerformanceScoreCalculator::attendance(['mangkir' => 2], 20, $rule);
        $this->assertEqualsWithDelta(100.0, $mangkir->basePct, 1e-9);
        $this->assertEqualsWithDelta(10.0, $mangkir->penaltyPoints, 1e-9);
        $this->assertEqualsWithDelta(90.0, $mangkir->finalPct, 1e-9);
        $this->assertEqualsWithDelta(13.5, $mangkir->score, 1e-9);

        // Cuti tahunan/lainnya: tanpa penalti dan tidak mengurangi hari kerja; sakit tanpa penalti tetapi mengurangi hari kerja.
        $leave = PerformanceScoreCalculator::attendance(['sick_days' => 2, 'cuti_tahunan' => 3, 'cuti_lainnya' => 2], 20, $rule);
        $this->assertEqualsWithDelta(90.0, $leave->basePct, 1e-9);
        $this->assertEqualsWithDelta(0.0, $leave->penaltyPoints, 1e-9);
        $this->assertEqualsWithDelta(13.5, $leave->score, 1e-9);

        // Hari kerja 0 => skor 0; penalti negatif dijepit ke 0.
        $this->assertSame(0.0, PerformanceScoreCalculator::attendance(['mangkir' => 1], 0, $rule)->score);
        $this->assertSame(0.0, PerformanceScoreCalculator::attendance(['mangkir' => 30], 20, $rule)->score);

        // Bobot & poin maksimal penilaian ikut aturan.
        $this->assertEqualsWithDelta(12.0, PerformanceScoreCalculator::review(16, $rule), 1e-9);
        $this->assertSame(0.0, PerformanceScoreCalculator::review(null, $rule));
        $this->assertSame(70.0, KpiScoringService::calculateFinalKpiScore(150, $rule));
        $this->assertSame(KpiScoringService::calculateFinalKpiScore(96.0), KpiScoringService::calculateFinalKpiScore(96.0, $rule));
    }

    public function test_locked_versions_only_allow_name_and_notes_changes_and_cannot_be_deleted(): void
    {
        $legacy = ScoringRule::query()->where('effective_from', '2000-01')->firstOrFail();
        $this->assertTrue($legacy->isLocked());

        // Pesan galat dikunci ke kolom yang diubah.
        $this->assertRejected(fn () => $legacy->fresh()->update(['attendance_weight' => 20, 'review_weight' => 10]), 'attendance_weight');
        $this->assertRejected(fn () => $legacy->fresh()->update(['attendance_weight' => 20, 'review_weight' => 10]), 'review_weight');
        $this->assertRejected(fn () => $legacy->fresh()->update(['effective_from' => '2027-01']), 'effective_from');
        $this->assertRejected(function () use ($legacy): void {
            $rule = $legacy->fresh();
            $rules = $rule->attendance_rules;
            $rules[2]['penalty_points'] = 0;
            $rule->update(['attendance_rules' => $rules]);
        }, 'attendance_rules');
        $this->assertRejected(fn () => $legacy->fresh()->delete(), 'effective_from');

        $legacy->fresh()->update(['name' => 'Aturan Lama (sebelum Nov 2026)', 'notes' => 'Catatan baru']);
        $this->assertSame('Aturan Lama (sebelum Nov 2026)', $legacy->fresh()->name);
        $this->assertSame(ScoringRuleData::legacy()->attendanceRulesList(), ScoringRuleData::fromModel($legacy->fresh())->attendanceRulesList());
        // Menyimpan ulang nilai yang sama tidak dianggap perubahan.
        $legacy->fresh()->fill(['kpi_weight' => 70, 'attendance_weight' => '15.00'])->save();

        // Versi mendatang masih boleh diubah sampai bulannya tiba.
        $november = ScoringRule::query()->where('effective_from', '2026-11')->firstOrFail();
        $this->assertFalse($november->isLocked());
        $rules = $november->attendance_rules;
        $rules[4]['penalty_points'] = 6;
        $november->update(['attendance_rules' => $rules]);
        $this->assertEqualsWithDelta(6.0, ScoringRuleService::forPeriode('2026-11')->penaltyPoints('mangkir'), 1e-9);
        $this->assertRejected(fn () => $november->fresh()->update(['effective_from' => '2026-09']), 'effective_from');

        // Selama November berjalan versi November masih bisa dikoreksi (data kehadiran November baru
        // diinput setelah bulannya selesai).
        $this->travelTo(Date::create(2026, 11, 1, 0, 0, 1));
        $this->assertFalse($november->fresh()->isLocked());
        $rules[4]['penalty_points'] = 5;
        $november->fresh()->update(['attendance_rules' => $rules]);
        $this->assertEqualsWithDelta(5.0, ScoringRuleService::forPeriode('2026-11')->penaltyPoints('mangkir'), 1e-9);
        $this->assertRejected(fn () => $november->fresh()->update(['effective_from' => '2026-10']), 'effective_from');

        // Setelah November berakhir versi itu terkunci.
        $this->travelTo(Date::create(2026, 12, 1, 0, 0, 1));
        $this->assertTrue($november->fresh()->isLocked());
        $this->assertRejected(fn () => $november->fresh()->update(['review_max_points' => 25]), 'review_max_points');
        $this->assertRejected(fn () => $november->fresh()->delete(), 'effective_from');
        $november->fresh()->update(['notes' => 'Sudah berlaku']);
    }

    public function test_locked_version_accepts_resaving_equivalent_attendance_rules_with_other_types(): void
    {
        $legacy = ScoringRule::query()->where('effective_from', '2000-01')->firstOrFail();
        $storedJson = $legacy->getRawOriginal('attendance_rules');

        // Bentuk yang dikirim form (string), dari ScoringRuleData (float), dan reduces_base '1'.
        $asStrings = array_map(fn (array $entry): array => [
            'component' => $entry['component'],
            'reduces_base' => $entry['reduces_base'] ? '1' : '0',
            'penalty_points' => (string) $entry['penalty_points'],
        ], $legacy->attendance_rules);

        $legacy->fresh()->update(['name' => 'Ganti nama', 'attendance_rules' => $asStrings]);
        $legacy->fresh()->update(['name' => 'Ganti nama lagi', 'attendance_rules' => ScoringRuleData::legacy()->attendanceRulesList()]);

        $this->assertSame('Ganti nama lagi', $legacy->fresh()->name);
        $this->assertSame($storedJson, $legacy->fresh()->getRawOriginal('attendance_rules'));
        $this->assertEquals(ScoringRuleData::legacy()->attendanceRules, ScoringRuleService::forPeriode('2026-09')->attendanceRules);

        // Nilai yang benar-benar berbeda, urutan yang berbeda, atau entri tambahan tetap ditolak.
        $changed = $asStrings;
        $changed[2]['penalty_points'] = '0';
        $this->assertRejected(fn () => $legacy->fresh()->update(['name' => 'X', 'attendance_rules' => $changed]), 'attendance_rules');
        $this->assertRejected(fn () => $legacy->fresh()->update(['attendance_rules' => array_reverse($asStrings)]), 'attendance_rules');
        $this->assertRejected(fn () => $legacy->fresh()->update(['attendance_rules' => [...$asStrings, $asStrings[0]]]), 'attendance_rules');
        $this->assertSame('Ganti nama lagi', $legacy->fresh()->name);
    }

    public function test_new_versions_are_validated(): void
    {
        $valid = [
            'name' => 'Aturan 2027',
            'effective_from' => '2027-01',
            'kpi_weight' => 60,
            'attendance_weight' => 20,
            'review_weight' => 20,
            'review_max_points' => 20,
            'attendance_rules' => [
                ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 1],
                ['component' => 'mangkir', 'reduces_base' => '1', 'penalty_points' => '7.5'],
            ],
        ];

        $this->assertRejected(fn () => ScoringRule::query()->create(['effective_from' => '2026-09'] + $valid), 'effective_from');
        $this->assertRejected(fn () => ScoringRule::query()->create(['effective_from' => '2027-13'] + $valid), 'effective_from');
        $this->assertRejected(fn () => ScoringRule::query()->create(['effective_from' => '2027-1'] + $valid), 'effective_from');
        $this->assertRejected(fn () => ScoringRule::query()->create(['review_weight' => 10] + $valid), 'kpi_weight');
        $this->assertRejected(fn () => ScoringRule::query()->create(['kpi_weight' => 110, 'attendance_weight' => -10] + $valid), 'attendance_weight');
        $this->assertRejected(fn () => ScoringRule::query()->create(['review_max_points' => 0] + $valid), 'review_max_points');
        // Nilai mentah divalidasi (cast integer/float tidak boleh menyembunyikan pecahan atau teks).
        foreach ([20.5, '20.5', 70000, true, 'dua puluh'] as $badMaxPoints) {
            $this->assertRejected(fn () => ScoringRule::query()->create(['review_max_points' => $badMaxPoints] + $valid), 'review_max_points');
        }
        $this->assertRejected(fn () => ScoringRule::query()->create(['kpi_weight' => 'abc', 'attendance_weight' => 50, 'review_weight' => 50] + $valid), 'kpi_weight');
        // decimal(6,2) membulatkan ke 2 desimal: 33.333/33.333/33.334 akan tersimpan 99.99, 70.005 jadi 70.01.
        $this->assertRejected(fn () => ScoringRule::query()->create(['kpi_weight' => 33.333, 'attendance_weight' => 33.333, 'review_weight' => 33.334] + $valid), 'kpi_weight');
        $this->assertRejected(fn () => ScoringRule::query()->create(['kpi_weight' => 70.005, 'attendance_weight' => 14.995, 'review_weight' => 15] + $valid), 'kpi_weight');
        $this->assertRejected(fn () => ScoringRule::query()->create(['kpi_weight' => '70', 'attendance_weight' => '14.995', 'review_weight' => '15.005'] + $valid), 'attendance_weight');
        // Periode berlaku ganda ditolak dengan pesan, bukan galat constraint database.
        $this->assertRejected(fn () => ScoringRule::query()->create(['effective_from' => '2026-11'] + $valid), 'effective_from');
        $this->assertRejected(fn () => ScoringRule::query()->create(['engine' => 'experimental'] + $valid), 'engine');
        $this->assertRejected(fn () => ScoringRule::query()->create(['name' => ''] + $valid), 'name');
        $this->assertRejected(fn () => ScoringRule::query()->create(['attendance_rules' => [['component' => 'dinas_luar', 'reduces_base' => false, 'penalty_points' => 1]]] + $valid), 'attendance_rules');
        $this->assertRejected(fn () => ScoringRule::query()->create(['attendance_rules' => [
            ['component' => 'izin', 'reduces_base' => false, 'penalty_points' => 1],
            ['component' => 'izin', 'reduces_base' => false, 'penalty_points' => 2],
        ]] + $valid), 'attendance_rules');
        $this->assertRejected(fn () => ScoringRule::query()->create(['attendance_rules' => [['component' => 'izin', 'reduces_base' => false, 'penalty_points' => -1]]] + $valid), 'attendance_rules');
        $this->assertRejected(fn () => ScoringRule::query()->create(['attendance_rules' => [['component' => 'izin', 'reduces_base' => 'ya', 'penalty_points' => 1]]] + $valid), 'attendance_rules');
        $this->assertRejected(fn () => ScoringRule::query()->create(['attendance_rules' => ['izin' => ['reduces_base' => false, 'penalty_points' => 1]]] + $valid), 'attendance_rules');
        $this->assertSame(2, ScoringRule::query()->count());

        // Bulan berjalan boleh dan masih bisa dikoreksi/dihapus selama bulannya berjalan.
        $current = ScoringRule::query()->create(['effective_from' => '2026-10', 'kpi_weight' => 70, 'attendance_weight' => 15, 'review_weight' => 15] + $valid);
        $this->assertFalse($current->isLocked());
        $this->assertSame('2026-10', ScoringRuleService::forPeriode('2026-10')->effectiveFrom);
        $this->assertSame('2000-01', ScoringRuleService::forPeriode('2026-09')->effectiveFrom);

        $typo = $valid['attendance_rules'];
        $typo[0]['penalty_points'] = 10;
        $current->fresh()->update(['attendance_rules' => $typo]);
        $this->assertEqualsWithDelta(10.0, ScoringRuleService::forPeriode('2026-10')->penaltyPoints('late_less_30'), 1e-9);
        $current->fresh()->update(['attendance_rules' => $valid['attendance_rules']]);
        $this->assertEqualsWithDelta(1.0, ScoringRuleService::forPeriode('2026-10')->penaltyPoints('late_less_30'), 1e-9);
        $this->assertRejected(fn () => $current->fresh()->update(['effective_from' => '2026-09']), 'effective_from');
        $current->fresh()->delete();
        $this->assertSame('2000-01', ScoringRuleService::forPeriode('2026-10')->effectiveFrom);

        // Versi bulan berjalan terkunci begitu bulannya berakhir.
        $current = ScoringRule::query()->create(['effective_from' => '2026-10', 'kpi_weight' => 70, 'attendance_weight' => 15, 'review_weight' => 15] + $valid);
        $this->travelTo(Date::create(2026, 11, 1, 0, 0, 1));
        $this->assertTrue($current->fresh()->isLocked());
        $this->assertRejected(fn () => $current->fresh()->update(['attendance_rules' => $typo]), 'attendance_rules');
        $this->assertRejected(fn () => $current->fresh()->delete(), 'effective_from');
        $this->travelTo(Date::create(2026, 10, 17, 9));

        // Bobot 2 desimal yang totalnya 100 diterima dan tersimpan apa adanya.
        $decimals = ScoringRule::query()->create(['effective_from' => '2026-12', 'kpi_weight' => '33.33', 'attendance_weight' => 33.33, 'review_weight' => 33.34] + $valid);
        $this->assertSame([33.33, 33.33, 33.34], [$decimals->fresh()->kpi_weight, $decimals->fresh()->attendance_weight, $decimals->fresh()->review_weight]);
        $this->assertSame(20, $decimals->fresh()->review_max_points);
        $decimals->delete();

        $future = ScoringRule::query()->create($valid);
        $this->assertSame('standard', $future->engine);
        $this->assertSame([
            ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 1],
            ['component' => 'mangkir', 'reduces_base' => true, 'penalty_points' => 7.5],
        ], $future->fresh()->attendance_rules);
        $resolved = ScoringRuleService::forPeriode('2027-06');
        $this->assertSame($future->id, $resolved->id);
        $this->assertSame([60.0, 20.0, 20.0], [$resolved->kpiWeight, $resolved->attendanceWeight, $resolved->reviewWeight]);

        // Versi mendatang boleh dihapus.
        $future->delete();
        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2027-06')->effectiveFrom);
    }

    public function test_attendance_component_guards(): void
    {
        $izin = AttendanceComponent::query()->where('code', 'izin')->firstOrFail();

        $this->assertRejected(fn () => $izin->fresh()->update(['code' => 'izin_baru']), 'code');
        $this->assertRejected(fn () => $izin->fresh()->update(['legacy_column' => 'sick_days']), 'legacy_column');
        $this->assertRejected(fn () => $izin->fresh()->update(['unit' => 'jam']), 'unit');
        // Dipakai versi November 2026.
        $this->assertRejected(fn () => $izin->fresh()->delete(), 'code');
        // Komponen kolom lama tidak bisa dihapus.
        $this->assertRejected(fn () => AttendanceComponent::query()->where('code', 'sick_days')->firstOrFail()->delete(), 'code');

        // Satuan komponen yang sudah dipakai tetap; komponen yang masih dinilai (izin: 5 poin/hari di aturan
        // November 2026) tidak bisa dinonaktifkan lewat jalur Eloquent mana pun.
        $this->assertRejected(fn () => $izin->fresh()->update(['unit' => 'kejadian']), 'unit');
        $this->assertRejected(fn () => $izin->fresh()->update(['is_active' => false]), 'is_active');

        // Mengganti nama boleh; komponen tanpa efek di versi mana pun (cuti_lainnya: penalti 0) boleh dinonaktifkan.
        $izin->fresh()->update(['name' => 'Izin (pribadi)']);
        $this->assertTrue(ScoringRuleService::component('izin')->isActive);
        $this->assertSame('Izin (pribadi)', ScoringRuleService::component('izin')->name);
        AttendanceComponent::query()->where('code', 'cuti_lainnya')->firstOrFail()->update(['is_active' => false]);
        $this->assertFalse(ScoringRuleService::component('cuti_lainnya')->isActive);

        foreach (['Dinas', '1dinas', 'd', 'dinas-luar', 'dinas luar', str_repeat('a', 51)] as $badCode) {
            $this->assertRejected(fn () => AttendanceComponent::query()->create(['code' => $badCode, 'name' => 'Dinas luar']), 'code');
        }
        $this->assertRejected(fn () => AttendanceComponent::query()->create(['code' => 'dinas_luar', 'name' => 'Dinas luar', 'legacy_column' => 'work_days']), 'legacy_column');
        $this->assertRejected(fn () => AttendanceComponent::query()->create(['code' => 'dinas_luar', 'name' => 'Dinas luar', 'legacy_column' => 'sick_days']), 'legacy_column');
        $this->assertRejected(fn () => AttendanceComponent::query()->create(['code' => 'dinas_luar', 'name' => '']), 'name');
        // Kode ganda ditolak dengan pesan, bukan galat constraint database.
        $this->assertRejected(fn () => AttendanceComponent::query()->create(['code' => 'izin', 'name' => 'Izin 2']), 'code');

        // Komponen baru tanpa kode program: dibaca dari component_counts.
        $dinas = AttendanceComponent::query()->create(['code' => 'dinas_luar', 'name' => 'Dinas luar', 'sort_order' => 8]);
        $this->assertSame('hari', $dinas->unit);
        $this->assertSame('dinas_luar', ScoringRuleService::components()->keys()->last());

        $user = $this->fixtureUser('karyawan', 'STAFF', $this->fixtureArea('COO'));
        $attendance = Attendance::query()->create([
            'user_id' => $user->id,
            'periode' => '2026-12',
            'work_days' => 20,
            'component_counts' => ['dinas_luar' => 2],
        ]);
        $attendance->delete();

        // Dipakai data kehadiran (termasuk yang soft-deleted) => tidak bisa dihapus.
        $this->assertRejected(fn () => $dinas->fresh()->delete(), 'code');

        $unused = AttendanceComponent::query()->create(['code' => 'wfh', 'name' => 'Kerja dari rumah', 'unit' => 'hari']);
        $unused->delete();
        $this->assertNull(ScoringRuleService::component('wfh'));
    }

    public function test_scoped_memo_is_reset_after_forget_scoped_instances(): void
    {
        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2027-01')->effectiveFrom);

        $queries = $this->countQueries(fn () => ScoringRuleService::forPeriode('2027-02'));
        $this->assertSame(0, $queries);

        // Worker lain menambah versi & menaikkan versi cache (tanpa observer di proses ini).
        DB::table('scoring_rules')->insert([
            'name' => 'Versi 2027',
            'effective_from' => '2027-01',
            'engine' => 'standard',
            'kpi_weight' => 60,
            'attendance_weight' => 20,
            'review_weight' => 20,
            'review_max_points' => 20,
            'attendance_rules' => json_encode([['component' => 'mangkir', 'reduces_base' => false, 'penalty_points' => 5]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::add($this->versionKey(), 1, 600);
        Cache::increment($this->versionKey());

        // Request yang sama tetap konsisten memakai memo-nya.
        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2027-01')->effectiveFrom);

        $this->app->forgetScopedInstances();

        $queries = $this->countQueries(function (): void {
            $this->assertSame('2027-01', ScoringRuleService::forPeriode('2027-01')->effectiveFrom);
        });
        $this->assertGreaterThan(0, $queries);
        $this->assertSame(2, ScoringRuleService::cacheVersion());
    }

    public function test_cache_is_versioned_and_invalidation_keeps_unrelated_cache_keys(): void
    {
        Cache::put('unrelated-application-key', 'survives', 600);

        $version = ScoringRuleService::cacheVersion();
        ScoringRuleService::forPeriode('2026-11');
        $this->assertTrue(Cache::has($this->payloadKey($version)));

        // Request berikutnya (memo baru) dilayani dari cache aplikasi tanpa query.
        $this->app->forgetScopedInstances();
        $this->assertSame(0, $this->countQueries(fn () => ScoringRuleService::forPeriode('2026-11')));

        ScoringRuleService::clearCache();
        $this->assertSame($version + 1, ScoringRuleService::cacheVersion());
        $this->assertSame('survives', Cache::get('unrelated-application-key'));

        // Observer: menyimpan aturan atau komponen menaikkan versi dan langsung terlihat.
        ScoringRule::query()->where('effective_from', '2026-11')->firstOrFail()->update(['notes' => 'Diperbarui']);
        $this->assertSame($version + 2, ScoringRuleService::cacheVersion());
        AttendanceComponent::query()->where('code', 'mangkir')->firstOrFail()->update(['name' => 'Mangkir']);
        $this->assertSame($version + 3, ScoringRuleService::cacheVersion());
        $this->assertSame('Mangkir', ScoringRuleService::component('mangkir')->name);
        $this->assertSame('survives', Cache::get('unrelated-application-key'));
    }

    public function test_falls_back_to_the_hard_coded_legacy_rule_when_tables_are_missing_or_empty(): void
    {
        $user = $this->fixtureUser('karyawan', 'STAFF', $this->fixtureArea('COO'));
        $attendance = Attendance::query()->create([
            'user_id' => $user->id,
            'periode' => '2026-12',
            'work_days' => 22,
            'late_less_30' => 2,
            'late_more_30' => 1,
            'sick_days' => 1,
            'component_counts' => ['izin' => 3],
        ])->refresh();

        DB::table('scoring_rules')->delete();
        ScoringRuleService::clearCache();

        $rule = ScoringRuleService::forPeriode('2026-12');
        $this->assertTrue($rule->isFallback());
        $this->assertEquals(ScoringRuleData::legacy(), $rule);
        $this->assertFalse(Cache::has($this->payloadKey(ScoringRuleService::cacheVersion())));

        Schema::drop('scoring_rules');
        Schema::drop('attendance_components');
        ScoringRuleService::clearCache();

        $this->assertEquals(ScoringRuleData::legacy(), ScoringRuleService::forPeriode('2099-01'));
        $this->assertEquals(ScoringRuleData::legacy(), ScoringRuleService::forPeriode('garbage'));
        $this->assertSame(['late_less_30', 'late_more_30', 'sick_days'], ScoringRuleService::components()->keys()->all());
        $this->assertSame(1, $attendance->countFor('sick_days'));
        $this->assertSame(3, $attendance->countFor('izin'));
        $this->assertEqualsWithDelta(
            ((22 - 2 - 1 - 1) / 22 * 100 - 10) / 100 * 15,
            PerformanceScoreCalculator::attendanceFor($attendance, ScoringRuleService::forPeriode('2026-12'))->score,
            1e-9,
        );
        $this->assertFalse(Cache::has($this->payloadKey(ScoringRuleService::cacheVersion())));
    }

    public function test_attendance_count_for_reads_legacy_columns_and_json_counts_without_extra_queries(): void
    {
        $user = $this->fixtureUser('karyawan', 'STAFF', $this->fixtureArea('COO'));
        $other = $this->fixtureUser('karyawan_lain', 'STAFF', $this->fixtureArea('COO'));

        Attendance::query()->create([
            'user_id' => $user->id,
            'periode' => '2026-11',
            'work_days' => 22,
            'late_less_30' => 4,
            'late_more_30' => 1,
            'sick_days' => 2,
            'component_counts' => ['izin' => 1, 'mangkir' => 2, 'cuti_tahunan' => 3, 'sick_days' => 99],
        ]);
        Attendance::query()->create([
            'user_id' => $other->id,
            'periode' => '2026-11',
            'work_days' => 20,
            'late_less_30' => 1,
        ]);

        $attendances = Attendance::query()->orderBy('id')->get();
        ScoringRuleService::components();

        $queries = $this->countQueries(function () use ($attendances): void {
            [$first, $second] = $attendances->all();

            $this->assertSame(4, $first->countFor('late_less_30'));
            $this->assertSame(1, $first->countFor('late_more_30'));
            // Komponen ber-kolom lama selalu dibaca dari kolomnya, bukan dari JSON.
            $this->assertSame(2, $first->countFor('sick_days'));
            $this->assertSame(1, $first->countFor('izin'));
            $this->assertSame(2, $first->countFor('mangkir'));
            $this->assertSame(3, $first->countFor('cuti_tahunan'));
            $this->assertSame(0, $first->countFor('cuti_lainnya'));
            $this->assertSame(0, $first->countFor('tidak_dikenal'));
            $this->assertSame(
                ['late_less_30' => 4, 'late_more_30' => 1, 'sick_days' => 2, 'izin' => 1, 'mangkir' => 2, 'cuti_tahunan' => 3, 'cuti_lainnya' => 0],
                $first->componentCounts(),
            );

            $this->assertNull($second->component_counts);
            $this->assertSame(['late_less_30' => 1, 'late_more_30' => 0, 'sick_days' => 0, 'izin' => 0, 'mangkir' => 0, 'cuti_tahunan' => 0, 'cuti_lainnya' => 0], $second->componentCounts());

            $rule = ScoringRuleService::forPeriode($first->periode);
            $score = PerformanceScoreCalculator::attendanceFor($first, $rule);
            // (22 - 4 - 1 - 2) / 22 * 100 - (4 + 3 + 5 + 10)
            $this->assertEqualsWithDelta((15 / 22 * 100 - 22) / 100 * 15, $score->score, 1e-9);
        });

        $this->assertSame(0, $queries);
    }

    public function test_migrations_are_idempotent_and_reversible(): void
    {
        config()->set('database.connections.scoring_migrations', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('scoring_migrations');

        try {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('username');
            });
            Schema::create('attendances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id');
                $table->string('periode');
                $table->integer('work_days');
                $table->integer('late_less_30')->default(0);
                $table->integer('late_more_30')->default(0);
                $table->integer('sick_days')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
            DB::table('users')->insert(['id' => 1, 'username' => 'lama']);
            DB::table('attendances')->insert(['id' => 1, 'user_id' => 1, 'periode' => '2026-09', 'work_days' => 22, 'sick_days' => 2]);

            $components = require database_path('migrations/2026_10_18_090000_create_attendance_components_table.php');
            $rules = require database_path('migrations/2026_10_18_090100_create_scoring_rules_table.php');

            $components->up();
            $rules->up();
            $components->up();
            $rules->up();

            $this->assertSame(self::COMPONENT_CODES, DB::table('attendance_components')->orderBy('sort_order')->pluck('code')->all());
            $this->assertSame(['2000-01', '2026-11'], DB::table('scoring_rules')->orderBy('effective_from')->pluck('effective_from')->all());
            $this->assertSame('standard', DB::table('scoring_rules')->where('effective_from', '2026-11')->value('engine'));
            $this->assertTrue(Schema::hasColumn('attendances', 'component_counts'));
            $this->assertNull(DB::table('attendances')->where('id', 1)->value('component_counts'));

            $rules->down();
            $components->down();
            $rules->down();
            $components->down();

            $this->assertFalse(Schema::hasTable('scoring_rules'));
            $this->assertFalse(Schema::hasTable('attendance_components'));
            $this->assertFalse(Schema::hasColumn('attendances', 'component_counts'));
            $this->assertSame(2, (int) DB::table('attendances')->where('id', 1)->value('sick_days'));
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('scoring_migrations');
        }
    }

    public function test_migrate_pretend_previews_the_attendances_component_counts_change(): void
    {
        config()->set('database.connections.scoring_pretend', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('scoring_pretend');

        try {
            Schema::create('attendances', function (Blueprint $table) {
                $table->id();
                $table->integer('sick_days')->default(0);
            });
            $components = require database_path('migrations/2026_10_18_090000_create_attendance_components_table.php');

            // `migrate --pretend`: introspeksi schema kosong, tetapi ALTER attendances harus tetap tampil.
            $up = implode("\n", array_column(DB::connection()->pretend(fn () => $components->up()), 'query'));
            $this->assertMatchesRegularExpression('/alter table "attendances" add column "component_counts"/', $up);
            $this->assertFalse(Schema::hasColumn('attendances', 'component_counts'));

            $components->up();
            $this->assertTrue(Schema::hasColumn('attendances', 'component_counts'));

            $down = implode("\n", array_column(DB::connection()->pretend(fn () => $components->down()), 'query'));
            $this->assertMatchesRegularExpression('/alter table "attendances" drop column "component_counts"/', $down);
            $this->assertTrue(Schema::hasColumn('attendances', 'component_counts'));
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('scoring_pretend');
        }
    }

    public function test_late_deploy_never_rescores_attendance_already_entered_for_the_new_rule_months(): void
    {
        // Deploy 8 Desember 2026: kehadiran November 2026 sudah diimport dengan kode lama (Sakit = sakit + izin).
        // Versi baru mulai Desember 2026 (belum terkunci), November tetap dinilai aturan lama. Data yang dihapus
        // (soft delete), periode sesudah bulan deploy (salah ketik), dan periode lama tidak menggeser aturan.
        $this->travelTo(Date::create(2026, 12, 8, 9));
        $late = $this->runScoringSeedMigrations([
            ['periode' => '2026-09'],
            ['periode' => '2026-10'],
            ['periode' => '2026-11'],
            ['periode' => '2026-12', 'deleted_at' => '2026-12-05 10:00:00'],
            ['periode' => '2027-06'],
        ]);
        $this->assertSame([
            ['2000-01', 'Aturan Lama', 'Aturan sebelum Desember 2026. Kolom Sakit berisi sakit dan izin sehingga tetap dipenalti 5 poin per hari.', true],
            ['2026-12', 'Aturan Kehadiran Desember 2026', 'Telat > 5 menit 1 poin, telat > 30 menit 3 poin, izin dan mangkir 5 poin per hari; sakit dan cuti tidak mengurangi poin.', false],
        ], $late['rules']);
        $this->assertSame('Sebelum Desember 2026 kolom ini berisi sakit dan izin (Sakit/Izin).', $late['sick_description']);

        // Deploy Desember tanpa data November: versi baru tetap mulai November 2026 (tidak ada data yang dinilai ulang).
        $onTimeData = $this->runScoringSeedMigrations([['periode' => '2026-10']]);
        $this->assertSame(['2026-11', 'Aturan Kehadiran November 2026'], array_slice($onTimeData['rules'][1], 0, 2));
        $this->assertSame('Sebelum November 2026 kolom ini berisi sakit dan izin (Sakit/Izin).', $onTimeData['sick_description']);

        // Deploy November dengan data November yang sudah terisi (form lama default bulan berjalan): mulai Desember.
        $this->travelTo(Date::create(2026, 11, 20, 9));
        $november = $this->runScoringSeedMigrations([['periode' => '2026-11'], ['periode' => '2026-9']]);
        $this->assertSame(['2026-12', 'Aturan Kehadiran Desember 2026'], array_slice($november['rules'][1], 0, 2));
        $this->assertFalse($november['rules'][1][3]);
    }

    public function test_periods_before_the_first_database_version_use_the_hard_coded_legacy_rule(): void
    {
        // Baris versi lama hilang lewat query builder (melewati penjaga Eloquent).
        DB::table('scoring_rules')->where('effective_from', '2000-01')->delete();
        ScoringRuleService::clearCache();

        foreach (['2026-10', '2026-09', '1900-01', '1970-01', 'garbage', null] as $periode) {
            $rule = ScoringRuleService::forPeriode($periode);
            $this->assertEquals(ScoringRuleData::legacy(), $rule, var_export($periode, true));
            $this->assertEqualsWithDelta(5.0, $rule->penaltyPoints('sick_days'), 1e-9);
        }

        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2026-11')->effectiveFrom);
        $this->assertFalse(ScoringRuleService::forPeriode('2027-01')->isFallback());
    }

    public function test_legacy_component_codes_always_read_their_attendances_column(): void
    {
        $user = $this->fixtureUser('karyawan', 'STAFF', $this->fixtureArea('COO'));
        Attendance::query()->create([
            'user_id' => $user->id,
            'periode' => '2026-09',
            'work_days' => 22,
            'late_less_30' => 2,
            'late_more_30' => 1,
            'sick_days' => 1,
            'component_counts' => ['sick_days' => 0, 'late_less_30' => 0],
        ]);

        // legacy_column dikosongkan lewat query builder: data historis tetap dibaca dari kolomnya.
        DB::table('attendance_components')->whereIn('code', ['sick_days', 'late_less_30'])->update(['legacy_column' => null]);
        ScoringRuleService::clearCache();
        $this->assertNull(ScoringRuleService::component('sick_days')->legacyColumn);

        $attendance = Attendance::query()->firstOrFail();
        $this->assertSame(1, $attendance->countFor('sick_days'));
        $this->assertSame(2, $attendance->countFor('late_less_30'));
        $this->assertEqualsWithDelta(
            ((22 - 2 - 1 - 1) / 22 * 100 - 10) / 100 * 15,
            PerformanceScoreCalculator::attendanceFor($attendance, ScoringRuleService::forPeriode('2026-09'))->score,
            1e-9,
        );
    }

    public function test_count_for_rejects_rows_loaded_without_the_needed_columns(): void
    {
        $user = $this->fixtureUser('karyawan', 'STAFF', $this->fixtureArea('COO'));
        $created = Attendance::query()->create([
            'user_id' => $user->id,
            'periode' => '2026-11',
            'work_days' => 20,
            'component_counts' => ['izin' => 2, 'mangkir' => 1],
        ]);
        $rule = ScoringRuleService::forPeriode('2026-11');
        $expected = (100 - 15) / 100 * 15;

        // Kolom yang dipakai leaderboard saat ini (tanpa component_counts): izin/mangkir tidak boleh hilang diam-diam.
        $partial = Attendance::query()->select(['id', 'user_id', 'late_less_30', 'late_more_30', 'sick_days', 'work_days'])->firstOrFail();
        $this->assertSame(0, $partial->countFor('sick_days'));
        $this->assertMissingAttribute(fn () => $partial->countFor('izin'), 'component_counts');
        $this->assertMissingAttribute(fn () => PerformanceScoreCalculator::attendanceFor($partial, $rule), 'component_counts');

        $withoutWorkDays = Attendance::query()->select(['id', 'late_less_30', 'late_more_30', 'sick_days', 'component_counts'])->firstOrFail();
        $this->assertMissingAttribute(fn () => PerformanceScoreCalculator::attendanceFor($withoutWorkDays, $rule), 'work_days');

        $withoutLegacy = Attendance::query()->select(['id', 'work_days', 'component_counts'])->firstOrFail();
        $this->assertMissingAttribute(fn () => $withoutLegacy->countFor('late_more_30'), 'late_more_30');

        $scoring = Attendance::query()->select(['id', 'user_id', ...Attendance::SCORING_COLUMNS])->firstOrFail();
        $this->assertSame(2, $scoring->countFor('izin'));
        $this->assertEqualsWithDelta($expected, PerformanceScoreCalculator::attendanceFor($scoring, $rule)->score, 1e-9);

        // Baris yang baru dibuat (tanpa refresh) memakai default kolom.
        $this->assertSame(2, $created->countFor('izin'));
        $this->assertSame(0, $created->countFor('late_less_30'));
        $this->assertEqualsWithDelta($expected, PerformanceScoreCalculator::attendanceFor($created, $rule)->score, 1e-9);
    }

    public function test_dates_with_a_time_zone_resolve_in_the_application_time_zone(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));

        $this->assertSame('2026-11', Periode::normalize('2026-11-15T10:00:00Z'));
        $this->assertSame('2026-11', Periode::normalize('2026-11-15T10:00:00+07:00'));
        $this->assertSame('2026-11', Periode::normalize('2026-11-15T10:00+0700'));
        // Serialisasi tanggal bawaan Laravel (toJSON/toArray).
        $this->assertSame('2026-11', Periode::normalize(Date::create(2026, 11, 15, 10)->toJSON()));
        $this->assertSame('2026-11', Periode::normalize('2026-11-15T10:00:00.000000Z'));
        // Batas bulan: 31 Okt 17:00 UTC = 1 Nov 00:00 WIB.
        $this->assertSame('2026-11', Periode::normalize('2026-10-31T17:00:00Z'));
        $this->assertSame('2026-10', Periode::normalize('2026-10-31T16:59:59Z'));
        $this->assertSame('2026-10', Periode::normalize('2026-11-01T00:30:00+09:00'));
        $this->assertSame('2026-11', Periode::normalize(new DateTimeImmutable('2026-10-31 20:00:00', new DateTimeZone('UTC'))));
        $this->assertNull(Periode::normalize('2026-11-15T25:00:00Z'));
        $this->assertNull(Periode::normalize('2026-11-15Z'));

        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2026-11-15T10:00:00Z')->effectiveFrom);
        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2026-11-15T10:00:00+07:00')->effectiveFrom);
        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2026-11-15T10:00:00.000000Z')->effectiveFrom);
        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2026-10-31T17:00:00Z')->effectiveFrom);
    }

    public function test_rolled_back_rule_change_is_never_cached(): void
    {
        $november = ScoringRule::query()->where('effective_from', '2026-11')->firstOrFail();
        $this->assertEqualsWithDelta(5.0, ScoringRuleService::forPeriode('2026-11')->penaltyPoints('mangkir'), 1e-9);
        $version = ScoringRuleService::cacheVersion();

        DB::beginTransaction();
        $rules = $november->attendance_rules;
        $rules[4]['penalty_points'] = 50;
        $november->update(['attendance_rules' => $rules]);

        // Request yang mengubah melihat perubahannya sendiri, tetapi versi cache belum naik dan tidak ada yang di-cache.
        $this->assertEqualsWithDelta(50.0, ScoringRuleService::forPeriode('2026-11')->penaltyPoints('mangkir'), 1e-9);
        $this->assertSame($version, (int) Cache::get($this->versionKey(), 1));
        DB::rollBack();

        $this->assertSame($version, (int) Cache::get($this->versionKey(), 1));
        $this->assertEqualsWithDelta(5.0, ScoringRuleService::forPeriode('2026-11')->penaltyPoints('mangkir'), 1e-9);
        $this->app->forgetScopedInstances();
        $this->assertEqualsWithDelta(5.0, ScoringRuleService::forPeriode('2026-11')->penaltyPoints('mangkir'), 1e-9);

        // Cache kosong saat transaksi berjalan: bacaan di dalam transaksi tetap tidak menulis cache.
        Cache::forget($this->payloadKey($version));
        $this->app->forgetScopedInstances();
        DB::beginTransaction();
        $november->fresh()->update(['attendance_rules' => $rules]);
        ScoringRuleService::forPeriode('2026-11');
        $this->assertFalse(Cache::has($this->payloadKey($version)));
        DB::rollBack();
        $this->app->forgetScopedInstances();
        $this->assertEqualsWithDelta(5.0, ScoringRuleService::forPeriode('2026-11')->penaltyPoints('mangkir'), 1e-9);

        // Commit: versi naik sekali setelah commit dan perubahan terlihat di request berikutnya.
        DB::transaction(function () use ($november, $rules, $version): void {
            $november->fresh()->update(['attendance_rules' => $rules]);
            $this->assertSame($version, (int) Cache::get($this->versionKey(), 1));
        });
        $this->assertSame($version + 1, (int) Cache::get($this->versionKey(), 1));
        $this->app->forgetScopedInstances();
        $this->assertEqualsWithDelta(50.0, ScoringRuleService::forPeriode('2026-11')->penaltyPoints('mangkir'), 1e-9);
    }

    public function test_cached_payload_with_an_unknown_shape_is_reloaded_from_the_database(): void
    {
        $version = ScoringRuleService::cacheVersion();
        $key = $this->payloadKey($version);
        ScoringRuleService::forPeriode('2026-09');
        $payload = Cache::get($key);
        $this->assertIsArray($payload);

        // Payload dari kode lama tanpa review_max_points.
        unset($payload['rules'][0]['review_max_points']);
        Cache::put($key, $payload, 600);
        $this->app->forgetScopedInstances();

        $this->assertSame(20, ScoringRuleService::forPeriode('2026-09')->reviewMaxPoints);
        $this->assertArrayHasKey('review_max_points', Cache::get($key)['rules'][0]);

        foreach (['rusak', ['rules' => 'x', 'components' => []], ['rules' => [], 'components' => [['code' => 'izin']]]] as $broken) {
            Cache::put($key, $broken, 600);
            $this->app->forgetScopedInstances();
            $this->assertSame('2026-11', ScoringRuleService::forPeriode('2026-12')->effectiveFrom);
            $this->assertSame('Izin', ScoringRuleService::component('izin')->name);
        }
    }

    private function assertRejected(callable $callback, string $field): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), json_encode($exception->errors()));

            return;
        }

        $this->fail("Perubahan seharusnya ditolak ({$field}).");
    }

    private function assertMissingAttribute(callable $callback, string $attribute): void
    {
        try {
            $callback();
        } catch (MissingAttributeException $exception) {
            $this->assertStringContainsString("[{$attribute}]", $exception->getMessage());

            return;
        }

        $this->fail("Kolom {$attribute} yang tidak di-select seharusnya ditolak.");
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        $listening = true;
        DB::listen(function () use (&$count, &$listening): void {
            if ($listening) {
                $count++;
            }
        });

        $callback();
        $listening = false;

        return $count;
    }

    /**
     * Kunci cache aturan diberi tanda database koneksi default (sama dengan ScoringRuleService::databaseTag()).
     */
    private function databaseTag(): string
    {
        $connection = (string) config('database.default');

        return substr(sha1($connection.'|'.(string) config("database.connections.{$connection}.database")), 0, 12);
    }

    private function versionKey(): string
    {
        return 'scoring_rules_version:'.$this->databaseTag();
    }

    private function payloadKey(int $version): string
    {
        return 'scoring_rules:'.$this->databaseTag().':f1:v'.$version.':payload';
    }

    /**
     * Jalankan migration attendance_components + scoring_rules (dua kali, idempoten) di database sqlite terpisah
     * yang sudah berisi data kehadiran $rows. Mengembalikan versi aturan [effective_from, name, notes, terkunci]
     * dan deskripsi komponen sick_days.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{rules: list<array{0: string, 1: string, 2: string|null, 3: bool}>, sick_description: string|null}
     */
    private function runScoringSeedMigrations(array $rows): array
    {
        config()->set('database.connections.scoring_seed', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('scoring_seed');

        try {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('username');
            });
            Schema::create('attendances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id');
                $table->string('periode');
                $table->integer('work_days');
                $table->integer('late_less_30')->default(0);
                $table->integer('late_more_30')->default(0);
                $table->integer('sick_days')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
            DB::table('users')->insert(['id' => 1, 'username' => 'lama']);

            foreach ($rows as $row) {
                DB::table('attendances')->insert($row + ['user_id' => 1, 'work_days' => 22, 'sick_days' => 3]);
            }

            $components = require database_path('migrations/2026_10_18_090000_create_attendance_components_table.php');
            $rules = require database_path('migrations/2026_10_18_090100_create_scoring_rules_table.php');
            $components->up();
            $rules->up();
            $rules->up();

            return [
                'rules' => ScoringRule::query()->orderBy('effective_from')->get()
                    ->map(fn (ScoringRule $rule): array => [$rule->effective_from, $rule->name, $rule->notes, $rule->isLocked()])
                    ->all(),
                'sick_description' => DB::table('attendance_components')->where('code', 'sick_days')->value('description'),
            ];
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('scoring_seed');
        }
    }
}
