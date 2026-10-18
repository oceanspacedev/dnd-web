<?php

namespace Tests\Feature\Scoring;

use App\Exports\AttendanceExport;
use App\Exports\AttendanceImportTemplateExport;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Attendances\Pages\CreateAttendance;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Imports\AttendanceImport;
use App\Models\Attendance;
use App\Models\AttendanceComponent;
use App\Models\User;
use App\Services\ScoringRuleService;
use Database\Seeders\LoadTestSeeder;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Input/output kehadiran berbasis komponen (master attendance_components): form & tabel panel,
 * import/template/export Excel, dan API presensi. Setiap data memakai versi aturan periodenya sendiri.
 */
class AttendanceInputOutputTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private const DELTA = 1e-9;

    /** Tujuh judul template lama tidak berubah; komponen lain ditambahkan di belakang sebagai "Nama [kode]". */
    private const TEMPLATE_HEADINGS = [
        'ID karyawan',
        'Nama Lengkap',
        'Periode',
        'Hari Kerja',
        'Late Less 30 min',
        'Late More 30 min',
        'Sakit',
        'Izin [izin]',
        'Mangkir (tanpa keterangan) [mangkir]',
        'Cuti tahunan [cuti_tahunan]',
        'Cuti lainnya [cuti_lainnya]',
    ];

    private const EXPORT_LEGACY_HEADINGS = ['ID Karyawan', 'Nama Lengkap', 'Periode', 'Hari Kerja', 'Late Less 30 min', 'Late More 30 min', 'Sakit'];

    private User $admin;

    private User $alpha;

    private User $bravo;

    protected function setUp(): void
    {
        parent::setUp();
        // Sesudah November 2026: aturan "hari ini" adalah versi baru, data lama tetap memakai aturan lama.
        $this->travelTo(Date::create(2027, 1, 15, 9));
        Cache::flush();
        $this->resetAccessMemos();

        $area = $this->fixtureArea('COO');
        $this->admin = $this->fixtureGlobalAdmin('boss', $area);
        $this->alpha = $this->fixtureUser('alpha', 'STAFF', $area, null, ['employee_id' => 'EMP-ALPHA']);
        $this->bravo = $this->fixtureUser('bravo', 'STAFF', $area, null, ['employee_id' => 'EMP-BRAVO']);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Panel: form & tabel
    // ---------------------------------------------------------------------

    public function test_form_has_one_field_per_active_component_and_stores_november_2026_counts(): void
    {
        AttendanceComponent::query()->create(['code' => 'dinas_luar', 'name' => 'Dinas luar', 'unit' => 'hari', 'description' => 'Tugas di luar kantor.', 'sort_order' => 8]);
        AttendanceComponent::query()->create(['code' => 'arsip', 'name' => 'Arsip', 'unit' => 'hari', 'sort_order' => 9, 'is_active' => false]);
        $this->actingAs($this->admin);

        Livewire::test(CreateAttendance::class)
            ->assertFormFieldExists('work_days')
            // Komponen lama terikat ke kolomnya, komponen lain ke component_counts.{kode}.
            ->assertFormFieldExists('late_less_30', fn (TextInput $field): bool => $field->getLabel() === 'Terlambat ≤ 30 menit (kejadian)')
            ->assertFormFieldExists('sick_days', fn (TextInput $field): bool => $field->getLabel() === 'Sakit (hari)')
            ->assertFormFieldExists('component_counts.izin', fn (TextInput $field): bool => $field->getLabel() === 'Izin (hari)')
            ->assertFormFieldExists('component_counts.mangkir')
            ->assertFormFieldExists('component_counts.cuti_tahunan')
            ->assertFormFieldExists('component_counts.cuti_lainnya')
            // Komponen baru di master langsung punya field tanpa perubahan kode; komponen nonaktif tidak.
            ->assertFormFieldExists('component_counts.dinas_luar', fn (TextInput $field): bool => $field->getLabel() === 'Dinas luar (hari)')
            ->assertFormFieldDoesNotExist('component_counts.arsip')
            // Teks bantuan = deskripsi komponen; hint & keterangan aturan mengikuti periode (default bulan lalu = 2026-12).
            ->assertSee('Sebelum November 2026 kolom ini berisi sakit dan izin (Sakit/Izin).')
            ->assertSee('Tugas di luar kantor.')
            ->assertSee('Aturan penilaian: Aturan Kehadiran November 2026 (berlaku sejak 2026-11, bobot kehadiran 15%).')
            ->assertSee('Penalti 5 poin/hari')
            // Komponen yang belum tercantum di aturan periode (dinas_luar) tidak bisa diisi (dulu: hint "Tidak dihitung").
            ->assertSee('Tidak dipakai di aturan periode ini')
            ->assertFormFieldDisabled('component_counts.dinas_luar')
            ->assertDontSee('Sakit/Izin</span>', false)
            ->assertFormSet([
                'late_less_30' => 0,
                'component_counts.izin' => 0,
                'component_counts.mangkir' => 0,
            ])
            ->fillForm([
                'user_id' => $this->alpha->id,
                'periode' => '2026-11',
                'work_days' => 20,
                'late_less_30' => 1,
                'late_more_30' => null,
                'sick_days' => 1,
                'component_counts.izin' => 2,
                'component_counts.mangkir' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $attendance = Attendance::query()->where('user_id', $this->alpha->id)->where('periode', '2026-11')->firstOrFail();
        $this->assertSame(1, (int) $attendance->late_less_30);
        // Kosong disimpan 0 (kolom NOT NULL).
        $this->assertSame(0, (int) $attendance->late_more_30);
        $this->assertSame(1, (int) $attendance->sick_days);
        // Hanya hitungan > 0 yang disimpan di JSON.
        $this->assertSame(['izin' => 2, 'mangkir' => 1], $attendance->component_counts);

        // Aturan November 2026: dasar (20 - 1 - 1) / 20 = 90%, penalti 1 + 0 (sakit) + 2 x 5 (izin) + 1 x 5 (mangkir) = 16.
        $score = AttendanceResource::attendanceScore($attendance);
        $this->assertEqualsWithDelta(90.0, $score->basePct, self::DELTA);
        $this->assertEqualsWithDelta(16.0, $score->penaltyPoints, self::DELTA);
        $this->assertEqualsWithDelta(74 / 100 * 15, $score->score, self::DELTA);
    }

    public function test_form_rejects_invalid_counts_and_junk_periode(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'user_id' => $this->alpha->id,
                'periode' => '1900-01',
                'work_days' => 20,
                'component_counts.izin' => -1,
            ])
            ->call('create')
            ->assertHasFormErrors(['periode', 'component_counts.izin']);

        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_edit_form_hydrates_counts_and_keeps_counts_of_components_not_on_the_form(): void
    {
        AttendanceComponent::query()->create(['code' => 'arsip', 'name' => 'Arsip', 'unit' => 'hari', 'sort_order' => 9, 'is_active' => false]);
        $attendance = Attendance::query()->create([
            'user_id' => $this->alpha->id,
            'periode' => '2026-12',
            'work_days' => 21,
            'component_counts' => ['izin' => 1, 'arsip' => 3],
        ]);
        $legacy = Attendance::query()->create(['user_id' => $this->bravo->id, 'periode' => '2026-09', 'work_days' => 20]);
        $this->actingAs($this->admin);

        // Baris lama tanpa component_counts tampil 0.
        Livewire::test(EditAttendance::class, ['record' => $legacy->id])
            ->assertFormSet(['component_counts.izin' => 0, 'component_counts.mangkir' => 0, 'sick_days' => 0]);

        Livewire::test(EditAttendance::class, ['record' => $attendance->id])
            ->assertFormSet(['component_counts.izin' => 1, 'component_counts.mangkir' => 0])
            ->fillForm(['component_counts.izin' => 0, 'component_counts.mangkir' => 2])
            ->call('save')
            ->assertHasNoFormErrors();

        // Komponen nonaktif (tidak ada di form) tetap tersimpan; izin 0 dihapus dari JSON.
        $this->assertSame(['arsip' => 3, 'mangkir' => 2], $attendance->refresh()->component_counts);
    }

    public function test_table_shows_component_columns_and_score_with_the_rule_of_each_rows_periode(): void
    {
        // Oktober 2026 = aturan lama (izin tidak dinilai, sakit 5 poin); November 2026 = aturan baru.
        $october = Attendance::query()->create([
            'user_id' => $this->alpha->id, 'periode' => '2026-10', 'work_days' => 20,
            'late_less_30' => 2, 'sick_days' => 2, 'component_counts' => ['izin' => 2],
        ]);
        $november = Attendance::query()->create([
            'user_id' => $this->bravo->id, 'periode' => '2026-11', 'work_days' => 20,
            'late_less_30' => 2, 'sick_days' => 2, 'component_counts' => ['izin' => 2, 'mangkir' => 1],
        ]);
        $this->actingAs($this->admin);

        $legacyScore = ((20 - 2 - 2) / 20 * 100 - (2 + 10)) / 100 * 15;
        $newScore = ((20 - 2 - 2) / 20 * 100 - (2 + 0 + 10 + 5)) / 100 * 15;

        Livewire::test(ListAttendances::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$october, $november])
            ->assertTableColumnExists('sick_days', fn ($column): bool => $column->getLabel() === 'Sakit')
            ->assertTableColumnExists('late_less_30', fn ($column): bool => $column->getLabel() === 'Terlambat ≤ 30 menit')
            ->assertTableColumnExists('component_izin', fn ($column): bool => $column->getLabel() === 'Izin' && $column->isToggledHiddenByDefault())
            ->assertTableColumnExists('component_mangkir')
            ->assertCanRenderTableColumn('sick_days')
            ->assertCanNotRenderTableColumn('component_izin')
            ->assertTableColumnStateSet('component_izin', 2, $october)
            ->assertTableColumnStateSet('component_mangkir', 1, $november)
            ->assertTableColumnStateSet('attendance_score', $legacyScore, $october)
            ->assertTableColumnStateSet('attendance_score', $newScore, $november);

        // Skor per baris tanpa query aturan per baris.
        $records = AttendanceResource::getEloquentQuery()->get();
        ScoringRuleService::forPeriode('2026-10');
        ScoringRuleService::forPeriode('2026-11');
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($records as $record) {
            AttendanceResource::attendanceScore($record);
            AttendanceResource::attendanceScoreTooltip($record);
        }
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    // ---------------------------------------------------------------------
    // API
    // ---------------------------------------------------------------------

    public function test_api_store_accepts_components_and_returns_metrics_of_the_records_rule(): void
    {
        Sanctum::actingAs($this->admin);

        $data = $this->postJson('/api/v1/attendances', [
            'user_id' => $this->alpha->id,
            'periode' => '2026-11',
            'work_days' => 20,
            'sick_days' => 1,
            'components' => ['late_less_30' => 1, 'izin' => 2, 'mangkir' => 1, 'cuti_tahunan' => 0],
        ])->assertCreated()->json('data');

        $attendance = Attendance::query()->findOrFail($data['id']);
        $this->assertSame(1, (int) $attendance->late_less_30);
        $this->assertSame(1, (int) $attendance->sick_days);
        $this->assertSame(['izin' => 2, 'mangkir' => 1], $attendance->component_counts);

        // Dasar 90%, penalti 1 + 0 + 10 + 5 = 16 => 74% => 11.1.
        $this->assertSame(1, $data['late_less_30']);
        $this->assertEqualsWithDelta(90.0, $data['metrics']['initial_achievement_pct'], self::DELTA);
        $this->assertSame(16, $data['metrics']['penalty_points']);
        $this->assertEqualsWithDelta(74.0, $data['metrics']['final_achievement_pct'], self::DELTA);
        $this->assertEqualsWithDelta(11.1, $data['metrics']['attendance_score_15pct'], self::DELTA);
        $this->assertEqualsWithDelta(11.1, $data['metrics']['attendance_score'], self::DELTA);
        $this->assertEquals(15, $data['attendance_weight']);
        $this->assertSame(['name' => 'Izin', 'unit' => 'hari', 'count' => 2], $data['components']['izin']);
        $this->assertSame(['name' => 'Mangkir (tanpa keterangan)', 'unit' => 'hari', 'count' => 1], $data['components']['mangkir']);
        $this->assertSame(1, $data['components']['sick_days']['count']);
        $this->assertSame(0, $data['components']['cuti_lainnya']['count']);
        $this->assertSame(['id', 'name', 'effective_from'], array_keys($data['scoring_rule']));
        $this->assertSame('2026-11', $data['scoring_rule']['effective_from']);
        $this->assertSame('Aturan Kehadiran November 2026', $data['scoring_rule']['name']);

        // Detail & daftar memberi angka yang sama.
        $this->getJson('/api/v1/attendances/'.$attendance->id)
            ->assertOk()
            ->assertJsonPath('data.metrics.penalty_points', 16)
            ->assertJsonPath('data.components.izin.count', 2);
        $this->getJson('/api/v1/attendances?periode=2026-11')
            ->assertOk()
            ->assertJsonPath('data.0.components.mangkir.count', 1);
    }

    public function test_api_update_merges_components_and_never_writes_null_counters(): void
    {
        Sanctum::actingAs($this->admin);
        $attendance = Attendance::query()->create([
            'user_id' => $this->alpha->id,
            'periode' => '2026-12',
            'work_days' => 22,
            'late_less_30' => 3,
            'late_more_30' => 1,
            'component_counts' => ['izin' => 2, 'mangkir' => 1],
        ]);

        $this->putJson('/api/v1/attendances/'.$attendance->id, [
            'late_less_30' => null,
            'components' => ['mangkir' => 0, 'cuti_tahunan' => 2],
        ])->assertOk()
            ->assertJsonPath('data.late_less_30', 0)
            ->assertJsonPath('data.components.izin.count', 2)
            ->assertJsonPath('data.components.mangkir.count', 0)
            ->assertJsonPath('data.components.cuti_tahunan.count', 2);

        $attendance->refresh();
        $this->assertSame(0, (int) $attendance->late_less_30);
        $this->assertSame(1, (int) $attendance->late_more_30);
        $this->assertSame(['cuti_tahunan' => 2, 'izin' => 2], $attendance->component_counts);

        // Field lama saja tidak menyentuh component_counts.
        $this->putJson('/api/v1/attendances/'.$attendance->id, ['work_days' => 20, 'sick_days' => 1])->assertOk();
        $this->assertSame(['cuti_tahunan' => 2, 'izin' => 2], $attendance->refresh()->component_counts);
        $this->assertSame(1, (int) $attendance->sick_days);
    }

    public function test_api_validates_components_and_periode(): void
    {
        Sanctum::actingAs($this->admin);
        $payload = ['user_id' => $this->alpha->id, 'periode' => '2026-11', 'work_days' => 20];

        $this->postJson('/api/v1/attendances', $payload + ['components' => ['izin' => 1, 'lembur' => 2]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['components']);
        $this->postJson('/api/v1/attendances', $payload + ['components' => ['izin' => -1]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['components.izin']);
        $this->postJson('/api/v1/attendances', $payload + ['components' => ['mangkir' => null]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['components.mangkir']);
        $this->postJson('/api/v1/attendances', $payload + ['components' => [3]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['components']);
        $this->postJson('/api/v1/attendances', $payload + ['sick_days' => 1, 'components' => ['sick_days' => 2]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['components']);

        foreach (['2026-13', 'November', '1900-01', '1970-01', '', '2026'] as $periode) {
            $this->postJson('/api/v1/attendances', ['periode' => $periode] + $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['periode']);
        }
        $this->assertSame(0, Attendance::query()->count());

        // Periode dinormalisasi ke 'Y-m' sebelum disimpan dan dicek duplikatnya.
        $this->postJson('/api/v1/attendances', ['periode' => '2026-1'] + $payload)
            ->assertCreated()
            ->assertJsonPath('data.periode', '2026-01')
            ->assertJsonPath('data.scoring_rule.name', 'Aturan Lama');
        $this->postJson('/api/v1/attendances', ['periode' => '2026-01-15'] + $payload)->assertStatus(422);
        $attendance = Attendance::query()->sole();
        $this->assertSame('2026-01', $attendance->periode);

        $this->putJson('/api/v1/attendances/'.$attendance->id, ['periode' => '2026-2'])
            ->assertOk()
            ->assertJsonPath('data.periode', '2026-02');
        $this->putJson('/api/v1/attendances/'.$attendance->id, ['periode' => '1970-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['periode']);
        $this->putJson('/api/v1/attendances/'.$attendance->id, ['components' => ['tidak_ada' => 1]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['components']);
    }

    public function test_api_legacy_periode_ignores_new_components_and_keeps_legacy_values(): void
    {
        Sanctum::actingAs($this->admin);
        // Oktober 2026 dengan izin/mangkir tercatat: aturan lama hanya menilai telat & sakit.
        $attendance = Attendance::query()->create([
            'user_id' => $this->alpha->id,
            'periode' => '2026-10',
            'work_days' => 22,
            'late_less_30' => 2,
            'late_more_30' => 1,
            'sick_days' => 1,
            'component_counts' => ['izin' => 3, 'mangkir' => 2],
        ]);

        $data = $this->getJson('/api/v1/attendances/'.$attendance->id)->assertOk()->json('data');

        $initial = (22 - 2 - 1 - 1) / 22 * 100;
        $this->assertEqualsWithDelta(round($initial, 2), $data['metrics']['initial_achievement_pct'], self::DELTA);
        $this->assertSame(10, $data['metrics']['penalty_points']);
        $this->assertEqualsWithDelta(round(($initial - 10) / 100 * 15, 2), $data['metrics']['attendance_score_15pct'], self::DELTA);
        $this->assertSame('Aturan Lama', $data['scoring_rule']['name']);
        $this->assertSame('2000-01', $data['scoring_rule']['effective_from']);
        $this->assertSame(3, $data['components']['izin']['count']);
    }

    public function test_api_list_works_when_the_attendances_table_has_no_component_counts_column(): void
    {
        Attendance::query()->create(['user_id' => $this->alpha->id, 'periode' => '2026-11', 'work_days' => 20, 'late_less_30' => 1]);
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn('component_counts');
        });
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/attendances')
            ->assertOk()
            ->assertJsonPath('data.0.components.izin.count', 0)
            ->assertJsonPath('data.0.late_less_30', 1)
            ->assertJsonPath('data.0.metrics.penalty_points', 1);
    }

    // ---------------------------------------------------------------------
    // Template, export & import Excel
    // ---------------------------------------------------------------------

    public function test_template_and_export_keep_the_old_headings_and_append_component_columns(): void
    {
        $template = new AttendanceImportTemplateExport;
        $this->assertSame(self::TEMPLATE_HEADINGS, $template->headings());
        $row = $template->map($template->collection()->firstWhere('employee_id', 'EMP-ALPHA'));
        // Template diisi periode bulan lalu (data kehadiran direkap setelah bulannya selesai).
        $this->assertSame(['EMP-ALPHA', 'ALPHA', '2026-12', ''], array_slice($row, 0, 4));
        $this->assertCount(count(self::TEMPLATE_HEADINGS), $row);

        // Export: tujuh judul export lama persis (termasuk 'ID Karyawan'), komponen baru di belakang.
        $this->assertSame([...self::EXPORT_LEGACY_HEADINGS, ...array_slice(self::TEMPLATE_HEADINGS, 7)], (new AttendanceExport)->headings());

        // Komponen lama tetap di kolom 5-7 walau dinonaktifkan / diurutkan ulang; komponen baru di belakang.
        // Komponen nonaktif yang tidak memengaruhi skor di versi mana pun (cuti_lainnya: penalti 0 di aturan
        // November 2026; arsip) tidak ada di template, tetapi tetap di export (hitungan tersimpannya tidak boleh hilang).
        // Template satu periode hanya memuat komponen yang tercantum di aturan periode itu: dinas_luar belum
        // tercantum di versi mana pun, jadi hanya ada di export.
        // sick_days masih dinilai aturan November 2026, jadi model menolak menonaktifkannya; simulasikan data lama
        // yang dinonaktifkan di luar Eloquent (SQL langsung).
        DB::table('attendance_components')->where('code', 'sick_days')->update(['is_active' => false]);
        ScoringRuleService::clearCache();
        AttendanceComponent::query()->where('code', 'izin')->firstOrFail()->update(['sort_order' => 0]);
        AttendanceComponent::query()->where('code', 'cuti_lainnya')->firstOrFail()->update(['is_active' => false]);
        AttendanceComponent::query()->create(['code' => 'dinas_luar', 'name' => 'Dinas luar', 'unit' => 'hari', 'sort_order' => 8]);
        AttendanceComponent::query()->create(['code' => 'arsip', 'name' => 'Arsip', 'unit' => 'hari', 'sort_order' => 9, 'is_active' => false]);

        $this->assertSame(
            [...array_slice(self::TEMPLATE_HEADINGS, 0, 7), 'Izin [izin]', 'Mangkir (tanpa keterangan) [mangkir]', 'Cuti tahunan [cuti_tahunan]'],
            (new AttendanceImportTemplateExport)->headings(),
        );
        $this->assertSame(
            [...self::EXPORT_LEGACY_HEADINGS, 'Izin [izin]', 'Mangkir (tanpa keterangan) [mangkir]', 'Cuti tahunan [cuti_tahunan]', 'Cuti lainnya [cuti_lainnya]', 'Dinas luar [dinas_luar]', 'Arsip [arsip]'],
            (new AttendanceExport)->headings(),
        );

        // Judul komponen diberi catatan berisi nama & keterangan komponen, mis. arti kolom 'Sakit' sejak November 2026.
        Storage::fake('local');
        Excel::store(new AttendanceImportTemplateExport, 'template.xlsx', 'local');
        $sheet = IOFactory::load(Storage::disk('local')->path('template.xlsx'))->getActiveSheet();
        $this->assertSame('Sakit', $sheet->getCell('G1')->getValue());
        $this->assertStringContainsString('Sakit (hari)', $sheet->getComment('G1')->getText()->getPlainText());
        $this->assertStringContainsString('Sebelum November 2026 kolom ini berisi sakit dan izin', $sheet->getComment('G1')->getText()->getPlainText());
        $this->assertStringContainsString('Komponen nonaktif.', $sheet->getComment('G1')->getText()->getPlainText());
        // Judul "Nama [kode]" tanpa keterangan tidak perlu catatan.
        $this->assertArrayNotHasKey('H1', $sheet->getComments());
    }

    public function test_export_rows_use_component_counts(): void
    {
        Attendance::query()->create([
            'user_id' => $this->alpha->id, 'periode' => '2026-11', 'work_days' => 20,
            'late_less_30' => 1, 'sick_days' => 1, 'component_counts' => ['izin' => 2, 'mangkir' => 1],
        ]);
        Attendance::query()->create([
            'user_id' => $this->bravo->id, 'periode' => '2026-09', 'work_days' => 21,
            'late_less_30' => 2, 'late_more_30' => 1, 'sick_days' => 3,
        ]);

        $export = new AttendanceExport;
        $rows = $export->collection()->map(fn (array $row): array => $export->map($row))->sortBy(2)->values()->all();

        $this->assertSame(['EMP-BRAVO', 'BRAVO', '2026-09', 21, 2, 1, 3, 0, 0, 0, 0], $rows[0]);
        $this->assertSame(['EMP-ALPHA', 'ALPHA', '2026-11', 20, 1, 0, 1, 2, 1, 0, 0], $rows[1]);
    }

    public function test_old_seven_column_rows_still_import_and_missing_components_default_to_zero(): void
    {
        $import = new AttendanceImport;

        $legacy = $import->model(['EMP-ALPHA', 'ALPHA', '2026-09', 20, 1, 0, 2]);
        $this->assertInstanceOf(Attendance::class, $legacy);
        $legacy->save();
        $november = $import->model(['', 'BRAVO', '2026-12', 22, 0, 1, 0]);
        $this->assertInstanceOf(Attendance::class, $november);
        $november->save();

        $legacy->refresh();
        $this->assertSame([20, 1, 0, 2], [(int) $legacy->work_days, (int) $legacy->late_less_30, (int) $legacy->late_more_30, (int) $legacy->sick_days]);
        $this->assertNull($legacy->component_counts);
        $this->assertSame(0, $november->refresh()->countFor('izin'));
        $this->assertSame(1, (int) $november->late_more_30);

        $summary = $import->getImportSummary();
        $this->assertSame(2, $summary['importedCount']);
        $this->assertSame(0, $summary['skippedCount']);
        // File lama tanpa kolom izin/mangkir untuk periode aturan baru: diperingatkan, dianggap 0.
        $this->assertContains('Kolom "Izin" tidak ada di file sehingga dianggap 0 untuk periode 2026-12.', $summary['warnings']);
        $this->assertContains('Kolom "Mangkir (tanpa keterangan)" tidak ada di file sehingga dianggap 0 untuk periode 2026-12.', $summary['warnings']);
        $this->assertCount(2, $summary['warnings']);

    }

    public function test_headerless_rows_read_only_the_seven_template_columns(): void
    {
        $import = new AttendanceImport;

        // Kolom catatan di belakang tidak membuat baris lama dilewati.
        $notes = $import->model(['EMP-ALPHA', 'ALPHA', '2026-09', 22, 1, 0, 0, 'WFH 2 hari']);
        $this->assertInstanceOf(Attendance::class, $notes);
        $this->assertSame(1, $notes->late_less_30);
        $this->assertNull($notes->component_counts);

        // Angka di kolom ke-8 dst. (mis. total/keterangan) tidak dibaca sebagai Izin/Mangkir: arti kolom tanpa
        // judul tidak bisa dipastikan (dan akan berubah bila urutan komponen diubah).
        $extra = $import->model(['EMP-BRAVO', 'BRAVO', '2026-11', 22, 1, 0, 1, 2, 1, 3]);
        $this->assertInstanceOf(Attendance::class, $extra);
        $this->assertNull($extra->component_counts);
        $this->assertSame(1, $extra->sick_days);

        $summary = $import->getImportSummary();
        $this->assertSame(2, $summary['importedCount']);
        $this->assertContains(
            'File tanpa baris judul: hanya 7 kolom pertama yang dibaca (ID karyawan, Nama Lengkap, Periode, Hari Kerja, Late Less 30 min, Late More 30 min, Sakit); kolom ke-8 dan seterusnya diabaikan. Gunakan template terbaru (dengan baris judul) untuk mengisi komponen lain.',
            $summary['warnings'],
        );
    }

    public function test_import_rejects_junk_periode_and_invalid_counts_with_a_reason_per_row(): void
    {
        $import = new AttendanceImport;

        // File tanpa kolom Periode: Work Days 20 terbaca sebagai tanggal Excel 1900-01-20.
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', 20, 0, 1, 0]));
        // Angka 0 => 1899/1900, teks tahun lama, teks bukan periode, periode kosong.
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', 0, 22, 0, 1, 1]));
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', '1970-01', 22, 0, 1, 1]));
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', 'Nov 2026', 22, 0, 0, 0]));
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', null, 22, 0, 0, 0]));
        // Hitungan tidak valid.
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', '2026-11', 22, -1, 0, 0]));
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', '2026-11', 22, 0, 0, 'dua']));
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', '2026-11', 'x', 0, 0, 0]));
        // Karyawan tidak dikenal.
        $this->assertNull($import->model(['EMP-NOPE', 'NOPE', '2026-11', 22, 0, 0, 0]));
        // Baris template yang belum diisi sama sekali diabaikan (bukan gagal); hari kerja kosong padahal komponen
        // terisi, 0, atau angka sampah; hitungan di atas batas / pecahan.
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', '2026-11', null, null, null, null]));
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', '2026-11', null, 2, null, null]));
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', '2026-11', 0, 0, 0, 0]));
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', '2026-11', 3201234567, 0, 0, 0]));
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', '2026-11', 22, 3201234567, 0, 0]));
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', '2026-11', 22, 0, 1.5, 0]));

        $summary = $import->getImportSummary();
        $this->assertSame(0, $summary['importedCount']);
        $this->assertSame(14, $summary['skippedCount']);
        $this->assertSame(1, $summary['unfilledCount']);
        $reasons = array_column($summary['skippedDetails'], 'reason');
        $this->assertStringContainsString('terbaca sebagai 1900-01', $reasons[0]);
        $this->assertStringContainsString('di luar rentang tahun 2000-2100', $reasons[0]);
        $this->assertStringContainsString('di luar rentang tahun 2000-2100', $reasons[1]);
        $this->assertStringContainsString('terbaca sebagai 1970-01', $reasons[2]);
        $this->assertStringContainsString('"Nov 2026" tidak dikenali', $reasons[3]);
        $this->assertStringContainsString('Periode kosong', $reasons[4]);
        $this->assertSame('Nilai Late Less 30 min harus bilangan bulat 0 sampai 999.', $reasons[5]);
        $this->assertSame('Nilai Sakit harus bilangan bulat 0 sampai 999.', $reasons[6]);
        $this->assertSame('Hari Kerja harus bilangan bulat 1 sampai 31.', $reasons[7]);
        $this->assertStringContainsString('Karyawan tidak ditemukan', $reasons[8]);
        $this->assertSame('Hari Kerja kosong. Isi jumlah hari kerja (1 sampai 31).', $reasons[9]);
        $this->assertSame('Hari Kerja harus bilangan bulat 1 sampai 31.', $reasons[10]);
        $this->assertSame('Hari Kerja harus bilangan bulat 1 sampai 31.', $reasons[11]);
        $this->assertSame('Nilai Late Less 30 min harus bilangan bulat 0 sampai 999.', $reasons[12]);
        $this->assertSame('Nilai Late More 30 min harus bilangan bulat 0 sampai 999.', $reasons[13]);
        $this->assertSame(0, Attendance::query()->count());

        // Tanggal Excel yang valid (serial 2026-11-01) dan teks d/m/Y tetap diterima.
        $serial = (new AttendanceImport)->model(['EMP-ALPHA', 'ALPHA', 46327, 22, 0, 0, 0]);
        $this->assertSame('2026-11', $serial?->periode);
        $this->assertSame('2026-11', (new AttendanceImport)->model(['EMP-ALPHA', 'ALPHA', '15/11/2026', 22, 0, 0, 0])?->periode);
    }

    public function test_import_maps_columns_by_heading_for_new_and_old_templates(): void
    {
        Storage::fake('local');
        AttendanceComponent::query()->create(['code' => 'dinas_luar', 'name' => 'Dinas luar', 'unit' => 'hari', 'sort_order' => 8]);
        // Komponen yang tidak tercantum di aturan periode barisnya tidak boleh diisi (baris dilewati, lihat
        // test_import_skips_a_row_that_fills_a_component_its_rule_does_not_use): cantumkan tanpa penalti.
        $this->listComponentsInEveryRule(['dinas_luar']);

        // Template baru dengan urutan kolom diacak dan satu kolom tidak dikenal.
        $this->storeSheet('new.xlsx', [
            ['Izin', 'Periode', 'ID karyawan', 'Nama Lengkap', 'Work Days', 'Mangkir (tanpa keterangan)', 'Sakit', 'Terlambat ≤ 30 menit', 'Dinas luar', 'Catatan'],
            [2, '2026-11', 'EMP-ALPHA', 'ALPHA', 20, 1, 1, 1, 4, 'abc'],
            [0, '2026-10', 'EMP-BRAVO', 'BRAVO', 21, 0, 2, 0, null, null],
        ]);
        $import = new AttendanceImport;
        Excel::import($import, 'new.xlsx', 'local');

        $summary = $import->getImportSummary();
        $this->assertSame(2, $summary['importedCount']);
        $this->assertSame(0, $summary['skippedCount']);
        // Kolom yang tidak ada di file dianggap 0 dan diperingatkan bila dinilai aturan periodenya (posisi template
        // kolom 6 sudah dipakai kolom lain, jadi tidak ditebak).
        $this->assertSame([
            'Kolom "Late More 30 min" tidak ada di file sehingga dianggap 0 untuk periode 2026-10, 2026-11.',
            'Kolom tidak dikenal diabaikan: Catatan.',
        ], $summary['warnings']);

        $november = Attendance::query()->where('user_id', $this->alpha->id)->sole();
        $this->assertSame('2026-11', $november->periode);
        $this->assertSame([20, 1, 0, 1], [(int) $november->work_days, (int) $november->late_less_30, (int) $november->late_more_30, (int) $november->sick_days]);
        $this->assertSame(['dinas_luar' => 4, 'izin' => 2, 'mangkir' => 1], $november->component_counts);
        $this->assertEqualsWithDelta(74 / 100 * 15, AttendanceResource::attendanceScore($november)->score, self::DELTA);

        $october = Attendance::query()->where('user_id', $this->bravo->id)->sole();
        $this->assertNull($october->component_counts);
        // Aturan lama: sakit tetap 5 poin per hari.
        $this->assertEqualsWithDelta(((21 - 2) / 21 * 100 - 10) / 100 * 15, AttendanceResource::attendanceScore($october)->score, self::DELTA);

        // Template lama (judul lama 'Sakit or Izin', 7 kolom) tetap terbaca.
        Attendance::query()->forceDelete();
        $this->storeSheet('old.xlsx', [
            ['ID karyawan', 'Nama Lengkap', 'Periode', 'Hari Kerja', 'Late Less 30 min', 'Late More 30 min', 'Sakit or Izin'],
            ['EMP-ALPHA', 'ALPHA', '2026-09', 22, 3, 1, 2],
        ]);
        $old = new AttendanceImport;
        Excel::import($old, 'old.xlsx', 'local');

        $this->assertSame(1, $old->getImportSummary()['importedCount']);
        $this->assertSame([], $old->getImportSummary()['warnings']);
        $attendance = Attendance::query()->sole();
        $this->assertSame([22, 3, 1, 2], [(int) $attendance->work_days, (int) $attendance->late_less_30, (int) $attendance->late_more_30, (int) $attendance->sick_days]);
    }

    public function test_exported_file_round_trips_through_the_import(): void
    {
        Storage::fake('local');
        Attendance::query()->create([
            'user_id' => $this->alpha->id, 'periode' => '2026-11', 'work_days' => 20,
            'late_less_30' => 1, 'sick_days' => 1, 'component_counts' => ['izin' => 2, 'mangkir' => 1, 'cuti_lainnya' => 3],
        ]);
        Attendance::query()->create([
            'user_id' => $this->bravo->id, 'periode' => '2026-09', 'work_days' => 21,
            'late_less_30' => 2, 'late_more_30' => 1, 'sick_days' => 3,
        ]);
        $before = $this->snapshot();

        Excel::store(new AttendanceExport, 'export.xlsx', 'local');
        Attendance::query()->forceDelete();

        $import = new AttendanceImport;
        Excel::import($import, 'export.xlsx', 'local');

        $this->assertSame(2, $import->getImportSummary()['importedCount']);
        $this->assertSame([], $import->getImportSummary()['warnings']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_panel_import_notifies_imported_rows_skip_reasons_and_warnings(): void
    {
        $disk = (string) (config('filament.default_filesystem_disk') ?: config('filesystems.default', 'local'));
        Storage::fake($disk);
        $this->storeSheet('imports/attendances/kehadiran.xlsx', [
            self::TEMPLATE_HEADINGS,
            ['EMP-ALPHA', 'ALPHA', '2026-11', 20, 1, 0, 1, 2, 1, 0, 0],
            ['EMP-BRAVO', 'BRAVO', '1900-01', 22, 0, 0, 0, 0, 0, 0, 0],
        ], $disk);
        $this->actingAs($this->admin);

        $list = Livewire::test(ListAttendances::class)
            ->call('processImport', ['file' => 'imports/attendances/kehadiran.xlsx']);

        $notification = collect(session('filament.claimed_notifications') ?? session('filament.notifications'))->last();
        $this->assertSame('Data Kehadiran berhasil diimport. 1 data ditambahkan, 1 data dilewati.', $notification['title'] ?? null);
        $this->assertStringContainsString('EMP-BRAVO / BRAVO (1900-01): Periode &quot;1900-01&quot; terbaca sebagai 1900-01', $notification['body']);
        $this->assertSame('persistent', $notification['duration']);
        $list->assertNotified('Data Kehadiran berhasil diimport. 1 data ditambahkan, 1 data dilewati.');

        $this->assertSame(['izin' => 2, 'mangkir' => 1], Attendance::query()->sole()->component_counts);
        $this->assertFalse(Storage::disk($disk)->exists('imports/attendances/kehadiran.xlsx'));

        $body = ListAttendances::importSummaryDetails([
            'warnings' => ['Kolom "Izin" tidak ada di file.'],
            'skippedDetails' => [['employee_id' => 'EMP-BRAVO', 'nama_lengkap' => '<b>BRAVO</b>', 'periode' => '1900-01', 'reason' => 'Periode di luar rentang.']],
        ]);
        $this->assertSame('&bull; Kolom &quot;Izin&quot; tidak ada di file.<br>&bull; EMP-BRAVO / &lt;b&gt;BRAVO&lt;/b&gt; (1900-01): Periode di luar rentang.', $body);
        $this->assertNull(ListAttendances::importSummaryDetails(['warnings' => [], 'skippedDetails' => []]));
    }

    // ---------------------------------------------------------------------
    // Regresi: pemetaan kolom import, komponen nonaktif, batas angka
    // ---------------------------------------------------------------------

    public function test_import_reads_unrecognised_headings_from_template_positions_and_never_imports_missing_work_days(): void
    {
        Storage::fake('local');

        // Judul Hari Kerja & komponen lama yang tidak dikenali dibaca dari posisi template (seperti import lama).
        $this->storeSheet('custom.xlsx', [
            ['ID karyawan', 'Nama Lengkap', 'Periode', 'Jml Hari Masuk', 'Terlambat ringan', 'Terlambat berat', 'Sakit'],
            ['EMP-ALPHA', 'ALPHA', '2026-09', 22, 3, 1, 2],
        ]);
        $import = new AttendanceImport;
        Excel::import($import, 'custom.xlsx', 'local');

        $attendance = Attendance::query()->sole();
        $this->assertSame([22, 3, 1, 2], [(int) $attendance->work_days, (int) $attendance->late_less_30, (int) $attendance->late_more_30, (int) $attendance->sick_days]);
        $this->assertSame([
            'Kolom "Jml Hari Masuk" (kolom ke-4) tidak dikenali dan dibaca sebagai Hari Kerja sesuai urutan template.',
            'Kolom "Terlambat ringan" (kolom ke-5) tidak dikenali dan dibaca sebagai Late Less 30 min sesuai urutan template.',
            'Kolom "Terlambat berat" (kolom ke-6) tidak dikenali dan dibaca sebagai Late More 30 min sesuai urutan template.',
        ], $import->getImportSummary()['warnings']);

        // Variasi judul umum dikenali langsung.
        Attendance::query()->forceDelete();
        $this->storeSheet('alias.xlsx', [
            ['ID karyawan', 'Nama Lengkap', 'Periode', 'Hari Kerja Efektif', 'Telat < 30', 'Telat > 30', 'Sakit/Izin'],
            ['EMP-ALPHA', 'ALPHA', '2026-09', 21, 1, 2, 3],
        ]);
        $alias = new AttendanceImport;
        Excel::import($alias, 'alias.xlsx', 'local');
        $attendance = Attendance::query()->sole();
        $this->assertSame([21, 1, 2, 3], [(int) $attendance->work_days, (int) $attendance->late_less_30, (int) $attendance->late_more_30, (int) $attendance->sick_days]);
        $this->assertSame([], $alias->getImportSummary()['warnings']);

        // Kolom Hari Kerja tidak bisa ditemukan (posisinya dipakai kolom lain): setiap baris dilewati, bukan diimport dengan 0.
        Attendance::query()->forceDelete();
        $this->storeSheet('shifted.xlsx', [
            ['ID karyawan', 'Nama Lengkap', 'Sakit', 'Periode'],
            ['EMP-ALPHA', 'ALPHA', 1, '2026-09'],
            ['EMP-BRAVO', 'BRAVO', 0, '2026-09'],
        ]);
        $shifted = new AttendanceImport;
        Excel::import($shifted, 'shifted.xlsx', 'local');
        $summary = $shifted->getImportSummary();
        $this->assertSame(0, $summary['importedCount']);
        $this->assertSame(2, $summary['skippedCount']);
        $this->assertSame('Kolom Hari Kerja tidak ditemukan di baris judul (gunakan judul "Hari Kerja" seperti template). Baris tidak diimport.', $summary['skippedDetails'][0]['reason']);
        $this->assertSame('2026-09', $summary['skippedDetails'][0]['periode']);

        // Baris template yang belum diisi diabaikan (dihitung terpisah, bukan gagal); baris yang komponennya terisi
        // tetapi Hari Kerja-nya kosong tetap dilewati dengan alasannya, tidak menjadi data bernilai 0.
        $this->storeSheet('template.xlsx', [
            self::TEMPLATE_HEADINGS,
            ['EMP-ALPHA', 'ALPHA', '2026-11', '', '', '', '', '', '', '', ''],
            ['EMP-BRAVO', 'BRAVO', '2026-11', 20, '', '', '', 1, '', '', ''],
            ['EMP-ALPHA', 'ALPHA', '2026-12', '', 1, '', '', '', '', '', ''],
        ]);
        $template = new AttendanceImport;
        Excel::import($template, 'template.xlsx', 'local');
        $summary = $template->getImportSummary();
        $this->assertSame(1, $summary['importedCount']);
        $this->assertSame(1, $summary['unfilledCount']);
        $this->assertSame(1, $summary['skippedCount']);
        $this->assertSame('Hari Kerja kosong. Isi jumlah hari kerja (1 sampai 31).', $summary['skippedDetails'][0]['reason']);
        $this->assertSame([], $summary['warnings']);
        $this->assertSame(['izin' => 1], Attendance::query()->sole()->component_counts);
    }

    public function test_import_never_guesses_the_employee_column_and_refuses_names_shared_by_several_employees(): void
    {
        Storage::fake('local');
        $area = $this->fixtureArea('COO');
        // Nama sama dengan alpha, dan karyawan dengan ID '1' (sama dengan isi kolom nomor urut).
        $twin = $this->fixtureUser('alpha_twin', 'STAFF', $area, null, ['employee_id' => 'EMP-TWIN', 'nama_lengkap' => 'ALPHA']);
        $one = $this->fixtureUser('one', 'STAFF', $area, null, ['employee_id' => '1']);

        // 'NIK' dikenali sebagai ID karyawan: baris masuk ke alpha, bukan karyawan lain bernama sama.
        $this->storeSheet('nik.xlsx', [
            ['NIK', 'Nama Lengkap', 'Periode', 'HK', 'Late Less 30 min', 'Late More 30 min', 'Sakit'],
            ['EMP-ALPHA', 'ALPHA', '2026-11', 20, 1, 2, 3],
        ]);
        $nik = new AttendanceImport;
        Excel::import($nik, 'nik.xlsx', 'local');
        $attendance = Attendance::query()->sole();
        $this->assertSame($this->alpha->id, (int) $attendance->user_id);
        $this->assertSame([20, 1, 2, 3], [(int) $attendance->work_days, (int) $attendance->late_less_30, (int) $attendance->late_more_30, (int) $attendance->sick_days]);

        // Kolom nomor urut di posisi ID tidak ditebak sebagai ID karyawan; nama yang dipakai dua karyawan ditolak.
        Attendance::query()->forceDelete();
        $this->storeSheet('numbered.xlsx', [
            ['No', 'Nama Lengkap', 'Periode', 'Hari Kerja', 'Late Less 30 min', 'Late More 30 min', 'Sakit'],
            [1, 'BRAVO', '2026-10', 20, 0, 0, 0],
            [2, 'ALPHA', '2026-10', 20, 0, 0, 0],
        ]);
        $numbered = new AttendanceImport;
        Excel::import($numbered, 'numbered.xlsx', 'local');
        $summary = $numbered->getImportSummary();
        $this->assertSame(1, $summary['importedCount']);
        $this->assertSame($this->bravo->id, (int) Attendance::query()->sole()->user_id);
        $this->assertFalse(Attendance::query()->whereIn('user_id', [$one->id, $twin->id, $this->alpha->id])->exists());
        $this->assertSame('Nama "ALPHA" dipakai lebih dari satu karyawan. Isi kolom ID karyawan agar data tidak masuk ke karyawan lain.', $summary['skippedDetails'][0]['reason']);
        $this->assertSame([
            'Kolom ID karyawan tidak ditemukan di baris judul; karyawan dicari dari Nama Lengkap.',
            'Kolom tidak dikenal diabaikan: No.',
        ], $summary['warnings']);

        // File tanpa judul juga menolak nama ganda tanpa ID.
        $headerless = new AttendanceImport;
        $this->assertNull($headerless->model(['', 'ALPHA', '2026-12', 20, 0, 0, 0]));
        $this->assertStringContainsString('dipakai lebih dari satu karyawan', $headerless->getImportSummary()['skippedDetails'][0]['reason']);
        $this->assertSame($twin->id, $headerless->model(['EMP-TWIN', 'ALPHA', '2026-12', 20, 0, 0, 0])?->user_id);

        // ID & nama sama-sama tidak dikenali judulnya: dibaca dari posisi template, dengan peringatan.
        Attendance::query()->forceDelete();
        $this->storeSheet('unknown.xlsx', [
            ['Kode', 'Pegawai', 'Periode', 'Hari Kerja'],
            ['EMP-BRAVO', 'BRAVO', '2026-09', 21],
        ]);
        $unknown = new AttendanceImport;
        Excel::import($unknown, 'unknown.xlsx', 'local');
        $this->assertSame($this->bravo->id, (int) Attendance::query()->sole()->user_id);
        $this->assertContains('Kolom "Kode" (kolom ke-1) tidak dikenali dan dibaca sebagai ID karyawan sesuai urutan template.', $unknown->getImportSummary()['warnings']);
    }

    public function test_component_headings_carry_the_code_so_renamed_and_same_named_components_still_import(): void
    {
        Storage::fake('local');
        Attendance::query()->create(['user_id' => $this->alpha->id, 'periode' => '2026-09', 'work_days' => 20, 'late_less_30' => 3]);
        Attendance::query()->create(['user_id' => $this->bravo->id, 'periode' => '2026-11', 'work_days' => 20, 'component_counts' => ['mangkir' => 2]]);
        $before = $this->snapshot();
        Excel::store(new AttendanceExport, 'export.xlsx', 'local');

        // Nama komponen boleh diubah setelah file diunduh: judul membawa kode tetap.
        AttendanceComponent::query()->where('code', 'late_less_30')->firstOrFail()->update(['name' => 'Terlambat 5-30 menit']);
        AttendanceComponent::query()->where('code', 'mangkir')->firstOrFail()->update(['name' => 'Alpa']);
        Attendance::query()->forceDelete();

        $import = new AttendanceImport;
        Excel::import($import, 'export.xlsx', 'local');
        $this->assertSame(2, $import->getImportSummary()['importedCount']);
        $this->assertSame([], $import->getImportSummary()['warnings']);
        $this->assertSame($before, $this->snapshot());

        // Dua komponen bernama sama: kolom template dibedakan dari kodenya; judul nama saja ambigu dan diabaikan.
        AttendanceComponent::query()->create(['code' => 'cuti_khusus_a', 'name' => 'Cuti khusus', 'unit' => 'hari', 'sort_order' => 10]);
        AttendanceComponent::query()->create(['code' => 'cuti_khusus_b', 'name' => 'Cuti khusus', 'unit' => 'hari', 'sort_order' => 11]);
        // Template & import hanya menerima komponen yang tercantum di aturan periodenya.
        $this->listComponentsInEveryRule(['cuti_khusus_a', 'cuti_khusus_b']);
        $headings = (new AttendanceImportTemplateExport)->headings();
        $this->assertSame(['Cuti khusus [cuti_khusus_a]', 'Cuti khusus [cuti_khusus_b]'], array_slice($headings, -2));
        Attendance::query()->forceDelete();

        $this->storeSheet('same-name.xlsx', [
            $headings,
            ['EMP-ALPHA', 'ALPHA', '2026-12', 20, 0, 0, 0, 0, 0, 0, 0, 2, 0],
        ]);
        Excel::import(new AttendanceImport, 'same-name.xlsx', 'local');
        $this->assertSame(['cuti_khusus_a' => 2], Attendance::query()->sole()->component_counts);

        Attendance::query()->forceDelete();
        $this->storeSheet('plain-name.xlsx', [
            ['ID karyawan', 'Nama Lengkap', 'Periode', 'Hari Kerja', 'Cuti khusus'],
            ['EMP-ALPHA', 'ALPHA', '2026-12', 20, 2],
        ]);
        $plain = new AttendanceImport;
        Excel::import($plain, 'plain-name.xlsx', 'local');
        $this->assertNull(Attendance::query()->sole()->component_counts);
        $this->assertContains('Kolom "Cuti khusus" diabaikan karena namanya dipakai lebih dari satu komponen kehadiran. Gunakan judul dengan kode komponen seperti di template, mis. "Cuti khusus [kode]".', $plain->getImportSummary()['warnings']);
    }

    public function test_inactive_components_still_used_by_a_rule_stay_in_form_table_template_and_export(): void
    {
        $attendance = Attendance::query()->create([
            'user_id' => $this->alpha->id, 'periode' => '2026-12', 'work_days' => 20,
            'component_counts' => ['mangkir' => 2, 'arsip' => 3],
        ]);
        // Mangkir masih dinilai aturan November 2026 (5 poin/hari); arsip tidak dinilai aturan mana pun.
        AttendanceComponent::query()->create(['code' => 'arsip', 'name' => 'Arsip', 'unit' => 'hari', 'sort_order' => 9, 'is_active' => false]);
        // Model menolak menonaktifkan komponen yang masih dinilai; simulasikan perubahan di luar Eloquent.
        DB::table('attendance_components')->where('code', 'mangkir')->update(['is_active' => false]);
        ScoringRuleService::clearCache();
        $this->actingAs($this->admin);
        $this->assertEqualsWithDelta(10.0, AttendanceResource::attendanceScore($attendance)->penaltyPoints, self::DELTA);

        Livewire::test(CreateAttendance::class)
            ->assertFormFieldExists('component_counts.mangkir', fn (TextInput $field): bool => $field->getLabel() === 'Mangkir (tanpa keterangan) (hari) - nonaktif')
            ->assertFormFieldDoesNotExist('component_counts.arsip')
            ->fillForm(['user_id' => $this->bravo->id, 'periode' => '2027-01', 'work_days' => 20, 'component_counts.mangkir' => 1])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(['mangkir' => 1], Attendance::query()->where('user_id', $this->bravo->id)->sole()->component_counts);

        Livewire::test(EditAttendance::class, ['record' => $attendance->id])
            ->assertFormSet(['component_counts.mangkir' => 2])
            ->fillForm(['component_counts.mangkir' => 3])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['arsip' => 3, 'mangkir' => 3], $attendance->refresh()->component_counts);

        Livewire::test(ListAttendances::class)
            ->loadTable()
            ->assertTableColumnExists('component_mangkir', fn ($column): bool => $column->getLabel() === 'Mangkir (tanpa keterangan) (nonaktif)')
            ->assertTableColumnStateSet('component_mangkir', 3, $attendance);

        $this->assertContains('Mangkir (tanpa keterangan) [mangkir]', (new AttendanceImportTemplateExport)->headings());
        $this->assertNotContains('Arsip [arsip]', (new AttendanceImportTemplateExport)->headings());

        // Export memuat semua komponen yang punya kolom, termasuk yang nonaktif: tidak ada hitungan yang hilang.
        $export = new AttendanceExport;
        $headings = $export->headings();
        $row = $export->map($export->collection()->firstWhere('employee_id', 'EMP-ALPHA'));
        $this->assertSame(3, $row[array_search('Mangkir (tanpa keterangan) [mangkir]', $headings, true)]);
        $this->assertSame(3, $row[array_search('Arsip [arsip]', $headings, true)]);

        // Import ulang export: baris yang menyimpan hitungan arsip (tidak tercantum di aturan periode 2026-12)
        // dilewati dengan alasannya, bukan disimpan diam-diam tanpa memengaruhi skor; baris lain kembali utuh.
        Storage::fake('local');
        $before = $this->snapshot();
        Excel::store(new AttendanceExport, 'export.xlsx', 'local');
        Attendance::query()->forceDelete();
        $import = new AttendanceImport;
        Excel::import($import, 'export.xlsx', 'local');
        $this->assertSame(
            ['Aturan penilaian Desember 2026 tidak memakai Arsip, tetapi kolomnya diisi. Kosongkan kolom Arsip. Bila periodenya yang keliru, perbaiki periodenya.'],
            array_column($import->getImportSummary()['skippedDetails'], 'reason'),
        );
        $this->assertSame(array_values(array_filter($before, fn (array $row): bool => $row['user_id'] !== $this->alpha->id)), $this->snapshot());
    }

    public function test_import_skips_a_row_that_fills_a_component_its_rule_does_not_use(): void
    {
        Storage::fake('local');
        $this->storeSheet('backfill.xlsx', [
            self::TEMPLATE_HEADINGS,
            ['EMP-ALPHA', 'ALPHA', '2026-10', 20, 0, 0, 1, 2, 0, 0, 0],
            ['EMP-BRAVO', 'BRAVO', '2026-11', 20, 0, 0, 1, 2, 0, 0, 0],
        ]);
        $import = new AttendanceImport;
        Excel::import($import, 'backfill.xlsx', 'local');

        // Dulu baris Oktober 2026 disimpan dengan peringatan (izin tidak memengaruhi skor); sekarang dilewati
        // dengan alasannya agar izin diisi di kolom Sakit/Izin.
        $summary = $import->getImportSummary();
        $this->assertSame(1, $summary['importedCount']);
        $this->assertSame([
            'Aturan penilaian Oktober 2026 belum memakai Izin, tetapi kolomnya diisi. Untuk periode ini izin dicatat di kolom Sakit/Izin: jumlahkan angka Izin ke kolom Sakit/Izin, lalu kosongkan kolom Izin. Bila periodenya yang keliru, perbaiki periodenya.',
        ], array_column($summary['skippedDetails'], 'reason'));
        $this->assertSame([], $summary['warnings']);
        $this->assertSame($this->bravo->id, (int) Attendance::query()->sole()->user_id);
    }

    public function test_api_rejects_counts_and_work_days_above_the_limit(): void
    {
        Sanctum::actingAs($this->admin);
        $payload = ['user_id' => $this->alpha->id, 'periode' => '2026-11', 'work_days' => 20];

        $this->postJson('/api/v1/attendances', $payload + ['late_less_30' => 3000000000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['late_less_30']);
        $this->postJson('/api/v1/attendances', $payload + ['components' => ['izin' => 1000]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['components.izin']);
        $this->postJson('/api/v1/attendances', ['work_days' => 40] + $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['work_days']);
        $this->assertSame(0, Attendance::query()->count());

        $attendance = Attendance::query()->create($payload);
        $this->putJson('/api/v1/attendances/'.$attendance->id, ['sick_days' => 1000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sick_days']);

        // Import: angka di atas batas melewati barisnya saja; baris berikutnya tetap diimport.
        Storage::fake('local');
        $this->storeSheet('overflow.xlsx', [
            self::TEMPLATE_HEADINGS,
            ['EMP-ALPHA', 'ALPHA', '2026-12', 3201234567, 0, 0, 0, 0, 0, 0, 0],
            ['EMP-BRAVO', 'BRAVO', '2026-12', 20, 0, 0, 0, 3201234567, 0, 0, 0],
            ['EMP-BRAVO', 'BRAVO', '2027-01', 20, 1, 0, 0, 0, 0, 0, 0],
        ]);
        $import = new AttendanceImport;
        Excel::import($import, 'overflow.xlsx', 'local');
        $summary = $import->getImportSummary();
        $this->assertSame(1, $summary['importedCount']);
        $this->assertSame(['Hari Kerja harus bilangan bulat 1 sampai 31.', 'Nilai Izin harus bilangan bulat 0 sampai 999.'], array_column($summary['skippedDetails'], 'reason'));
        $this->assertSame(1, (int) Attendance::query()->where('periode', '2027-01')->sole()->late_less_30);
    }

    public function test_load_test_seeder_fills_component_counts(): void
    {
        $this->seed(LoadTestSeeder::class);

        $attendances = Attendance::query()->where('periode', '2027-01')->get();
        $this->assertGreaterThan(80, $attendances->count());
        $withCounts = $attendances->filter(fn (Attendance $attendance): bool => $attendance->component_counts !== null);
        $this->assertNotEmpty($withCounts);
        $this->assertLessThan($attendances->count(), $withCounts->count());
        $this->assertTrue($withCounts->contains(fn (Attendance $attendance): bool => $attendance->countFor('izin') === 1));
        $this->assertTrue($withCounts->contains(fn (Attendance $attendance): bool => $attendance->countFor('mangkir') === 1));
        $this->assertEmpty(array_diff(
            $withCounts->flatMap(fn (Attendance $attendance): array => array_keys($attendance->component_counts))->unique()->all(),
            ['izin', 'mangkir', 'cuti_tahunan', 'cuti_lainnya'],
        ));
    }

    /**
     * Cantumkan komponen di setiap versi aturan tanpa penalti (skor tidak berubah), agar boleh diisi untuk periode
     * mana pun. Versi yang sudah berlaku dikunci model, jadi diubah di luar Eloquent seperti data lama.
     *
     * @param  list<string>  $codes
     */
    private function listComponentsInEveryRule(array $codes): void
    {
        foreach (DB::table('scoring_rules')->get(['id', 'attendance_rules']) as $rule) {
            $entries = json_decode((string) $rule->attendance_rules, true);

            foreach ($codes as $code) {
                $entries[] = ['component' => $code, 'reduces_base' => false, 'penalty_points' => 0];
            }

            DB::table('scoring_rules')->where('id', $rule->id)->update(['attendance_rules' => json_encode($entries)]);
        }

        ScoringRuleService::clearCache();
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function storeSheet(string $path, array $rows, string $disk = 'local'): void
    {
        Excel::store(new class($rows) implements FromArray
        {
            /** @param  list<list<mixed>>  $rows */
            public function __construct(private readonly array $rows) {}

            public function array(): array
            {
                return $this->rows;
            }
        }, $path, $disk);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshot(): array
    {
        return Attendance::query()
            ->orderBy('periode')
            ->get()
            ->map(fn (Attendance $attendance): array => [
                'user_id' => (int) $attendance->user_id,
                'periode' => $attendance->periode,
                'work_days' => (int) $attendance->work_days,
                'counts' => $attendance->componentCounts(),
            ])
            ->all();
    }
}
