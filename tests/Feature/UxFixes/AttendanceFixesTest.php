<?php

namespace Tests\Feature\UxFixes;

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
use App\Support\Periode;
use App\Support\Scoring\AttendanceCountInput;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Perbaikan UX Kehadiran:
 * - FIX 3: periode default bulan lalu (form, template, import), template & import per periode yang dipilih,
 *   konfirmasi import periode yang belum selesai, nama periode di notifikasi import.
 * - FIX 4: komponen mengikuti versi aturan periode data (form, template, import, API): komponen yang tidak
 *   tercantum di aturan periode tidak bisa diisi, dan kolom Sakit bernama "Sakit/Izin" pada periode yang izinnya
 *   masih dicatat bersama sakit (sebelum November 2026).
 *
 * Tanggal tes 18 Oktober 2026: bulan lalu = 2026-09 dan bulan berjalan = 2026-10 (Aturan Lama, izin dicatat di
 * Sakit/Izin); 2026-11 = Aturan Kehadiran November 2026 (izin, mangkir, cuti dicatat sendiri).
 */
class AttendanceFixesTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private const V1_HEADINGS = ['ID karyawan', 'Nama Lengkap', 'Periode', 'Hari Kerja', 'Late Less 30 min', 'Late More 30 min', 'Sakit/Izin'];

    private const V2_HEADINGS = [
        'ID karyawan', 'Nama Lengkap', 'Periode', 'Hari Kerja', 'Late Less 30 min', 'Late More 30 min', 'Sakit',
        'Izin [izin]', 'Mangkir (tanpa keterangan) [mangkir]', 'Cuti tahunan [cuti_tahunan]', 'Cuti lainnya [cuti_lainnya]',
    ];

    private const IZIN_OCTOBER_REASON = 'Aturan penilaian Oktober 2026 belum memakai Izin, tetapi kolomnya diisi. Untuk periode ini izin dicatat di kolom Sakit/Izin: jumlahkan angka Izin ke kolom Sakit/Izin, lalu kosongkan kolom Izin. Bila periodenya yang keliru, perbaiki periodenya.';

    private const IZIN_OCTOBER_API_ERROR = 'Aturan penilaian Oktober 2026 belum memakai Izin, tetapi nilainya lebih dari 0. Untuk periode ini izin dicatat di sick_days (Sakit/Izin): tambahkan jumlah izin ke sick_days dan kirim components.izin 0. Bila periodenya yang keliru, perbaiki periodenya.';

    private const IZIN_OCTOBER_FORM_ERROR = 'Aturan penilaian Oktober 2026 belum memakai Izin, tetapi isian ini lebih dari 0. Untuk periode ini izin dicatat di Sakit/Izin: jumlahkan ke Sakit/Izin, lalu isi 0 di sini. Bila periodenya yang keliru, perbaiki periodenya.';

    private User $admin;

    private User $alpha;

    private User $bravo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Date::create(2026, 10, 18, 10));
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
    // Periode & aturan per komponen (dipakai bersama form, template, import, API)
    // ---------------------------------------------------------------------

    public function test_periode_helpers_default_to_the_previous_month(): void
    {
        $this->assertSame('2026-09', Periode::previous());
        $this->assertSame('September 2026', Periode::label(Periode::previous()));
        $this->assertSame('Oktober 2026', Periode::label('2026-10'));
        $this->assertSame('Januari 2027', Periode::label('2027-1'));
        $this->assertSame('bukan periode', Periode::label('bukan periode'));
        $this->assertFalse(Periode::isCurrentOrLater('2026-09'));
        $this->assertTrue(Periode::isCurrentOrLater('2026-10'));
        $this->assertTrue(Periode::isCurrentOrLater('2027-01'));
        $this->assertFalse(Periode::isCurrentOrLater('2026-13'));

        // Akhir bulan tidak meloncati bulan pendek; Januari jatuh ke Desember tahun sebelumnya.
        $this->travelTo(Date::create(2026, 3, 31, 12));
        $this->assertSame('2026-02', Periode::previous());
        $this->travelTo(Date::create(2027, 1, 1, 0, 5));
        $this->assertSame('2026-12', Periode::previous());
    }

    public function test_component_usage_and_sick_label_follow_the_rule_of_the_periode(): void
    {
        $october = AttendanceCountInput::ruleFor('2026-10');
        $november = AttendanceCountInput::ruleFor('2026-11');
        $this->assertNotNull($october);
        $this->assertNotNull($november);
        $this->assertNull(AttendanceCountInput::ruleFor('1900-01'));
        $this->assertNull(AttendanceCountInput::ruleFor(''));

        // Dipakai = tercantum di attendance_rules versi itu (walau penalti 0); tiga komponen lama selalu boleh.
        foreach (['late_less_30', 'late_more_30', 'sick_days'] as $code) {
            $this->assertTrue(AttendanceCountInput::isUsedBy($code, $october));
        }
        $this->assertFalse(AttendanceCountInput::isUsedBy('izin', $october));
        $this->assertFalse(AttendanceCountInput::isUsedBy('mangkir', $october));
        $this->assertTrue(AttendanceCountInput::isUsedBy('izin', $november));
        $this->assertTrue(AttendanceCountInput::isUsedBy('cuti_lainnya', $november));

        $sick = AttendanceCountInput::inputComponents()->get('sick_days');
        $this->assertNotNull($sick);
        $this->assertTrue(AttendanceCountInput::recordsIzinInSick($october));
        $this->assertSame('Sakit/Izin', AttendanceCountInput::componentLabel($sick, $october));
        $this->assertSame('Untuk periode ini izin dicatat bersama sakit.', AttendanceCountInput::componentHelperText($sick, $october));
        $this->assertFalse(AttendanceCountInput::recordsIzinInSick($november));
        $this->assertSame('Sakit', AttendanceCountInput::componentLabel($sick, $november));
        $this->assertSame('Sebelum November 2026 kolom ini berisi sakit dan izin (Sakit/Izin).', AttendanceCountInput::componentHelperText($sick, $november));
        $this->assertSame('Sakit', AttendanceCountInput::componentLabel($sick, null));

        // "belum" bila versi sesudahnya memakai komponen itu; "tidak" bila tidak ada versi yang memakainya.
        $this->assertSame(self::IZIN_OCTOBER_REASON, AttendanceCountInput::unusedComponentsMessage(['izin'], '2026-10'));
        AttendanceComponent::query()->create(['code' => 'dinas_luar', 'name' => 'Dinas luar', 'unit' => 'hari', 'sort_order' => 8]);
        $this->assertSame(
            'Aturan penilaian November 2026 tidak memakai Dinas luar, tetapi kolomnya diisi. Kosongkan kolom Dinas luar. Bila periodenya yang keliru, perbaiki periodenya.',
            AttendanceCountInput::unusedComponentsMessage(['dinas_luar'], '2026-11'),
        );
    }

    // ---------------------------------------------------------------------
    // Form Kehadiran
    // ---------------------------------------------------------------------

    public function test_create_form_defaults_to_the_previous_month_and_disables_components_its_rule_does_not_use(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateAttendance::class)
            ->assertFormSet(['periode' => '2026-09'])
            // September 2026 = Aturan Lama: izin dicatat bersama sakit.
            ->assertFormFieldExists('sick_days', fn (TextInput $field): bool => $field->getLabel() === 'Sakit/Izin (hari)')
            ->assertSee('Untuk periode ini izin dicatat bersama sakit.')
            ->assertDontSee('Sebelum November 2026 kolom ini berisi sakit dan izin (Sakit/Izin).')
            ->assertSee('Tidak dipakai di aturan periode ini')
            ->assertFormFieldEnabled('late_less_30')
            ->assertFormFieldEnabled('late_more_30')
            ->assertFormFieldEnabled('sick_days')
            ->assertFormFieldDisabled('component_counts.izin')
            ->assertFormFieldDisabled('component_counts.mangkir')
            ->assertFormFieldDisabled('component_counts.cuti_tahunan')
            ->assertFormFieldDisabled('component_counts.cuti_lainnya')
            // Ganti periode: isian ikut berubah langsung (tanpa menyimpan).
            ->fillForm(['periode' => '2026-11'])
            ->assertFormFieldEnabled('component_counts.izin')
            ->assertFormFieldEnabled('component_counts.mangkir')
            ->assertFormFieldExists('sick_days', fn (TextInput $field): bool => $field->getLabel() === 'Sakit (hari)')
            ->assertSee('Sebelum November 2026 kolom ini berisi sakit dan izin (Sakit/Izin).')
            ->assertDontSee('Tidak dipakai di aturan periode ini')
            // Angka yang sempat diketik lalu menjadi nonaktif kembali ke 0 (isian nonaktif tidak disimpan).
            ->fillForm(['component_counts.izin' => 2])
            ->fillForm(['periode' => '2026-10'])
            ->assertFormFieldDisabled('component_counts.izin')
            ->assertFormSet(['component_counts.izin' => 0])
            ->assertFormFieldExists('sick_days', fn (TextInput $field): bool => $field->getLabel() === 'Sakit/Izin (hari)')
            // Periode belum valid: tidak ada yang dinonaktifkan, validasi periode yang menolak.
            ->fillForm(['periode' => '1900-01'])
            ->assertFormFieldEnabled('component_counts.izin')
            ->fillForm([
                'user_id' => $this->alpha->id,
                'periode' => '2026-11',
                'work_days' => 20,
                'sick_days' => 1,
                'component_counts.izin' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $attendance = Attendance::query()->where('user_id', $this->alpha->id)->sole();
        $this->assertSame(['2026-11', 1], [$attendance->periode, (int) $attendance->sick_days]);
        $this->assertSame(['izin' => 2], $attendance->component_counts);
    }

    public function test_disabled_components_are_never_saved_even_when_the_request_is_tampered_with(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'user_id' => $this->alpha->id,
                'periode' => '2026-10',
                'work_days' => 21,
                'sick_days' => 2,
            ])
            // Isian nonaktif diisi lewat state Livewire (bukan dari UI).
            ->set('data.component_counts.izin', 3)
            ->call('create')
            ->assertHasNoFormErrors();

        $attendance = Attendance::query()->sole();
        $this->assertSame(2, (int) $attendance->sick_days);
        $this->assertNull($attendance->component_counts);
    }

    public function test_create_another_keeps_periode_and_work_days(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'user_id' => $this->alpha->id,
                'periode' => '2026-08',
                'work_days' => 21,
                'late_less_30' => 2,
            ])
            ->call('createAnother')
            ->assertHasNoFormErrors()
            ->assertFormSet([
                'periode' => '2026-08',
                'work_days' => 21,
                'user_id' => null,
                'late_less_30' => 0,
            ])
            ->fillForm(['user_id' => $this->bravo->id, 'sick_days' => 1])
            ->call('createAnother')
            ->assertHasNoFormErrors()
            ->assertFormSet(['periode' => '2026-08', 'work_days' => 21, 'sick_days' => 0]);

        $this->assertSame(
            [[$this->alpha->id, '2026-08', 21, 2, 0], [$this->bravo->id, '2026-08', 21, 0, 1]],
            Attendance::query()->orderBy('user_id')->get()
                ->map(fn (Attendance $attendance): array => [(int) $attendance->user_id, $attendance->periode, (int) $attendance->work_days, (int) $attendance->late_less_30, (int) $attendance->sick_days])
                ->all(),
        );
    }

    public function test_edit_form_keeps_stored_values_of_components_the_rule_does_not_use_and_lets_hr_clear_them(): void
    {
        // Data lama: izin tercatat untuk Oktober 2026 (sebelum perbaikan ini), padahal aturannya belum memakai izin.
        $attendance = Attendance::query()->create([
            'user_id' => $this->alpha->id,
            'periode' => '2026-10',
            'work_days' => 22,
            'sick_days' => 1,
            'component_counts' => ['izin' => 2],
        ]);
        $this->actingAs($this->admin);

        $form = Livewire::test(EditAttendance::class, ['record' => $attendance->id])
            // Nilai tersimpan tetap terlihat dan isiannya tetap bisa diubah agar HR bisa mengosongkannya.
            ->assertFormSet(['component_counts.izin' => 2, 'sick_days' => 1])
            ->assertFormFieldEnabled('component_counts.izin')
            ->assertSee('Tersimpan 2, tidak dihitung untuk periode ini')
            ->assertSee('Untuk periode ini izin dicatat di Sakit/Izin: jumlahkan ke Sakit/Izin, lalu isi 0.')
            // Komponen tanpa hitungan tersimpan tetap nonaktif.
            ->assertFormFieldDisabled('component_counts.mangkir')
            ->assertFormFieldExists('sick_days', fn (TextInput $field): bool => $field->getLabel() === 'Sakit/Izin (hari)')
            // Pindah ke periode aturan baru: izin dihitung.
            ->fillForm(['periode' => '2026-11'])
            ->assertFormFieldEnabled('component_counts.izin')
            ->assertFormFieldExists('sick_days', fn (TextInput $field): bool => $field->getLabel() === 'Sakit (hari)')
            ->fillForm(['component_counts.izin' => 4])
            // Kembali ke Oktober: angka yang diketik tetap terlihat (isiannya aktif dan ikut disimpan), tetapi hanya
            // boleh 0 atau nilai tersimpan.
            ->fillForm(['periode' => '2026-10'])
            ->assertFormFieldEnabled('component_counts.izin')
            ->assertFormSet(['component_counts.izin' => 4])
            ->fillForm(['work_days' => 21])
            ->call('save')
            ->assertHasFormErrors(['component_counts.izin']);
        $this->assertSame(self::IZIN_OCTOBER_FORM_ERROR, $form->errors()->first('data.component_counts.izin'));
        $this->assertSame(22, (int) $attendance->refresh()->work_days);

        // Periode tidak diganti dan nilai tersimpan tidak diubah: data lama tetap bisa diubah bagian lainnya.
        $form->fillForm(['component_counts.izin' => 2])
            ->call('save')
            ->assertHasNoFormErrors();
        $attendance->refresh();
        $this->assertSame(['2026-10', 21, 1], [$attendance->periode, (int) $attendance->work_days, (int) $attendance->sick_days]);
        $this->assertSame(['izin' => 2], $attendance->component_counts);

        // Dirapikan: izin dijumlahkan ke Sakit/Izin lalu dikosongkan.
        Livewire::test(EditAttendance::class, ['record' => $attendance->id])
            ->fillForm(['sick_days' => 3, 'component_counts.izin' => 0])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame([3, null], [(int) $attendance->refresh()->sick_days, $attendance->component_counts]);

        // Setelah kosong, isian izin Oktober kembali nonaktif; dipindah ke November izin bisa diisi dan disimpan.
        Livewire::test(EditAttendance::class, ['record' => $attendance->id])
            ->assertFormFieldDisabled('component_counts.izin')
            ->fillForm(['periode' => '2026-11', 'component_counts.izin' => 3])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['2026-11', ['izin' => 3]], [$attendance->refresh()->periode, $attendance->component_counts]);
    }

    public function test_edit_form_does_not_move_counts_into_a_periode_whose_rule_does_not_use_them(): void
    {
        $attendance = Attendance::query()->create([
            'user_id' => $this->alpha->id,
            'periode' => '2026-11',
            'work_days' => 20,
            'sick_days' => 1,
            'component_counts' => ['izin' => 3],
        ]);
        $this->actingAs($this->admin);

        // November -> Oktober dengan izin 3: ditolak dengan langkahnya, bukan disimpan tanpa penalti izin.
        $form = Livewire::test(EditAttendance::class, ['record' => $attendance->id])
            ->fillForm(['periode' => '2026-10'])
            ->assertFormFieldEnabled('component_counts.izin')
            ->assertFormSet(['component_counts.izin' => 3])
            ->assertSee('Tersimpan 3, tidak dihitung untuk periode ini')
            ->call('save')
            ->assertHasFormErrors(['component_counts.izin']);
        $this->assertSame(self::IZIN_OCTOBER_FORM_ERROR, $form->errors()->first('data.component_counts.izin'));
        $this->assertSame(['2026-11', ['izin' => 3]], [$attendance->refresh()->periode, $attendance->component_counts]);

        // Izin dijumlahkan ke Sakit/Izin dan dikosongkan: boleh dipindah.
        $form->fillForm(['sick_days' => 4, 'component_counts.izin' => 0])
            ->call('save')
            ->assertHasNoFormErrors();
        $attendance->refresh();
        $this->assertSame(['2026-10', 4, null], [$attendance->periode, (int) $attendance->sick_days, $attendance->component_counts]);
    }

    // ---------------------------------------------------------------------
    // Template import
    // ---------------------------------------------------------------------

    public function test_template_columns_and_rows_follow_the_chosen_periode(): void
    {
        // Default: bulan lalu (Aturan Lama): tujuh kolom lama, kolom 7 "Sakit/Izin", tanpa kolom izin dkk.
        $default = new AttendanceImportTemplateExport;
        $this->assertSame('2026-09', $default->periode());
        $this->assertSame(self::V1_HEADINGS, $default->headings());
        $row = $default->map($default->collection()->firstWhere('employee_id', 'EMP-ALPHA'));
        $this->assertSame(['EMP-ALPHA', 'ALPHA', '2026-09', '', '', '', ''], $row);

        // Periode aturan baru: kolom 7 "Sakit", lalu komponen yang tercantum di aturan itu.
        $november = new AttendanceImportTemplateExport(null, '2026-11');
        $this->assertSame(self::V2_HEADINGS, $november->headings());
        $row = $november->map($november->collection()->firstWhere('employee_id', 'EMP-ALPHA'));
        $this->assertSame('2026-11', $row[2]);
        $this->assertCount(count(self::V2_HEADINGS), $row);

        // Komponen yang belum tercantum di aturan mana pun tidak ada di template periode mana pun.
        AttendanceComponent::query()->create(['code' => 'dinas_luar', 'name' => 'Dinas luar', 'unit' => 'hari', 'sort_order' => 8]);
        $this->assertSame(self::V2_HEADINGS, (new AttendanceImportTemplateExport(null, '2026-12'))->headings());
        $this->assertSame(self::V1_HEADINGS, (new AttendanceImportTemplateExport(null, '2026-10'))->headings());

        $this->assertSame('attendance_import_template_2026-09.xlsx', AttendanceImportTemplateExport::fileName('2026-09'));

        // Catatan judul: arti kolom Sakit/Izin dan periode template.
        Storage::fake('local');
        Excel::store(new AttendanceImportTemplateExport, 'template.xlsx', 'local');
        $sheet = IOFactory::load(Storage::disk('local')->path('template.xlsx'))->getActiveSheet();
        $this->assertSame('Sakit/Izin', $sheet->getCell('G1')->getValue());
        $this->assertSame('2026-09', $sheet->getCell('C2')->getValue());
        $this->assertStringContainsString('Sakit/Izin (hari)', $sheet->getComment('G1')->getText()->getPlainText());
        $this->assertStringContainsString('Untuk periode ini izin dicatat bersama sakit.', $sheet->getComment('G1')->getText()->getPlainText());
        $this->assertStringContainsString('Template periode September 2026 (2026-09)', $sheet->getComment('C1')->getText()->getPlainText());
        $this->assertNull($sheet->getCell('H1')->getValue());

        // Template periode itu langsung bisa diimport kembali (kolom Sakit/Izin => sick_days).
        $sheet->setCellValue('D2', 20)->setCellValue('G2', 2);
        (new Xlsx($sheet->getParent()))->save(Storage::disk('local')->path('filled.xlsx'));
        $import = new AttendanceImport(null, null, '2026-09');
        Excel::import($import, 'filled.xlsx', 'local');
        $this->assertSame(1, $import->getImportSummary()['importedCount']);
        $this->assertSame(2, (int) Attendance::query()->sole()->sick_days);
    }

    public function test_template_rejects_an_invalid_periode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AttendanceImportTemplateExport(null, '1900-01');
    }

    public function test_download_template_modal_asks_for_the_periode(): void
    {
        Excel::fake();
        $this->actingAs($this->admin);

        Livewire::test(ListAttendances::class)
            ->mountAction('downloadTemplate')
            ->assertActionDataSet(['periode' => '2026-09'])
            ->assertMountedActionModalSee('Aturan penilaian: Aturan Lama. Izin dicatat bersama sakit di kolom Sakit/Izin.')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Excel::assertDownloaded('attendance_import_template_2026-09.xlsx', fn (AttendanceImportTemplateExport $export): bool => $export->periode() === '2026-09' && $export->headings() === self::V1_HEADINGS);

        Livewire::test(ListAttendances::class)
            ->callAction('downloadTemplate', ['periode' => '2026-11'])
            ->assertHasNoActionErrors();

        Excel::assertDownloaded('attendance_import_template_2026-11.xlsx', function (AttendanceImportTemplateExport $export): bool {
            $periodes = $export->collection()->pluck('periode')->unique()->values()->all();

            return $export->headings() === self::V2_HEADINGS && $periodes === ['2026-11'];
        });

        Livewire::test(ListAttendances::class)
            ->callAction('downloadTemplate', ['periode' => ''])
            ->assertHasActionErrors(['periode' => 'required']);
        Livewire::test(ListAttendances::class)
            ->callAction('downloadTemplate', ['periode' => '1970-01'])
            ->assertHasActionErrors(['periode']);
    }

    // ---------------------------------------------------------------------
    // Import
    // ---------------------------------------------------------------------

    public function test_import_with_a_chosen_periode_fills_blank_cells_and_skips_other_periodes(): void
    {
        $import = new AttendanceImport(null, null, '2026-09');

        // Sel Periode kosong (juga file 7 kolom tanpa judul) memakai periode yang dipilih.
        $blank = $import->model(['EMP-ALPHA', 'ALPHA', '', 20, 1, 0, 2]);
        $this->assertInstanceOf(Attendance::class, $blank);
        $this->assertSame('2026-09', $blank->periode);
        $this->assertSame('2026-09', $import->model(['EMP-BRAVO', 'BRAVO', null, 21, 0, 0, 0])?->periode);

        // Periode lain di file dilewati dengan alasannya (termasuk tanggal Excel yang terbaca bulan lain).
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', '2026-10', 20, 0, 0, 0]));
        $this->assertNull($import->model(['EMP-ALPHA', 'ALPHA', '2026-08-01', 20, 0, 0, 0]));
        // Periode yang sama dalam bentuk lain tetap diterima.
        $this->assertSame('2026-09', (new AttendanceImport(null, null, '2026-9'))->model(['EMP-ALPHA', 'ALPHA', '15/09/2026', 20, 0, 0, 0])?->periode);

        // Baris periode lain diringkas per periode dalam satu peringatan (bukan alasan per baris).
        $summary = $import->getImportSummary();
        $this->assertSame('2026-09', $summary['periode']);
        $this->assertSame([2, 2], [$summary['importedCount'], $summary['skippedCount']]);
        $this->assertSame([], $summary['skippedDetails']);
        $this->assertSame(['2026-08' => 1, '2026-10' => 1], $summary['periodeMismatches']);
        $this->assertSame([
            'File berisi 1 baris periode Agustus 2026 dan 1 baris periode Oktober 2026, bukan September 2026 yang dipilih; baris tersebut dilewati. '
                .'Bila datanya untuk periode tersebut, import ulang file ini untuk setiap periode dan pilih periodenya. '
                .'Bila datanya untuk September 2026, perbaiki atau kosongkan kolom Periode di file.',
        ], $summary['warnings']);

        // Tanpa periode yang dipilih (pemanggil lama): sel kosong tetap ditolak, file 7 kolom tetap terbaca.
        $legacy = new AttendanceImport;
        $this->assertNull($legacy->model(['EMP-ALPHA', 'ALPHA', '', 20, 0, 0, 0]));
        $this->assertStringContainsString('Periode kosong', $legacy->getImportSummary()['skippedDetails'][0]['reason']);
        $this->assertSame('2026-10', $legacy->model(['EMP-ALPHA', 'ALPHA', '2026-10', 20, 1, 0, 2])?->periode);
        $this->assertNull($legacy->getImportSummary()['periode']);
    }

    public function test_import_rejects_an_invalid_chosen_periode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AttendanceImport(null, null, '2026-13');
    }

    public function test_import_skips_rows_that_fill_components_their_rule_does_not_use(): void
    {
        Storage::fake('local');
        AttendanceComponent::query()->create(['code' => 'dinas_luar', 'name' => 'Dinas luar', 'unit' => 'hari', 'sort_order' => 8]);
        $this->storeSheet('kehadiran.xlsx', [
            [...self::V2_HEADINGS, 'Dinas luar [dinas_luar]'],
            // Template baru (semua kolom) dipakai untuk Oktober 2026: 0/kosong di kolom izin dkk. tidak masalah.
            ['EMP-ALPHA', 'ALPHA', '2026-10', 20, 1, 0, 2, 0, null, 0, '', null],
            // Izin & mangkir diisi untuk Oktober 2026: dilewati dengan alasan per kolom.
            ['EMP-BRAVO', 'BRAVO', '2026-10', 20, 0, 0, 1, 2, 1, 0, 0, 0],
            // Komponen yang tidak tercantum di aturan mana pun.
            ['EMP-ALPHA', 'ALPHA', '2026-11', 20, 0, 0, 0, 1, 0, 0, 0, 3],
            // November 2026 memakai izin.
            ['EMP-BRAVO', 'BRAVO', '2026-11', 20, 0, 0, 1, 2, 1, 0, 0, 0],
        ]);
        $import = new AttendanceImport;
        Excel::import($import, 'kehadiran.xlsx', 'local');

        $summary = $import->getImportSummary();
        $this->assertSame(2, $summary['importedCount']);
        // Satu kalimat untuk semua kolom yang bermasalah; izin diarahkan ke Sakit/Izin (dijumlahkan dulu, baru dikosongkan).
        $this->assertSame([
            'Aturan penilaian Oktober 2026 belum memakai Izin dan Mangkir (tanpa keterangan), tetapi kolomnya diisi. '
                .'Untuk periode ini izin dicatat di kolom Sakit/Izin: jumlahkan angka Izin ke kolom Sakit/Izin, lalu kosongkan kolom Izin. '
                .'Kosongkan kolom Mangkir (tanpa keterangan). Bila periodenya yang keliru, perbaiki periodenya.',
            'Aturan penilaian November 2026 tidak memakai Dinas luar, tetapi kolomnya diisi. Kosongkan kolom Dinas luar. Bila periodenya yang keliru, perbaiki periodenya.',
        ], array_column($summary['skippedDetails'], 'reason'));
        // Tidak ada lagi peringatan "nilai disimpan tanpa memengaruhi skor".
        $this->assertSame([], $summary['warnings']);

        $october = Attendance::query()->where('user_id', $this->alpha->id)->sole();
        $this->assertSame(['2026-10', 2], [$october->periode, (int) $october->sick_days]);
        $this->assertNull($october->component_counts);
        $november = Attendance::query()->where('user_id', $this->bravo->id)->sole();
        $this->assertSame(['izin' => 2, 'mangkir' => 1], $november->component_counts);
    }

    public function test_panel_import_uses_the_chosen_periode_and_names_it_in_the_notification(): void
    {
        $disk = $this->fakeImportDisk();
        $this->storeSheet('imports/attendances/september.xlsx', [
            self::V1_HEADINGS,
            ['EMP-ALPHA', 'ALPHA', '', 20, 1, 0, 2],
            ['EMP-BRAVO', 'BRAVO', '2026-10', 20, 0, 0, 0],
        ], $disk);
        $this->actingAs($this->admin);

        $list = Livewire::test(ListAttendances::class)
            ->call('processImport', ['file' => 'imports/attendances/september.xlsx', 'periode' => '2026-09']);

        $notification = $this->lastNotification();
        $list->assertNotified('Import Kehadiran September 2026: 1 data ditambahkan, 1 data dilewati.');
        $this->assertSame(
            '&bull; File berisi 1 baris periode Oktober 2026, bukan September 2026 yang dipilih; baris tersebut dilewati. '
                .'Bila datanya untuk Oktober 2026, import ulang file ini dan pilih Oktober 2026. '
                .'Bila datanya untuk September 2026, perbaiki atau kosongkan kolom Periode di file.',
            $notification['body'] ?? null,
        );
        $this->assertSame(['2026-09', 2], [Attendance::query()->sole()->periode, (int) Attendance::query()->sole()->sick_days]);

        // Tidak ada yang diimport: judul tetap menyebut periodenya.
        $this->storeSheet('imports/attendances/again.xlsx', [
            self::V1_HEADINGS,
            ['EMP-ALPHA', 'ALPHA', '2026-09', 20, 1, 0, 2],
        ], $disk);
        Livewire::test(ListAttendances::class)
            ->call('processImport', ['file' => 'imports/attendances/again.xlsx', 'periode' => '2026-09'])
            ->assertNotified('Import Kehadiran September 2026: Tidak ada data yang diimport. Semua data (1) sudah ada atau tidak valid.');
    }

    public function test_import_modal_asks_for_the_periode_and_confirms_an_unfinished_month(): void
    {
        $disk = $this->fakeImportDisk();
        $this->actingAs($this->admin);

        // Default bulan lalu, tanpa peringatan/konfirmasi.
        Livewire::test(ListAttendances::class)
            ->mountAction('import')
            ->assertActionDataSet(['periode' => '2026-09'])
            ->assertMountedActionModalSee('Periode yang diimport')
            ->assertMountedActionModalDontSee('Periode ini belum selesai')
            ->setActionData(['periode' => ''])
            ->callMountedAction()
            ->assertHasActionErrors(['periode' => 'required', 'file' => 'required']);

        // Bulan berjalan: peringatan dan konfirmasi wajib.
        Livewire::test(ListAttendances::class)
            ->mountAction('import')
            ->setActionData(['periode' => '2026-10'])
            ->assertMountedActionModalSee('Periode ini belum selesai')
            ->assertMountedActionModalSee('Saya yakin mengimport data kehadiran periode Oktober 2026.')
            ->callMountedAction()
            ->assertHasActionErrors(['confirm_open_periode' => 'accepted']);
        Livewire::test(ListAttendances::class)
            ->callAction('import', ['periode' => '2026-12', 'file' => [$this->uploadSheet([self::V2_HEADINGS])]])
            ->assertHasActionErrors(['confirm_open_periode']);
        $this->assertSame(0, Attendance::query()->count());

        // Dengan konfirmasi: diimport untuk periode itu.
        Livewire::test(ListAttendances::class)
            ->callAction('import', [
                'periode' => '2026-10',
                'confirm_open_periode' => true,
                'file' => [$this->uploadSheet([
                    self::V1_HEADINGS,
                    ['EMP-ALPHA', 'ALPHA', '', 21, 0, 0, 1],
                ])],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Import Kehadiran Oktober 2026: 1 data ditambahkan, 0 data dilewati.');
        $this->assertSame(['2026-10', 21, 1], [Attendance::query()->sole()->periode, (int) Attendance::query()->sole()->work_days, (int) Attendance::query()->sole()->sick_days]);

        // Bulan lalu tidak perlu konfirmasi.
        Livewire::test(ListAttendances::class)
            ->callAction('import', [
                'periode' => '2026-09',
                'file' => [$this->uploadSheet([
                    self::V1_HEADINGS,
                    ['EMP-BRAVO', 'BRAVO', '2026-09', 20, 0, 0, 0],
                ])],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Import Kehadiran September 2026: 1 data ditambahkan, 0 data dilewati.');
        $this->assertSame(2, Attendance::query()->count());
        $this->assertSame([], Storage::disk($disk)->allFiles('imports/attendances'));
    }

    // ---------------------------------------------------------------------
    // API
    // ---------------------------------------------------------------------

    public function test_api_rejects_components_the_rule_of_the_periode_does_not_use(): void
    {
        Sanctum::actingAs($this->admin);
        $payload = ['user_id' => $this->alpha->id, 'periode' => '2026-10', 'work_days' => 20];

        $this->postJson('/api/v1/attendances', $payload + ['components' => ['izin' => 1, 'mangkir' => 0, 'late_less_30' => 2]])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['components.izin' => [self::IZIN_OCTOBER_API_ERROR]]);
        $this->assertSame(0, Attendance::query()->count());

        // 0 untuk komponen yang tidak dipakai, dan field/komponen lama, selalu diterima.
        $created = $this->postJson('/api/v1/attendances', $payload + ['sick_days' => 3, 'components' => ['izin' => 0, 'late_more_30' => 1]])
            ->assertCreated()
            ->json('data');
        $attendance = Attendance::query()->findOrFail($created['id']);
        $this->assertSame([3, 1], [(int) $attendance->sick_days, (int) $attendance->late_more_30]);
        $this->assertNull($attendance->component_counts);

        // Update tanpa periode memakai periode data yang diubah.
        $this->putJson('/api/v1/attendances/'.$attendance->id, ['components' => ['mangkir' => 2]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['components.mangkir']);
        $this->putJson('/api/v1/attendances/'.$attendance->id, ['sick_days' => 4])->assertOk();

        // Pindah ke periode yang memakai izin: boleh; kembali ke periode lama dengan izin: ditolak.
        $this->putJson('/api/v1/attendances/'.$attendance->id, ['periode' => '2026-11', 'components' => ['izin' => 2]])
            ->assertOk()
            ->assertJsonPath('data.components.izin.count', 2);
        $errors = $this->putJson('/api/v1/attendances/'.$attendance->id, ['periode' => '2026-10', 'components' => ['izin' => 1]])
            ->assertStatus(422)
            ->json('errors');
        $this->assertSame(['components.izin' => [self::IZIN_OCTOBER_API_ERROR]], $errors);
        $this->assertSame(['2026-11', ['izin' => 2]], [$attendance->refresh()->periode, $attendance->component_counts]);

        // Periode tidak valid tetap dilaporkan lewat periode, bukan komponennya.
        $this->postJson('/api/v1/attendances', ['periode' => '1900-01'] + $payload + ['components' => ['izin' => 1]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['periode'])
            ->assertJsonMissingValidationErrors(['components.izin']);
    }

    public function test_api_update_does_not_reveal_the_periode_of_a_record_the_user_cannot_change(): void
    {
        $attendance = Attendance::query()->create(['user_id' => $this->alpha->id, 'periode' => '2026-10', 'work_days' => 20]);
        Sanctum::actingAs($this->bravo);

        $this->putJson('/api/v1/attendances/'.$attendance->id, ['components' => ['izin' => 1]])->assertForbidden();
        $this->assertNull($attendance->refresh()->component_counts);
    }

    // ---------------------------------------------------------------------
    // Tindak lanjut review: pindah periode, pesan izin, ringkasan import, tabel, modal import
    // ---------------------------------------------------------------------

    public function test_api_update_does_not_move_stored_counts_into_a_periode_whose_rule_does_not_use_them(): void
    {
        Sanctum::actingAs($this->admin);
        $attendance = Attendance::query()->create([
            'user_id' => $this->alpha->id, 'periode' => '2026-11', 'work_days' => 20, 'sick_days' => 1,
            'component_counts' => ['izin' => 3, 'mangkir' => 1],
        ]);

        // Hanya periode yang dikirim: hitungan tersimpan yang tidak dipakai aturan Oktober ditolak pada 'periode'.
        $this->putJson('/api/v1/attendances/'.$attendance->id, ['periode' => '2026-10'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['periode' => [
                'Aturan penilaian Oktober 2026 belum memakai Izin dan Mangkir (tanpa keterangan), tetapi data ini menyimpan Izin 3 hari dan Mangkir (tanpa keterangan) 1 hari. '
                    .'Untuk periode ini izin dicatat di sick_days (Sakit/Izin): tambahkan jumlah izin ke sick_days dan kirim components.izin 0. '
                    .'Kirim 0 untuk components.mangkir. Bila periodenya yang keliru, perbaiki periodenya.',
            ]]);
        // Komponen yang ikut dikirim diperiksa per kunci components.*; yang tidak dikirim tetap disebut di 'periode'.
        $this->putJson('/api/v1/attendances/'.$attendance->id, ['periode' => '2026-10', 'components' => ['izin' => 0]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['periode'])
            ->assertJsonMissingValidationErrors(['components.izin']);
        $this->assertSame(['2026-11', ['izin' => 3, 'mangkir' => 1]], [$attendance->refresh()->periode, $attendance->component_counts]);

        // Izin dijumlahkan ke sick_days dan kedua komponen dikirim 0 bersama periode baru: berhasil.
        $this->putJson('/api/v1/attendances/'.$attendance->id, ['periode' => '2026-10', 'sick_days' => 4, 'components' => ['izin' => 0, 'mangkir' => 0]])
            ->assertOk();
        $attendance->refresh();
        $this->assertSame(['2026-10', 4, null], [$attendance->periode, (int) $attendance->sick_days, $attendance->component_counts]);

        // Data lama yang periodenya tidak diganti (termasuk periode sama yang dikirim ulang) tetap bisa diubah.
        $legacy = Attendance::query()->create([
            'user_id' => $this->bravo->id, 'periode' => '2026-10', 'work_days' => 20, 'component_counts' => ['izin' => 2],
        ]);
        $this->putJson('/api/v1/attendances/'.$legacy->id, ['work_days' => 21])->assertOk();
        $this->putJson('/api/v1/attendances/'.$legacy->id, ['periode' => '2026-10-01', 'late_less_30' => 1])->assertOk();
        $this->putJson('/api/v1/attendances/'.$legacy->id, ['periode' => '2026-09'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['periode']);
        $legacy->refresh();
        $this->assertSame(['2026-10', 21, 1, ['izin' => 2]], [$legacy->periode, (int) $legacy->work_days, (int) $legacy->late_less_30, $legacy->component_counts]);
    }

    public function test_create_form_tells_hr_when_a_periode_change_clears_typed_counts(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateAttendance::class)
            ->fillForm(['periode' => '2026-11', 'component_counts.izin' => 2, 'component_counts.mangkir' => 1])
            ->fillForm(['periode' => '2026-10'])
            ->assertFormSet(['component_counts.izin' => 0, 'component_counts.mangkir' => 0]);

        $notification = $this->lastNotification();
        $this->assertSame('Isian komponen dikosongkan', $notification['title'] ?? null);
        $this->assertSame(
            'Izin 2 hari dan Mangkir (tanpa keterangan) 1 hari dikosongkan. Aturan penilaian Oktober 2026 belum memakai Izin dan Mangkir (tanpa keterangan). '
                .'Untuk periode ini izin dijumlahkan ke Sakit/Izin.',
            $notification['body'] ?? null,
        );
        $this->assertSame('warning', $notification['status'] ?? null);

        // Tidak ada angka yang dikosongkan: tidak ada pemberitahuan.
        session()->forget(['filament.notifications', 'filament.claimed_notifications']);
        Livewire::test(CreateAttendance::class)
            ->fillForm(['periode' => '2026-11'])
            ->fillForm(['periode' => '2026-10']);
        $this->assertSame([], $this->lastNotification());
    }

    public function test_izin_and_unlisted_components_explain_why_they_cannot_be_filled(): void
    {
        AttendanceComponent::query()->create(['code' => 'dinas_luar', 'name' => 'Dinas luar', 'unit' => 'hari', 'description' => 'Tugas di luar kantor.', 'sort_order' => 8]);
        $this->actingAs($this->admin);

        Livewire::test(CreateAttendance::class)
            // September 2026: isian Izin menunjuk ke Sakit/Izin.
            ->assertFormFieldDisabled('component_counts.izin')
            ->assertSee(AttendanceCountInput::IZIN_IN_SICK_HELPER)
            // Komponen yang belum tercantum di aturan mana pun: alasan nonaktifnya disebut setelah deskripsinya.
            ->assertFormFieldDisabled('component_counts.dinas_luar')
            ->assertSee('Tugas di luar kantor. '.AttendanceCountInput::UNLISTED_COMPONENT_HELPER)
            ->fillForm(['periode' => '2026-11'])
            ->assertFormFieldEnabled('component_counts.izin')
            ->assertDontSee(AttendanceCountInput::IZIN_IN_SICK_HELPER)
            ->assertFormFieldDisabled('component_counts.dinas_luar')
            ->assertSee(AttendanceCountInput::UNLISTED_COMPONENT_HELPER);
    }

    public function test_table_marks_the_sick_column_of_older_periodes_as_sakit_izin(): void
    {
        $october = Attendance::query()->create(['user_id' => $this->alpha->id, 'periode' => '2026-10', 'work_days' => 20, 'sick_days' => 2]);
        $november = Attendance::query()->create(['user_id' => $this->bravo->id, 'periode' => '2026-11', 'work_days' => 20, 'sick_days' => 1]);
        $sick = AttendanceCountInput::inputComponents()->get('sick_days');
        $this->assertNotNull($sick);

        $this->assertSame('Sakit/Izin: untuk periode Oktober 2026 izin dicatat bersama sakit.', AttendanceResource::componentCellTooltip($sick, $october));
        $this->assertNull(AttendanceResource::componentCellTooltip($sick, $november));
        $this->assertNull(AttendanceResource::componentCellTooltip(AttendanceCountInput::inputComponents()->get('late_less_30'), $october));

        $this->actingAs($this->admin);
        $column = Livewire::test(ListAttendances::class)
            ->loadTable()
            ->assertTableColumnExists('sick_days', fn ($column): bool => $column->getLabel() === 'Sakit')
            ->assertCanRenderTableColumn('sick_days')
            ->instance()->getTable()->getColumn('sick_days');
        $this->assertNotNull($column);
        $this->assertSame('Sebelum November 2026 kolom ini berisi sakit + izin (Sakit/Izin).', $column->getHeaderTooltip());
        $this->assertSame('Sakit/Izin: untuk periode Oktober 2026 izin dicatat bersama sakit.', $column->record($october)->getTooltip());
        $this->assertNull($column->record($november)->getTooltip());
    }

    public function test_import_of_a_file_for_another_month_gets_one_summary_and_a_clear_title(): void
    {
        $disk = $this->fakeImportDisk();
        $this->storeSheet('imports/attendances/agustus.xlsx', [
            self::V1_HEADINGS,
            ['EMP-ALPHA', 'ALPHA', '2026-08', 20, 1, 0, 2],
            ['EMP-BRAVO', 'BRAVO', '2026-08', 21, 0, 0, 0],
        ], $disk);
        $this->actingAs($this->admin);

        $list = Livewire::test(ListAttendances::class)
            ->call('processImport', ['file' => 'imports/attendances/agustus.xlsx', 'periode' => '2026-09']);
        $this->assertSame(
            '&bull; File berisi 2 baris periode Agustus 2026, bukan September 2026 yang dipilih; baris tersebut dilewati. '
                .'Bila datanya untuk Agustus 2026, import ulang file ini dan pilih Agustus 2026. '
                .'Bila datanya untuk September 2026, perbaiki atau kosongkan kolom Periode di file.',
            $this->lastNotification()['body'] ?? null,
        );
        $list->assertNotified('Import Kehadiran September 2026: Tidak ada data September 2026 di file. Periksa keterangan di bawah.');
        $this->assertSame(0, Attendance::query()->count());

        // Campuran: data periode itu yang sudah ada disebut terpisah dari baris periode lain.
        Attendance::query()->create(['user_id' => $this->alpha->id, 'periode' => '2026-09', 'work_days' => 20]);
        $this->storeSheet('imports/attendances/campuran.xlsx', [
            self::V1_HEADINGS,
            ['EMP-ALPHA', 'ALPHA', '2026-09', 20, 1, 0, 2],
            ['EMP-BRAVO', 'BRAVO', '2026-08', 21, 0, 0, 0],
        ], $disk);
        $list = Livewire::test(ListAttendances::class)
            ->call('processImport', ['file' => 'imports/attendances/campuran.xlsx', 'periode' => '2026-09']);
        $body = (string) ($this->lastNotification()['body'] ?? '');
        $list->assertNotified('Import Kehadiran September 2026: Tidak ada data yang diimport. 1 data September 2026 sudah ada atau tidak valid, 1 baris periode lain dilewati.');
        $this->assertStringContainsString('File berisi 1 baris periode Agustus 2026, bukan September 2026 yang dipilih', $body);
        $this->assertStringContainsString('EMP-ALPHA / ALPHA (2026-09): Data kehadiran karyawan ini untuk periode tersebut sudah ada.', $body);
        $this->assertStringNotContainsString('berbeda dengan periode yang dipilih', $body);
    }

    public function test_import_modal_wording_for_a_future_month_and_confirmation_is_per_periode(): void
    {
        $this->fakeImportDisk();
        $this->actingAs($this->admin);

        Livewire::test(ListAttendances::class)
            ->mountAction('import')
            ->setActionData(['periode' => '2026-10'])
            ->assertMountedActionModalSee('Periode ini belum selesai')
            ->assertMountedActionModalSee('Oktober 2026 masih berjalan (belum selesai). Data kehadiran biasanya direkap untuk bulan yang sudah selesai (September 2026).')
            ->setActionData(['periode' => '2027-02'])
            ->assertMountedActionModalSee('Periode ini belum dimulai')
            ->assertMountedActionModalSee('Februari 2027 belum dimulai. Data kehadiran biasanya direkap untuk bulan yang sudah selesai (September 2026).')
            ->assertMountedActionModalDontSee('masih berjalan');

        // Konfirmasi untuk satu periode tidak terbawa ke periode lain.
        Livewire::test(ListAttendances::class)
            ->mountAction('import')
            ->setActionData(['periode' => '2026-10', 'confirm_open_periode' => true])
            ->assertActionDataSet(['confirm_open_periode' => true])
            ->setActionData(['periode' => '2026-12'])
            ->assertActionDataSet(['confirm_open_periode' => false])
            ->setActionData(['file' => [$this->uploadSheet([self::V2_HEADINGS, ['EMP-ALPHA', 'ALPHA', '', 20, 0, 0, 0, 0, 0, 0, 0]])]])
            ->callMountedAction()
            ->assertHasActionErrors(['confirm_open_periode' => 'accepted']);
        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_export_then_import_restores_rows_without_counts_their_rule_does_not_use(): void
    {
        Storage::fake('local');
        Attendance::query()->create(['user_id' => $this->alpha->id, 'periode' => '2026-10', 'work_days' => 21, 'late_less_30' => 1, 'sick_days' => 3]);
        Attendance::query()->create(['user_id' => $this->bravo->id, 'periode' => '2026-11', 'work_days' => 20, 'late_more_30' => 2, 'sick_days' => 1, 'component_counts' => ['cuti_tahunan' => 1, 'izin' => 2]]);
        Attendance::query()->create(['user_id' => $this->alpha->id, 'periode' => '2026-12', 'work_days' => 19, 'component_counts' => ['mangkir' => 1]]);
        $snapshot = fn (): array => Attendance::query()->orderBy('user_id')->orderBy('periode')->get()
            ->map(fn (Attendance $attendance): array => [
                (int) $attendance->user_id, $attendance->periode, (int) $attendance->work_days, (int) $attendance->late_less_30,
                (int) $attendance->late_more_30, (int) $attendance->sick_days, $attendance->component_counts,
            ])
            ->all();
        $before = $snapshot();

        Excel::store(new AttendanceExport, 'export.xlsx', 'local');
        Attendance::query()->forceDelete();
        $import = new AttendanceImport;
        Excel::import($import, 'export.xlsx', 'local');

        $summary = $import->getImportSummary();
        $this->assertSame([3, 0, []], [$summary['importedCount'], $summary['skippedCount'], $summary['skippedDetails']]);
        $this->assertSame($before, $snapshot());
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
     * File xlsx yang diunggah lewat modal Import.
     *
     * @param  list<list<mixed>>  $rows
     */
    private function uploadSheet(array $rows): UploadedFile
    {
        Storage::fake('uploads');
        $this->storeSheet('upload.xlsx', $rows, 'uploads');

        return UploadedFile::fake()->createWithContent('kehadiran.xlsx', (string) Storage::disk('uploads')->get('upload.xlsx'));
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
}
