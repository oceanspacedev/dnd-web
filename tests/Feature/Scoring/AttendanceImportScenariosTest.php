<?php

namespace Tests\Feature\Scoring;

use App\Exports\AttendanceExport;
use App\Exports\AttendanceImportTemplateExport;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Imports\AttendanceImport;
use App\Models\Attendance;
use App\Models\AttendanceComponent;
use App\Models\User;
use App\Policies\AttendancePolicy;
use App\Services\ScoringRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Skenario file Excel HR yang nyata untuk import kehadiran (dan kesesuaian form/API/export dengannya):
 * komponen berkode sama dengan judul template, rumus, kolom bergeser, beberapa sheet, label di atas tabel,
 * karyawan bernama sama di luar cakupan, baris ganda, dan nilai 0 di export.
 */
class AttendanceImportScenariosTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private const DELTA = 1e-9;

    private const READ_ONLY_REASON = 'Kehadiran akun ADMIN (termasuk akun Anda sendiri) hanya dapat dicatat oleh super admin.';

    private const NOT_FOUND_REASON = 'Karyawan tidak ditemukan. Periksa ID karyawan dan Nama Lengkap.';

    private User $admin;

    private User $alpha;

    private User $bravo;

    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_component_code_equal_to_a_template_heading_does_not_take_over_the_template_column(): void
    {
        Storage::fake('local');
        AttendanceComponent::query()->create(['code' => 'sakit', 'name' => 'Sakit dengan surat dokter', 'unit' => 'hari', 'sort_order' => 8]);
        // Template & import hanya menerima komponen yang tercantum di aturan periodenya.
        $this->listComponentsInEveryRule(['sakit']);
        $headings = (new AttendanceImportTemplateExport)->headings();
        $this->assertSame('Sakit', $headings[6]);
        $this->assertSame('Sakit dengan surat dokter [sakit]', end($headings));

        $this->storeWorkbook('template.xlsx', ['Worksheet' => [
            $headings,
            $this->templateRow($headings, ['ID karyawan' => 'EMP-ALPHA', 'Nama Lengkap' => 'ALPHA', 'Periode' => '2026-11', 'Hari Kerja' => 20, 'Sakit' => 2, 'Sakit dengan surat dokter [sakit]' => 1]),
        ]]);
        $import = new AttendanceImport;
        Excel::import($import, 'template.xlsx', 'local');

        $attendance = Attendance::query()->sole();
        $this->assertSame(2, (int) $attendance->sick_days);
        $this->assertSame(['sakit' => 1], $attendance->component_counts);
        // Aturan November 2026: sakit mengurangi hari kerja tanpa penalti => (20 - 2) / 20 x 15.
        $this->assertEqualsWithDelta(13.5, AttendanceResource::attendanceScore($attendance)->score, self::DELTA);
        foreach ($import->getImportSummary()['warnings'] as $warning) {
            $this->assertStringNotContainsString('tidak ada di file', $warning);
            $this->assertStringNotContainsString('diabaikan', $warning);
        }

        // Export lalu import ulang tidak menukar Sakit dengan komponen berkode "sakit".
        Attendance::query()->forceDelete();
        Attendance::query()->create(['user_id' => $this->bravo->id, 'periode' => '2026-11', 'work_days' => 20, 'sick_days' => 3, 'component_counts' => ['sakit' => 1]]);
        $before = $this->snapshot();
        Excel::store(new AttendanceExport, 'export.xlsx', 'local');
        Attendance::query()->forceDelete();
        Excel::import(new AttendanceImport, 'export.xlsx', 'local');
        $this->assertSame($before, $this->snapshot());

        // Kode yang sama dengan judul 'Late More 30 min' juga tidak merebut kolom itu.
        Attendance::query()->forceDelete();
        AttendanceComponent::query()->create(['code' => 'late_more_30_min', 'name' => 'Terlambat manual', 'unit' => 'kejadian', 'sort_order' => 9]);
        $this->listComponentsInEveryRule(['late_more_30_min']);
        $headings = (new AttendanceImportTemplateExport)->headings();
        $this->storeWorkbook('late.xlsx', ['Worksheet' => [
            $headings,
            $this->templateRow($headings, ['ID karyawan' => 'EMP-ALPHA', 'Nama Lengkap' => 'ALPHA', 'Periode' => '2026-12', 'Hari Kerja' => 20, 'Late More 30 min' => 2]),
        ]]);
        Excel::import(new AttendanceImport, 'late.xlsx', 'local');
        $attendance = Attendance::query()->sole();
        $this->assertSame(2, (int) $attendance->late_more_30);
        $this->assertNull($attendance->component_counts);
    }

    public function test_edit_form_accepts_the_same_count_limit_as_import_and_api(): void
    {
        // Hitungan > 99 yang diterima import/API (batas 999) tetap bisa disimpan ulang dari form.
        $attendance = Attendance::query()->create([
            'user_id' => $this->alpha->id, 'periode' => '2026-12', 'work_days' => 22,
            'late_less_30' => 150, 'component_counts' => ['izin' => 120],
        ]);
        $this->actingAs($this->admin);

        Livewire::test(EditAttendance::class, ['record' => $attendance->id])
            ->fillForm(['work_days' => 21])
            ->call('save')
            ->assertHasNoFormErrors();
        $attendance->refresh();
        $this->assertSame([21, 150], [(int) $attendance->work_days, (int) $attendance->late_less_30]);
        $this->assertSame(['izin' => 120], $attendance->component_counts);

        // Di atas batas ditolak dengan pesan berbahasa Indonesia.
        $page = Livewire::test(EditAttendance::class, ['record' => $attendance->id])
            ->fillForm(['component_counts.izin' => 1000])
            ->call('save')
            ->assertHasFormErrors(['component_counts.izin']);
        $this->assertSame(['Nilai maksimal 999.'], $page->errors()->get('data.component_counts.izin'));
        $this->assertSame(['izin' => 120], $attendance->refresh()->component_counts);
    }

    public function test_component_name_ending_in_another_components_code_imports_into_itself(): void
    {
        Storage::fake('local');
        AttendanceComponent::query()->create(['code' => 'dl', 'name' => 'Dinas Luar', 'unit' => 'hari', 'sort_order' => 8]);
        AttendanceComponent::query()->create(['code' => 'tugas', 'name' => 'Tugas [dl]', 'unit' => 'hari', 'sort_order' => 9]);
        // Template & import hanya menerima komponen yang tercantum di aturan periodenya.
        $this->listComponentsInEveryRule(['dl', 'tugas']);

        $this->storeWorkbook('plain.xlsx', ['Worksheet' => [
            ['ID karyawan', 'Nama Lengkap', 'Periode', 'Hari Kerja', 'Tugas [dl]'],
            ['EMP-ALPHA', 'ALPHA', '2026-12', 20, 3],
        ]]);
        Excel::import(new AttendanceImport, 'plain.xlsx', 'local');
        $this->assertSame(['tugas' => 3], Attendance::query()->sole()->component_counts);

        Attendance::query()->forceDelete();
        $this->storeWorkbook('both.xlsx', ['Worksheet' => [
            ['ID karyawan', 'Nama Lengkap', 'Periode', 'Hari Kerja', 'Dinas Luar', 'Tugas [dl]'],
            ['EMP-ALPHA', 'ALPHA', '2026-12', 20, 2, 3],
        ]]);
        $import = new AttendanceImport;
        Excel::import($import, 'both.xlsx', 'local');
        $this->assertSame(['dl' => 2, 'tugas' => 3], Attendance::query()->sole()->component_counts);
        foreach ($import->getImportSummary()['warnings'] as $warning) {
            $this->assertStringNotContainsString('sudah dibaca', $warning);
        }

        // Judul template kedua komponen tetap terbaca lewat kodenya.
        Attendance::query()->forceDelete();
        $headings = (new AttendanceImportTemplateExport)->headings();
        $this->assertSame(['Dinas Luar [dl]', 'Tugas [dl] [tugas]'], array_slice($headings, -2));
        $this->storeWorkbook('template.xlsx', ['Worksheet' => [
            $headings,
            $this->templateRow($headings, ['ID karyawan' => 'EMP-ALPHA', 'Nama Lengkap' => 'ALPHA', 'Periode' => '2026-12', 'Hari Kerja' => 20, 'Dinas Luar [dl]' => 4, 'Tugas [dl] [tugas]' => 5]),
        ]]);
        Excel::import(new AttendanceImport, 'template.xlsx', 'local');
        $this->assertSame(['dl' => 4, 'tugas' => 5], Attendance::query()->sole()->component_counts);
    }

    public function test_employee_id_never_falls_back_to_a_same_named_employee(): void
    {
        $coo = $this->fixtureArea('COO');
        $uls = $this->fixtureArea('ULS');
        $gmd = $this->fixtureArea('GMD');
        $ojan = $this->fixtureScopedAdmin('ojan', $coo, [$uls]);
        $inScope = $this->fixtureUser('andi_in', 'STAFF', $uls, null, ['employee_id' => 'EMP-IN', 'nama_lengkap' => 'ANDI']);
        $outOfScope = $this->fixtureUser('andi_out', 'STAFF', $coo, null, ['employee_id' => 'EMP-OUT', 'nama_lengkap' => 'ANDI']);
        $this->resetAccessMemos();

        // Admin per badan usaha: ID milik ANDI di luar cakupan tidak jatuh ke ANDI lain yang ada di cakupannya.
        $scoped = new AttendanceImport(AttendancePolicy::writableUserIds($ojan), AttendancePolicy::visibleUserIds($ojan));
        $this->assertNull($scoped->model(['EMP-OUT', 'ANDI', '2026-11', 20, 7, 0, 3]));
        // Tanpa ID, nama yang dipakai karyawan lain di perusahaan juga ditolak.
        $this->assertNull($scoped->model(['', 'ANDI', '2026-11', 20, 7, 0, 3]));
        $this->assertSame($inScope->id, $scoped->model(['EMP-IN', 'ANDI', '2026-11', 20, 7, 0, 3])?->user_id);
        // ID di luar cakupan dilaporkan sama dengan ID yang tidak ada (keberadaannya tidak dibuka).
        $this->assertSame([
            self::NOT_FOUND_REASON,
            'Nama "ANDI" dipakai lebih dari satu karyawan. Isi kolom ID karyawan agar data tidak masuk ke karyawan lain.',
        ], array_column($scoped->getImportSummary()['skippedDetails'], 'reason'));

        // Super admin: ID milik karyawan yang sudah dihapus tidak dipindah ke karyawan aktif bernama sama.
        $old = $this->fixtureUser('cici_old', 'STAFF', $coo, null, ['employee_id' => 'EMP-OLD', 'nama_lengkap' => 'CICI']);
        $new = $this->fixtureUser('cici_new', 'STAFF', $coo, null, ['employee_id' => 'EMP-NEW', 'nama_lengkap' => 'CICI']);
        $old->delete();
        $global = new AttendanceImport;
        $this->assertNull($global->model(['EMP-OLD', 'CICI', '2026-11', 20, 7, 0, 3]));
        $this->assertSame('ID karyawan "EMP-OLD" milik karyawan yang sudah dihapus.', $global->getImportSummary()['skippedDetails'][0]['reason']);
        // Nama hanya dipakai bila ID kosong / tidak dikenal (karyawan yang dihapus tidak dihitung).
        $this->assertSame($new->id, $global->model(['', 'CICI', '2026-11', 20, 0, 0, 0])?->user_id);
        $this->assertSame($this->bravo->id, $global->model(['EMP-TYPO', 'BRAVO', '2026-11', 20, 0, 0, 0])?->user_id);

        // Atasan: ID staf tim lain tidak jatuh ke bawahannya sendiri yang bernama sama.
        $manager = $this->fixtureUser('gmd_manager', 'MANAGER', $gmd);
        $managed = $this->fixtureUser('gmd_staff', 'STAFF', $gmd, $manager, ['employee_id' => 'EMP-GMD', 'nama_lengkap' => 'ANDI WIJAYA']);
        $leader = $this->fixtureUser('gmd_leader', 'TEAM LEADER', $gmd);
        $this->fixtureUser('tl_staff', 'STAFF', $gmd, $leader, ['employee_id' => 'EMP-TLS', 'nama_lengkap' => 'ANDI WIJAYA']);
        $this->resetAccessMemos();
        $supervisor = new AttendanceImport(AttendancePolicy::writableUserIds($manager), AttendancePolicy::visibleUserIds($manager));
        $this->assertNull($supervisor->model(['EMP-TLS', 'ANDI WIJAYA', '2026-12', 19, 0, 0, 0]));
        $this->assertSame(self::NOT_FOUND_REASON, $supervisor->getImportSummary()['skippedDetails'][0]['reason']);
        $this->assertSame($managed->id, $supervisor->model(['EMP-GMD', 'ANDI WIJAYA', '2026-12', 19, 0, 0, 0])?->user_id);

        $this->assertSame(0, Attendance::query()->whereIn('user_id', [$inScope->id, $outOfScope->id, $new->id, $managed->id])->count());
    }

    public function test_limited_import_does_not_reveal_employees_outside_the_users_scope(): void
    {
        $coo = $this->fixtureArea('COO');
        $uls = $this->fixtureArea('ULS');
        $gmd = $this->fixtureArea('GMD');
        $ojan = $this->fixtureScopedAdmin('ojan', $coo, [$uls]);
        $inScope = $this->fixtureUser('uls_staff', 'STAFF', $uls, null, ['employee_id' => 'EMP-ULS', 'nama_lengkap' => 'ULS STAFF']);
        $ulsDeleted = $this->fixtureUser('uls_old', 'STAFF', $uls, null, ['employee_id' => 'EMP-ULS-OLD', 'nama_lengkap' => 'ULS OLD']);
        $ulsDeleted->delete();
        $this->fixtureUser('gmd_a', 'STAFF', $gmd, null, ['employee_id' => 'EMP-GMD-A', 'nama_lengkap' => 'SECRET PERSON']);
        $this->fixtureUser('gmd_twin_1', 'STAFF', $gmd, null, ['employee_id' => 'EMP-GMD-T1', 'nama_lengkap' => 'TWIN NAME']);
        $this->fixtureUser('gmd_twin_2', 'STAFF', $gmd, null, ['employee_id' => 'EMP-GMD-T2', 'nama_lengkap' => 'TWIN NAME']);
        $this->fixtureUser('gmd_del', 'STAFF', $gmd, null, ['employee_id' => 'EMP-GMD-DEL', 'nama_lengkap' => 'GONE PERSON'])->delete();
        $this->resetAccessMemos();

        $probes = [
            ['EMP-GMD-A', 'X'],         // ID karyawan badan usaha lain
            ['EMP-NOPE', 'X'],          // ID yang tidak ada
            ['', 'SECRET PERSON'],      // nama karyawan badan usaha lain
            ['', 'NOBODY HERE'],        // nama yang tidak ada
            ['', 'TWIN NAME'],          // nama yang dipakai dua karyawan badan usaha lain
            ['EMP-GMD-DEL', 'X'],       // ID karyawan badan usaha lain yang sudah dihapus
            ['EMP-GMD-A', 'ULS STAFF'], // ID badan usaha lain + nama karyawan dalam cakupan
            ['EMP-NOPE', 'ULS STAFF'],  // ID yang tidak ada + nama karyawan dalam cakupan
        ];

        $scoped = new AttendanceImport(AttendancePolicy::writableUserIds($ojan), AttendancePolicy::visibleUserIds($ojan));
        foreach ($probes as [$employeeId, $name]) {
            $this->assertNull($scoped->model([$employeeId, $name, '2026-11', 20, 0, 0, 0]));
        }

        $this->assertSame(
            array_fill(0, count($probes), self::NOT_FOUND_REASON),
            array_column($scoped->getImportSummary()['skippedDetails'], 'reason'),
        );

        // Baris template kosong tentang karyawan badan usaha lain diabaikan seperti baris tentang karyawan yang tidak ada.
        $blank = new AttendanceImport(AttendancePolicy::writableUserIds($ojan), AttendancePolicy::visibleUserIds($ojan));
        $this->assertNull($blank->model(['EMP-GMD-A', 'SECRET PERSON', '2026-11', '', '', '', '']));
        $this->assertNull($blank->model(['EMP-NOPE', 'NOBODY HERE', '2026-11', '', '', '', '']));
        $this->assertSame(0, $blank->getImportSummary()['unfilledCount']);
        $this->assertNull($blank->model(['EMP-ULS', 'ULS STAFF', '2026-11', '', '', '', '']));
        $this->assertSame(1, $blank->getImportSummary()['unfilledCount']);

        // Karyawan dalam cakupan tetap dikenali (termasuk yang sudah dihapus).
        $this->assertSame($inScope->id, $scoped->model(['EMP-ULS', 'ULS STAFF', '2026-11', 20, 0, 0, 0])?->user_id);
        $this->assertSame($inScope->id, $scoped->model(['', 'ULS STAFF', '2026-12', 20, 0, 0, 0])?->user_id);
        $this->assertNull($scoped->model(['EMP-ULS-OLD', 'ULS OLD', '2026-11', 20, 0, 0, 0]));
        $this->assertSame(
            'ID karyawan "EMP-ULS-OLD" milik karyawan yang sudah dihapus.',
            collect($scoped->getImportSummary()['skippedDetails'])->last()['reason'],
        );

        // Super admin (seluruh karyawan) tetap mendapat alasan rinci.
        $global = new AttendanceImport;
        $this->assertNull($global->model(['', 'TWIN NAME', '2026-11', 20, 0, 0, 0]));
        $this->assertNull($global->model(['EMP-GMD-DEL', 'X', '2026-11', 20, 0, 0, 0]));
        $this->assertSame([
            'Nama "TWIN NAME" dipakai lebih dari satu karyawan. Isi kolom ID karyawan agar data tidak masuk ke karyawan lain.',
            'ID karyawan "EMP-GMD-DEL" milik karyawan yang sudah dihapus.',
        ], array_column($global->getImportSummary()['skippedDetails'], 'reason'));
    }

    public function test_same_name_employees_without_employee_id_are_told_to_complete_the_id_in_master_data(): void
    {
        $area = $this->fixtureArea('COO');
        $first = $this->fixtureUser('dupe_1', 'STAFF', $area, null, ['nama_lengkap' => 'DUPE']);
        $second = $this->fixtureUser('dupe_2', 'STAFF', $area, null, ['nama_lengkap' => 'DUPE']);
        $noIdReason = 'Nama "DUPE" dipakai lebih dari satu karyawan, dan ID karyawan untuk nama ini belum diisi di data karyawan. '
            .'Lengkapi ID karyawan di data karyawan (menu Karyawan / Tim Saya) lalu isi kolom ID karyawan di file, atau isi kehadirannya lewat form Kehadiran.';

        // Template mengirim kedua baris dengan ID kosong; ID apa pun yang diisi di file belum dikenal.
        $this->assertSame([[null, 'DUPE'], [null, 'DUPE']], (new AttendanceImportTemplateExport)->collection()
            ->filter(fn ($row): bool => $row['nama_lengkap'] === 'DUPE')
            ->map(fn ($row): array => [$row['employee_id'], $row['nama_lengkap']])->values()->all());
        $import = new AttendanceImport;
        $this->assertNull($import->model(['', 'DUPE', '2026-11', 20, 0, 0, 0]));
        $this->assertNull($import->model(['EMP-BARU', 'DUPE', '2026-11', 20, 0, 0, 0]));
        $this->assertSame([$noIdReason, $noIdReason], array_column($import->getImportSummary()['skippedDetails'], 'reason'));

        // Setelah ID salah satu dilengkapi di data karyawan, ID itu memilih karyawannya; baris tanpa ID tetap ditolak
        // dan alasannya menyebut karyawan yang belum memiliki ID.
        $first->forceFill(['employee_id' => 'EMP-DUPE-1'])->save();
        $completed = new AttendanceImport;
        $this->assertSame($first->id, $completed->model(['EMP-DUPE-1', 'DUPE', '2026-11', 20, 0, 0, 0])?->user_id);
        $this->assertNull($completed->model(['', 'DUPE', '2026-11', 20, 0, 0, 0]));
        $this->assertSame(
            'Nama "DUPE" dipakai lebih dari satu karyawan. Isi kolom ID karyawan agar data tidak masuk ke karyawan lain. '
            .'Karyawan bernama sama yang belum memiliki ID karyawan perlu dilengkapi dulu ID-nya di data karyawan.',
            $completed->getImportSummary()['skippedDetails'][0]['reason'],
        );
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_formula_cells_are_read_from_their_calculated_values(): void
    {
        Storage::fake('local');
        $headings = (new AttendanceImportTemplateExport)->headings();
        $this->storeWorkbook('formulas.xlsx', ['Worksheet' => [
            $headings,
            $this->templateRow($headings, [
                'ID karyawan' => 'EMP-ALPHA',
                'Nama Lengkap' => 'ALPHA',
                'Periode' => '=DATE(2026,11,1)',
                'Hari Kerja' => '=NETWORKDAYS(DATE(2026,11,1),DATE(2026,11,30))',
                'Late Less 30 min' => '=1+1',
                'Late More 30 min' => '=IF(1=0,1,"")',
            ]),
            $this->templateRow($headings, [
                'ID karyawan' => '="EMP-"&"BRAVO"',
                'Nama Lengkap' => 'BRAVO',
                'Periode' => '2026-11',
                // Hari Kerja yang di-fill down dari baris atas.
                'Hari Kerja' => '=$D$2',
                'Sakit' => '=COUNTIF(A2:A3,"EMP-*")',
            ]),
        ]]);
        $import = new AttendanceImport;
        Excel::import($import, 'formulas.xlsx', 'local');

        $summary = $import->getImportSummary();
        $this->assertSame(2, $summary['importedCount']);
        $this->assertSame([], $summary['skippedDetails']);
        $alpha = Attendance::query()->where('user_id', $this->alpha->id)->sole();
        $this->assertSame(['2026-11', 21, 2, 0], [$alpha->periode, (int) $alpha->work_days, (int) $alpha->late_less_30, (int) $alpha->late_more_30]);
        $bravo = Attendance::query()->where('user_id', $this->bravo->id)->sole();
        $this->assertSame([21, 2], [(int) $bravo->work_days, (int) $bravo->sick_days]);
    }

    public function test_extra_leading_columns_never_shift_counts_into_the_wrong_component(): void
    {
        Storage::fake('local');
        // Kolom "No" di depan menggeser semua kolom; judul variasi umum (Terlambat < 30, Alpa) dikenali langsung.
        $this->storeWorkbook('numbered.xlsx', ['Worksheet' => [
            ['No', 'NIK', 'Nama', 'Periode', 'Hari Kerja', 'Terlambat < 30', 'Terlambat > 30', 'Sakit', 'Izin', 'Alpa'],
            [1, 'EMP-ALPHA', 'ALPHA', '2026-11', 20, 4, 0, 0, 0, 1],
        ]]);
        $import = new AttendanceImport;
        Excel::import($import, 'numbered.xlsx', 'local');
        $attendance = Attendance::query()->sole();
        $this->assertSame([4, 0], [(int) $attendance->late_less_30, (int) $attendance->late_more_30]);
        $this->assertSame(['mangkir' => 1], $attendance->component_counts);
        $this->assertSame(['Kolom tidak dikenal diabaikan: No.'], $import->getImportSummary()['warnings']);

        // Judul yang tidak dikenali tidak ditebak dari posisi template bila kolomnya bergeser ("Divisi").
        Attendance::query()->forceDelete();
        $this->storeWorkbook('division.xlsx', ['Worksheet' => [
            ['ID karyawan', 'Nama Lengkap', 'Divisi', 'Periode', 'Hari Kerja', 'Telat', 'Telat Berat', 'Sakit'],
            ['EMP-BRAVO', 'BRAVO', 'SALES', '2026-11', 20, 5, 1, 0],
        ]]);
        $shifted = new AttendanceImport;
        Excel::import($shifted, 'division.xlsx', 'local');
        $attendance = Attendance::query()->sole();
        $this->assertSame([0, 0], [(int) $attendance->late_less_30, (int) $attendance->late_more_30]);
        $warnings = $shifted->getImportSummary()['warnings'];
        $this->assertContains('Kolom "Late Less 30 min" tidak ada di file sehingga dianggap 0 untuk periode 2026-11.', $warnings);
        $this->assertContains('Kolom tidak dikenal diabaikan: Divisi, Telat, Telat Berat.', $warnings);
        foreach ($warnings as $warning) {
            $this->assertStringNotContainsString('sesuai urutan template', $warning);
        }
    }

    public function test_workbook_sheets_without_attendance_columns_are_not_imported(): void
    {
        $disk = $this->fakeImportDisk();
        $headings = (new AttendanceImportTemplateExport)->headings();
        $kehadiran = [
            $headings,
            $this->templateRow($headings, ['ID karyawan' => 'EMP-ALPHA', 'Nama Lengkap' => 'ALPHA', 'Periode' => '2026-12', 'Hari Kerja' => 21]),
            $this->templateRow($headings, ['ID karyawan' => 'EMP-BRAVO', 'Nama Lengkap' => 'BRAVO', 'Periode' => '2026-12', 'Hari Kerja' => 21]),
            $this->templateRow($headings, ['ID karyawan' => 'EMP-NOPE', 'Nama Lengkap' => 'NOPE', 'Periode' => '2026-12', 'Hari Kerja' => 21]),
        ];
        // Sheet master karyawan diletakkan di depan; Tanggal Masuk BRAVO kebetulan Desember 2026.
        $this->storeWorkbook('imports/attendances/multi.xlsx', [
            'Data Karyawan' => [
                ['NIK', 'Nama', 'Tanggal Masuk', 'Sisa Cuti'],
                ['EMP-ALPHA', 'ALPHA', ExcelDate::PHPToExcel(new \DateTimeImmutable('2019-03-01')), 12],
                ['EMP-BRAVO', 'BRAVO', ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-12-01')), 8],
            ],
            'Kehadiran' => $kehadiran,
            'Rekap Divisi' => [
                ['Divisi', 'Jumlah Karyawan', 'Hari Kerja', 'Sakit', 'Izin'],
                ['SALES', 2, 21, 1, 0],
            ],
            'Catatan' => [
                ['Catatan pengisian'],
                ['Isi Hari Kerja 1 sampai 31.'],
            ],
        ], $disk);
        $this->actingAs($this->admin);

        $list = Livewire::test(ListAttendances::class)
            ->call('processImport', ['file' => 'imports/attendances/multi.xlsx']);
        $notification = $this->lastNotification();
        $list->assertNotified('Data Kehadiran berhasil diimport. 2 data ditambahkan, 1 data dilewati.');

        $this->assertSame(
            [['EMP-ALPHA', '2026-12', 21], ['EMP-BRAVO', '2026-12', 21]],
            Attendance::query()->with('user')->orderBy('user_id')->get()
                ->map(fn (Attendance $attendance): array => [$attendance->user->employee_id, $attendance->periode, (int) $attendance->work_days])
                ->all(),
        );

        $body = $notification['body'] ?? '';
        $this->assertStringContainsString('Sheet &quot;Data Karyawan&quot; tidak diimport: Kolom Periode tidak ditemukan di baris judul', $body);
        $this->assertStringContainsString('Sheet &quot;Rekap Divisi&quot; tidak diimport: Kolom ID karyawan dan Nama Lengkap tidak ditemukan di baris judul.', $body);
        $this->assertStringContainsString('EMP-NOPE / NOPE (2026-12): Sheet &quot;Kehadiran&quot;: Karyawan tidak ditemukan.', $body);
        $this->assertStringNotContainsString('Catatan', $body);
        $this->assertStringNotContainsString('dibaca sebagai', $body);
    }

    public function test_headerless_sheet_mentioning_employees_is_skipped_with_one_warning(): void
    {
        $disk = $this->fakeImportDisk();
        $headings = (new AttendanceImportTemplateExport)->headings();
        $this->storeWorkbook('imports/attendances/notes.xlsx', [
            'Kehadiran' => [
                $headings,
                $this->templateRow($headings, ['ID karyawan' => 'EMP-ALPHA', 'Nama Lengkap' => 'ALPHA', 'Periode' => '2026-12', 'Hari Kerja' => 21]),
                $this->templateRow($headings, ['ID karyawan' => 'EMP-BRAVO', 'Nama Lengkap' => 'BRAVO', 'Periode' => '2026-12']),
            ],
            // Catatan HR yang menyebut karyawan (bahkan dengan angka di kolom ke-4) bukan data kehadiran.
            'Catatan' => [
                ['Catatan HR'],
                ['EMP-ALPHA', 'ALPHA', 'Cek ulang', 3],
                ['EMP-BRAVO', 'BRAVO', 'Cuti bersama'],
            ],
        ], $disk);
        $this->actingAs($this->admin);

        $list = Livewire::test(ListAttendances::class)
            ->call('processImport', ['file' => 'imports/attendances/notes.xlsx']);
        $notification = $this->lastNotification();
        $list->assertNotified('Data Kehadiran berhasil diimport. 1 data ditambahkan, 0 data dilewati. 1 baris template belum diisi (diabaikan).');

        $this->assertSame(1, Attendance::query()->count());
        $body = $notification['body'] ?? '';
        $this->assertSame(1, substr_count($body, 'Sheet &quot;Catatan&quot; tidak diimport: baris judul template'));
        $this->assertStringNotContainsString('Karyawan tidak ditemukan', $body);
    }

    public function test_label_rows_above_the_heading_leave_no_false_warnings(): void
    {
        $disk = $this->fakeImportDisk();
        $headings = (new AttendanceImportTemplateExport)->headings();
        $this->storeWorkbook('imports/attendances/rekap.xlsx', ['Rekap' => [
            ['REKAP KEHADIRAN'],
            ['Periode', 'November 2026'],
            ['Periode', ':', 'November 2026'],
            ['Divisi', ':', 'SALES'],
            [null],
            $headings,
            $this->templateRow($headings, ['ID karyawan' => 'EMP-ALPHA', 'Nama Lengkap' => 'ALPHA', 'Periode' => '2026-11', 'Hari Kerja' => 20, 'Late Less 30 min' => 1, 'Sakit' => 1, 'Izin [izin]' => 2, 'Mangkir (tanpa keterangan) [mangkir]' => 1]),
        ]], $disk);
        $this->actingAs($this->admin);

        $list = Livewire::test(ListAttendances::class)
            ->call('processImport', ['file' => 'imports/attendances/rekap.xlsx']);
        $notification = $this->lastNotification();
        $list->assertNotified('Data Kehadiran berhasil diimport. 1 data ditambahkan, 0 data dilewati.');

        // Tanpa peringatan kolom maupun "baris template belum diisi" dari baris label.
        $this->assertSame('Data Kehadiran berhasil diimport. 1 data ditambahkan, 0 data dilewati.', $notification['title'] ?? null);
        $this->assertNull($notification['body'] ?? null);
        $attendance = Attendance::query()->sole();
        $this->assertSame([20, 1, 1], [(int) $attendance->work_days, (int) $attendance->late_less_30, (int) $attendance->sick_days]);
        $this->assertSame(['izin' => 2, 'mangkir' => 1], $attendance->component_counts);
    }

    public function test_non_breaking_spaces_around_values_are_ignored(): void
    {
        $nbsp = "\u{00A0}";
        $import = new AttendanceImport;

        $attendance = $import->model(["EMP-ALPHA{$nbsp}", 'ALPHA', "2026-11{$nbsp}", "20{$nbsp}", "2{$nbsp}", "{$nbsp}", 0]);

        $this->assertInstanceOf(Attendance::class, $attendance);
        $this->assertSame([$this->alpha->id, '2026-11', 20, 2, 0], [$attendance->user_id, $attendance->periode, $attendance->work_days, $attendance->late_less_30, $attendance->late_more_30]);
        $this->assertSame([], $import->getImportSummary()['skippedDetails']);
    }

    public function test_duplicate_employee_and_periode_in_the_same_file_is_reported_as_a_duplicate_row(): void
    {
        Storage::fake('local');
        Attendance::query()->create(['user_id' => $this->bravo->id, 'periode' => '2026-11', 'work_days' => 20]);
        $headings = (new AttendanceImportTemplateExport)->headings();
        $this->storeWorkbook('duplicate.xlsx', ['Worksheet' => [
            $headings,
            $this->templateRow($headings, ['ID karyawan' => 'EMP-ALPHA', 'Nama Lengkap' => 'ALPHA', 'Periode' => '2026-11', 'Hari Kerja' => 20, 'Late Less 30 min' => 5]),
            $this->templateRow($headings, ['ID karyawan' => 'EMP-BRAVO', 'Nama Lengkap' => 'BRAVO', 'Periode' => '2026-11', 'Hari Kerja' => 20]),
            $this->templateRow($headings, ['ID karyawan' => 'EMP-ALPHA', 'Nama Lengkap' => 'ALPHA', 'Periode' => '2026-11', 'Hari Kerja' => 20, 'Late Less 30 min' => 1]),
        ]]);
        $import = new AttendanceImport;
        Excel::import($import, 'duplicate.xlsx', 'local');

        $this->assertSame(5, (int) Attendance::query()->where('user_id', $this->alpha->id)->sole()->late_less_30);
        $this->assertSame([
            'Data kehadiran karyawan ini untuk periode tersebut sudah ada.',
            'Baris ganda: karyawan dan periode ini sudah diisi di baris sebelumnya pada file yang sama. Hanya baris pertama yang diimport.',
        ], array_column($import->getImportSummary()['skippedDetails'], 'reason'));
    }

    public function test_edit_form_and_api_refuse_moving_a_record_onto_an_existing_periode(): void
    {
        $november = Attendance::query()->create(['user_id' => $this->alpha->id, 'periode' => '2026-11', 'work_days' => 20, 'component_counts' => ['mangkir' => 2]]);
        $december = Attendance::query()->create(['user_id' => $this->alpha->id, 'periode' => '2026-12', 'work_days' => 20]);
        $this->actingAs($this->admin);

        Livewire::test(EditAttendance::class, ['record' => $december->id])
            ->fillForm(['periode' => '2026-11'])
            ->call('save')
            ->assertNotified('Data sudah ada');
        $this->assertSame('2026-12', $december->refresh()->periode);

        // Karyawan juga tidak boleh dipindah ke karyawan yang sudah punya periode itu.
        Attendance::query()->create(['user_id' => $this->bravo->id, 'periode' => '2026-12', 'work_days' => 22]);
        Livewire::test(EditAttendance::class, ['record' => $december->id])
            ->fillForm(['user_id' => $this->bravo->id])
            ->call('save')
            ->assertNotified('Data sudah ada');
        $this->assertSame($this->alpha->id, (int) $december->refresh()->user_id);

        // Mengubah data lain pada record yang sama tetap bisa.
        Livewire::test(EditAttendance::class, ['record' => $december->id])
            ->fillForm(['work_days' => 21])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(21, (int) $december->refresh()->work_days);

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/attendances/'.$december->id, ['periode' => '2026-11'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Data presensi untuk karyawan ini pada periode 2026-11 sudah ada.')
            ->assertJsonPath('data.id', $november->id);
        $this->assertSame('2026-12', $december->refresh()->periode);
        $this->putJson('/api/v1/attendances/'.$december->id, ['periode' => '2026-12', 'work_days' => 20])->assertOk();
        $this->assertSame(1, Attendance::query()->where('user_id', $this->alpha->id)->where('periode', '2026-11')->count());
    }

    public function test_scoped_admin_import_explains_why_visible_rows_are_refused(): void
    {
        $disk = $this->fakeImportDisk();
        $coo = $this->fixtureArea('COO');
        $uls = $this->fixtureArea('ULS');
        $ojan = $this->fixtureScopedAdmin('ojan', $coo, [$uls]);
        $ojan->update(['employee_id' => 'EMP-OJAN']);
        $ulsSuper = $this->fixtureGlobalAdmin('uls_super', $uls);
        $ulsSuper->update(['employee_id' => 'EMP-SUPER']);
        $this->fixtureUser('uls_staff', 'STAFF', $uls, null, ['employee_id' => 'EMP-ULS']);
        $this->resetAccessMemos();

        $headings = (new AttendanceImportTemplateExport)->headings();
        $rows = [$headings];
        foreach ([['EMP-OJAN', 'OJAN'], ['EMP-SUPER', 'ULS SUPER'], ['EMP-ULS', 'ULS STAFF'], ['EMP-ALPHA', 'ALPHA'], ['EMP-NOPE', 'NOPE']] as [$id, $name]) {
            $rows[] = $this->templateRow($headings, ['ID karyawan' => $id, 'Nama Lengkap' => $name, 'Periode' => '2026-11', 'Hari Kerja' => 20]);
        }
        $this->storeWorkbook('imports/attendances/scoped.xlsx', ['Worksheet' => $rows], $disk);
        $this->actingAs($ojan);
        Livewire::actingAs($ojan);

        $list = Livewire::test(ListAttendances::class)
            ->call('processImport', ['file' => 'imports/attendances/scoped.xlsx']);
        $body = $this->lastNotification()['body'] ?? '';
        $list->assertNotified('Data Kehadiran berhasil diimport. 1 data ditambahkan, 4 data dilewati.');

        $this->assertStringContainsString('EMP-OJAN / OJAN (2026-11): '.self::READ_ONLY_REASON, $body);
        $this->assertStringContainsString('EMP-SUPER / ULS SUPER (2026-11): '.self::READ_ONLY_REASON, $body);
        // Karyawan di luar cakupan dilaporkan sama dengan karyawan yang tidak ada.
        $this->assertStringContainsString('EMP-ALPHA / ALPHA (2026-11): '.self::NOT_FOUND_REASON, $body);
        $this->assertStringContainsString('EMP-NOPE / NOPE (2026-11): '.self::NOT_FOUND_REASON, $body);
        $this->assertStringNotContainsString('di luar cakupan', $body);
        $this->assertSame('ULS STAFF', Attendance::query()->with('user')->sole()->user->nama_lengkap);
    }

    public function test_export_writes_zero_values_and_a_zero_work_day_row_gets_a_reason_on_reimport(): void
    {
        Storage::fake('local');
        // API menerima Hari Kerja 0 (= skor 0); import hanya menerima 1-31.
        Attendance::query()->create(['user_id' => $this->alpha->id, 'periode' => '2026-11', 'work_days' => 0, 'component_counts' => ['izin' => 2]]);
        Attendance::query()->create(['user_id' => $this->bravo->id, 'periode' => '2026-12', 'work_days' => 20]);

        Excel::store(new AttendanceExport, 'export.xlsx', 'local');
        $rows = collect(IOFactory::load(Storage::disk('local')->path('export.xlsx'))->getActiveSheet()->toArray(null, false, false))
            ->keyBy(0);
        $this->assertEquals(['EMP-ALPHA', 'ALPHA', '2026-11', 0, 0, 0, 0, 2, 0, 0, 0], $rows['EMP-ALPHA']);
        $this->assertEquals(['EMP-BRAVO', 'BRAVO', '2026-12', 20, 0, 0, 0, 0, 0, 0, 0], $rows['EMP-BRAVO']);
        $this->assertNotContains(null, $rows['EMP-BRAVO']);

        // Diimport ulang selagi datanya masih ada: kedua baris "sudah ada" (termasuk baris Hari Kerja 0), bukan
        // "tidak valid".
        $again = new AttendanceImport;
        Excel::import($again, 'export.xlsx', 'local');
        $this->assertSame(0, $again->getImportSummary()['importedCount']);
        $this->assertSame([
            ['EMP-ALPHA', 'Data kehadiran karyawan ini untuk periode tersebut sudah ada.'],
            ['EMP-BRAVO', 'Data kehadiran karyawan ini untuk periode tersebut sudah ada.'],
        ], array_map(
            static fn (array $detail): array => [$detail['employee_id'], $detail['reason']],
            $again->getImportSummary()['skippedDetails'],
        ));

        Attendance::query()->forceDelete();
        $import = new AttendanceImport;
        Excel::import($import, 'export.xlsx', 'local');
        $summary = $import->getImportSummary();
        $this->assertSame(1, $summary['importedCount']);
        $this->assertSame(0, $summary['unfilledCount']);
        $this->assertSame([['EMP-ALPHA', 'Hari Kerja harus bilangan bulat 1 sampai 31.']], array_map(
            static fn (array $detail): array => [$detail['employee_id'], $detail['reason']],
            $summary['skippedDetails'],
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
     * Baris template: nilai per judul kolom, sel lain kosong.
     *
     * @param  list<string>  $headings
     * @param  array<string, mixed>  $values
     * @return list<mixed>
     */
    private function templateRow(array $headings, array $values): array
    {
        $this->assertSame([], array_diff(array_keys($values), $headings));

        return array_map(static fn (string $heading): mixed => $values[$heading] ?? null, $headings);
    }

    /**
     * Workbook xlsx (nilai 0 tetap ditulis, teks berawalan "=" menjadi rumus).
     *
     * @param  array<string, list<list<mixed>>>  $sheets  judul sheet => baris
     */
    private function storeWorkbook(string $path, array $sheets, string $disk = 'local'): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($rows, null, 'A1', true);
        }

        if (($directory = dirname($path)) !== '.') {
            Storage::disk($disk)->makeDirectory($directory);
        }

        (new Xlsx($spreadsheet))->save(Storage::disk($disk)->path($path));
    }

    private function fakeImportDisk(): string
    {
        $disk = (string) (config('filament.default_filesystem_disk') ?: config('filesystems.default', 'local'));
        Storage::fake($disk);

        return $disk;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastNotification(): array
    {
        return collect(session('filament.claimed_notifications') ?? session('filament.notifications'))->last() ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshot(): array
    {
        return Attendance::query()
            ->orderBy('periode')
            ->orderBy('user_id')
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
