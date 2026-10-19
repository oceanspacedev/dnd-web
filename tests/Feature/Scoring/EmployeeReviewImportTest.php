<?php

namespace Tests\Feature\Scoring;

use App\Exports\EmployeeReviewImportTemplateExport;
use App\Filament\Resources\EmployeeReviews\Pages\ListEmployeeReviews;
use App\Imports\EmployeeReviewImport;
use App\Models\EmployeeReview;
use App\Policies\EmployeeReviewPolicy;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Import Penilaian: periode tidak bergeser, baris template yang belum dinilai tidak menjadi penilaian 0, dan karyawan
 * dicari tanpa salah orang (nama ganda, ID karyawan numerik, karyawan di luar cakupan).
 */
class EmployeeReviewImportTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private const NOT_FOUND_REASON = 'Karyawan tidak ditemukan. Periksa ID karyawan dan Nama Lengkap.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();
        Date::setTestNow('2026-10-31 10:00:00');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_periode_is_parsed_strictly_without_rolling_over_to_the_next_month(): void
    {
        $area = $this->fixtureArea('COO');
        $employee = $this->fixtureUser('reviewed', 'STAFF', $area, null, ['employee_id' => 'EMP-REV']);
        $row = fn (mixed $periode): array => [
            'id_karyawan' => 'EMP-REV', 'nama_lengkap' => 'REVIEWED', 'periode' => $periode,
            'responsiveness' => 4, 'problem_solver' => 4, 'helpfulness' => 4, 'initiative' => 4,
        ];

        $import = new EmployeeReviewImport;

        // Teks 'YYYY-MM' pada tanggal 31: bukan '31 Februari' yang bergeser ke Maret.
        $this->assertSame('2026-02', $import->model($row('2026-02'))?->periode);
        $this->assertSame('2026-09', $import->model($row(ExcelDate::PHPToExcel(new DateTimeImmutable('2026-09-01'))))?->periode);
        $this->assertSame('2026-04', $import->model($row('2026-4'))?->periode);

        // Bulan 13, tanggal yang tidak ada (31 September), dan tanggal Excel 0 ditolak, bukan disimpan di bulan lain.
        $this->assertNull($import->model($row('2026-13')));
        $this->assertNull($import->model($row('31/09/26')));
        $this->assertNull($import->model($row(0)));
        $this->assertNull($import->model($row('')));

        $summary = $import->getImportSummary();
        $this->assertSame(3, $summary['importedCount']);
        $reasons = array_column($summary['skippedDetails'], 'reason');
        $this->assertCount(4, $reasons);
        $this->assertSame('Periode "2026-13" tidak dikenali. Gunakan format YYYY-MM (mis. 2026-11).', $reasons[0]);
        $this->assertSame('Periode "31/09/26" tidak dikenali. Gunakan format YYYY-MM (mis. 2026-11).', $reasons[1]);
        $this->assertStringStartsWith('Periode "0" terbaca sebagai ', $reasons[2]);
        $this->assertStringContainsString('di luar rentang tahun 2000-2100', $reasons[2]);
        $this->assertSame('Periode kosong. Isi kolom Periode dengan format YYYY-MM (mis. 2026-11).', $reasons[3]);
        $this->assertSame($employee->id, (int) $import->model($row('2026-10'))?->user_id);
    }

    public function test_unfilled_template_rows_are_ignored_instead_of_becoming_zero_point_reviews(): void
    {
        $disk = (string) (config('filament.default_filesystem_disk') ?: config('filesystems.default', 'local'));
        Storage::fake($disk);
        $area = $this->fixtureArea('COO');
        $super = $this->fixtureGlobalAdmin('boss', $area);
        $manager = $this->fixtureUser('manager', 'MANAGER', $area, null, ['employee_id' => 'EMP-MGR']);
        $filled = $this->fixtureUser('staff_a', 'STAFF', $area, $manager, ['employee_id' => 'EMP-A']);
        $unfilled = $this->fixtureUser('staff_b', 'STAFF', $area, $manager, ['employee_id' => 'EMP-B']);
        $this->resetAccessMemos();

        // Template September (periode berupa teks '2026-09') yang baru diimport 31 Oktober.
        Date::setTestNow('2026-09-15 10:00:00');
        $template = Excel::raw(new EmployeeReviewImportTemplateExport(EmployeeReviewPolicy::writableUserIds($super)), ExcelFormat::XLSX);
        Date::setTestNow('2026-10-31 10:00:00');

        Storage::disk($disk)->put('template.xlsx', $template);
        $spreadsheet = IOFactory::load(Storage::disk($disk)->path('template.xlsx'));
        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame(5, $sheet->getHighestDataRow(), 'Template super admin: judul + 4 karyawan.');
        foreach (range(2, $sheet->getHighestDataRow()) as $line) {
            if ($sheet->getCell("A{$line}")->getValue() === 'EMP-A') {
                $this->assertSame('2026-09', $sheet->getCell("C{$line}")->getValue());
                $sheet->fromArray([[4, 4, 4, 4]], null, "D{$line}");
            }
        }
        Storage::disk($disk)->makeDirectory('imports/employee-reviews');
        (new Xlsx($spreadsheet))->save(Storage::disk($disk)->path('imports/employee-reviews/review.xlsx'));

        $this->actingAs($super);
        Livewire::actingAs($super);
        Livewire::test(ListEmployeeReviews::class)
            ->call('processImport', ['file' => 'imports/employee-reviews/review.xlsx'])
            ->assertNotified('Data Penilaian berhasil diimport. 1 data ditambahkan, 0 data dilewati. 3 baris template belum dinilai (diabaikan).');

        $this->assertSame(
            [[$filled->id, '2026-09', 4, 4, 4, 4]],
            EmployeeReview::query()->get()->map(fn (EmployeeReview $review): array => [
                (int) $review->user_id, $review->periode,
                (int) $review->responsiveness, (int) $review->problem_solver, (int) $review->helpfulness, (int) $review->initiative,
            ])->all(),
        );

        // Atasan masih bisa mengimport penilaian sebenarnya untuk karyawan yang barisnya tadi kosong.
        $managerImport = new EmployeeReviewImport(EmployeeReviewPolicy::writableUserIds($manager));
        $review = $managerImport->model([
            'id_karyawan' => 'EMP-B', 'nama_lengkap' => $unfilled->nama_lengkap, 'periode' => '2026-09',
            'responsiveness' => 5, 'problem_solver' => 5, 'helpfulness' => 5, 'initiative' => 5,
        ]);
        $this->assertSame($unfilled->id, (int) $review?->user_id);

        // Aspek kosong pada baris yang sudah dinilai sebagian tetap 0; baris tanpa ID dan nama tidak dihitung.
        $partial = new EmployeeReviewImport;
        $this->assertSame(0, (int) $partial->model([
            'id_karyawan' => 'EMP-MGR', 'nama_lengkap' => 'MANAGER', 'periode' => '2026-09',
            'responsiveness' => 3, 'problem_solver' => '', 'helpfulness' => null, 'initiative' => ' ',
        ])?->problem_solver);
        $this->assertNull($partial->model(['id_karyawan' => null, 'nama_lengkap' => null, 'periode' => null]));
        $this->assertSame(0, $partial->getImportSummary()['unfilledCount']);
    }

    public function test_employees_are_resolved_without_picking_the_wrong_person(): void
    {
        $coo = $this->fixtureArea('COO');
        $uls = $this->fixtureArea('ULS');
        $first = $this->fixtureUser('same_1', 'STAFF', $coo, null, ['nama_lengkap' => 'SAME NAME']);
        $second = $this->fixtureUser('same_2', 'STAFF', $coo, null, ['nama_lengkap' => 'SAME NAME']);
        $numericA = $this->fixtureUser('numeric_a', 'STAFF', $coo, null, ['employee_id' => '25091302', 'nama_lengkap' => 'NUMERIC A']);
        $numericB = $this->fixtureUser('numeric_b', 'STAFF', $coo, null, ['employee_id' => '26031301', 'nama_lengkap' => 'NUMERIC B']);
        $row = fn (mixed $employeeId, string $name, string $periode = '2026-09'): array => [
            'id_karyawan' => $employeeId, 'nama_lengkap' => $name, 'periode' => $periode,
            'responsiveness' => 5, 'problem_solver' => 5, 'helpfulness' => 5, 'initiative' => 5,
        ];

        $import = new EmployeeReviewImport;

        // Nama yang dipakai dua karyawan tanpa ID karyawan: ditolak, bukan masuk ke karyawan terakhir.
        $this->assertNull($import->model($row('', 'SAME NAME')));
        $this->assertSame(
            'Nama "SAME NAME" dipakai lebih dari satu karyawan, dan ID karyawan untuk nama ini belum diisi di data karyawan. '
            .'Lengkapi ID karyawan di data karyawan (menu Karyawan / Tim Saya) lalu isi kolom ID karyawan di file, atau isi penilaiannya lewat form Penilaian.',
            $import->getImportSummary()['skippedDetails'][0]['reason'],
        );

        // ID karyawan numerik (angka dari Excel) menunjuk pemiliknya, bukan karyawan ke-n hasil penomoran ulang.
        $this->assertSame($numericA->id, (int) $import->model($row(25091302, 'NAMA SALAH KETIK'))?->user_id);
        $this->assertSame($numericB->id, (int) $import->model($row(26031301.0, 'NUMERIC B'))?->user_id);
        $this->assertNull($import->model($row('1', 'NOBODY')));
        $this->assertSame(self::NOT_FOUND_REASON, collect($import->getImportSummary()['skippedDetails'])->last()['reason']);

        // Setelah ID dilengkapi, ID memilih karyawannya; tanpa ID nama tetap ditolak.
        $first->forceFill(['employee_id' => 'EMP-SAME-1'])->save();
        $withId = new EmployeeReviewImport;
        $this->assertSame($first->id, (int) $withId->model($row('EMP-SAME-1', 'SAME NAME'))?->user_id);
        $this->assertNull($withId->model($row('', 'SAME NAME')));
        $this->assertStringStartsWith(
            'Nama "SAME NAME" dipakai lebih dari satu karyawan. Isi kolom ID karyawan agar penilaian tidak masuk ke karyawan lain.',
            $withId->getImportSummary()['skippedDetails'][0]['reason'],
        );

        // Admin per badan usaha: karyawan di luar cakupan diperlakukan seperti tidak ada (dengan alasannya), dan ID
        // badan usaha lain tidak jatuh ke karyawan bernama sama di cakupannya.
        $ojan = $this->fixtureScopedAdmin('ojan', $coo, [$uls]);
        $ulsTwin = $this->fixtureUser('uls_twin', 'STAFF', $uls, null, ['employee_id' => 'EMP-ULS', 'nama_lengkap' => 'NUMERIC A']);
        $this->resetAccessMemos();
        $scoped = new EmployeeReviewImport(EmployeeReviewPolicy::writableUserIds($ojan));
        $this->assertNull($scoped->model($row(25091302, 'NUMERIC A')));
        $this->assertNull($scoped->model($row('', 'NUMERIC B')));
        $this->assertSame([self::NOT_FOUND_REASON, self::NOT_FOUND_REASON], array_column($scoped->getImportSummary()['skippedDetails'], 'reason'));
        $this->assertSame($ulsTwin->id, (int) $scoped->model($row('EMP-ULS', 'NUMERIC A'))?->user_id);

        $this->assertSame(0, EmployeeReview::query()->count());
        $this->assertNotSame($first->id, $second->id);
    }
}
