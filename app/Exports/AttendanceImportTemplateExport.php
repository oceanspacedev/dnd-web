<?php

namespace App\Exports;

use App\Models\User;
use App\Services\ScoringRuleService;
use App\Support\Periode;
use App\Support\Scoring\AttendanceComponentData;
use App\Support\Scoring\AttendanceCountInput;
use App\Support\Scoring\ScoringRuleData;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Template import kehadiran untuk satu periode (default bulan lalu). Kolom 1-7 tetap seperti template lama
 * (kolom 5-7 = tiga komponen lama; kolom 7 berjudul "Sakit/Izin" bila versi aturan periode itu belum memakai
 * komponen izin, selain itu "Sakit"), lalu satu kolom per komponen lain yang dipakai versi aturan periode itu,
 * berjudul "Nama [kode]" (lihat AttendanceCountInput::spreadsheetComponents()). Baris karyawan sudah terisi
 * periodenya. AttendanceImport memetakan kolom lewat judulnya (kode komponen di dalam kurung siku), jadi komponen
 * baru di aturan langsung ikut tanpa perubahan kode, nama komponen boleh diubah tanpa membuat template lama
 * terbaca 0, dan template lama (semua komponen, judul "Sakit or Izin") tetap bisa diimport.
 */
class AttendanceImportTemplateExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithTitle
{
    /** Judul kolom 1-7 template lama (tidak diubah agar file/makro yang membaca judul ini tetap jalan). */
    public const LEGACY_HEADINGS = [
        'ID karyawan',
        'Nama Lengkap',
        'Periode',
        'Hari Kerja',
        'Late Less 30 min',
        'Late More 30 min',
        'Sakit',
    ];

    /** Kolom pertama hitungan komponen (kolom 5 = E). */
    public const FIRST_COMPONENT_COLUMN = 5;

    /** Kolom Periode (kolom 3 = C). */
    private const PERIODE_COLUMN = 3;

    /** Indeks judul kolom 7 (sick_days) di LEGACY_HEADINGS. */
    private const SICK_HEADING_INDEX = 6;

    /** @var Collection<string, AttendanceComponentData>|null */
    private ?Collection $components = null;

    /** Periode 'Y-m' yang diisikan ke setiap baris dan menentukan kolom komponen. */
    private readonly string $periode;

    /**
     * @param  array<int>|null  $userIds  Karyawan yang dimasukkan ke template. null = seluruh karyawan;
     *                                    pemanggil HTTP/Livewire mengisi cakupan actor (AttendancePolicy::visibleUserIds).
     * @param  string|null  $periode  Periode template ('YYYY-MM'); null = bulan lalu (Periode::previous()).
     *
     * @throws InvalidArgumentException bila periode tidak terbaca atau di luar rentang tahun yang diizinkan.
     */
    public function __construct(private readonly ?array $userIds = null, ?string $periode = null)
    {
        $normalized = AttendanceCountInput::periode($periode ?? Periode::previous());

        if ($normalized === null) {
            throw new InvalidArgumentException((string) AttendanceCountInput::periodeError($periode));
        }

        $this->periode = $normalized;
    }

    public function periode(): string
    {
        return $this->periode;
    }

    /**
     * Nama file unduhan, memuat periodenya, mis. attendance_import_template_2026-10.xlsx.
     */
    public static function fileName(string $periode): string
    {
        return "attendance_import_template_{$periode}.xlsx";
    }

    /**
     * Baris karyawan dengan periode template.
     */
    public function collection(): Enumerable
    {
        $periode = $this->periode;

        return User::whereNull('deleted_at')
            ->when($this->userIds !== null, fn ($query) => $query->whereIn('id', $this->userIds))
            ->select('employee_id', 'nama_lengkap')
            ->get()
            ->filter(function ($user) {
                return $user->nama_lengkap !== 'ADMIN'; // Exclude users with 'nama_lengkap' as 'ADMIN'
            })
            ->map(function ($user) use ($periode) {
                return [
                    'employee_id' => $user->employee_id,
                    'nama_lengkap' => $user->nama_lengkap,
                    'periode' => $periode,
                    'work_days' => '',
                    'late_less_30' => '',
                    'late_more_30' => '',
                    'sick_days' => '',
                ];
            });
    }

    /**
     * Set headings for the columns in the template.
     */
    public function headings(): array
    {
        return self::headingsFor(self::legacyHeadings($this->rule()), $this->components());
    }

    /**
     * Tujuh judul kolom lama untuk versi aturan: kolom 7 "Sakit/Izin" bila izin dicatat bersama sakit, selain
     * itu "Sakit". Import mengenali keduanya (dan judul lama "Sakit or Izin").
     *
     * @return list<string>
     */
    public static function legacyHeadings(ScoringRuleData $rule): array
    {
        $headings = self::LEGACY_HEADINGS;
        $headings[self::SICK_HEADING_INDEX] = AttendanceCountInput::recordsIzinInSick($rule)
            ? AttendanceCountInput::SICK_IZIN_LABEL
            : self::LEGACY_HEADINGS[self::SICK_HEADING_INDEX];

        return $headings;
    }

    /**
     * Satu sel kosong per komponen setelah kolom dasar.
     *
     * @param  array{employee_id: mixed, nama_lengkap: mixed, periode: string, work_days: string, late_less_30: string, late_more_30: string, sick_days: string}  $row
     */
    public function map(mixed $row): array
    {
        return [
            $row['employee_id'],
            $row['nama_lengkap'],
            $row['periode'],
            $row['work_days'],
            ...array_fill(0, $this->components()->count(), ''),
        ];
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $rule = $this->rule();

                self::annotateComponentHeadings($sheet, $this->components(), $rule);

                // Catatan pada judul Periode: template ini khusus periode tersebut (kolom komponen ikut aturannya).
                $comment = $sheet->getComment(Coordinate::stringFromColumnIndex(self::PERIODE_COLUMN).'1');
                $comment->getText()->createTextRun('Template periode '.Periode::label($this->periode)." ({$this->periode}), aturan penilaian \"{$rule->name}\".\n"
                    .'Kolom komponen mengikuti aturan periode ini. Untuk periode lain, unduh template periode tersebut.');
                $comment->setWidth('240pt');
                $comment->setHeight('80pt');
            },
        ];
    }

    /**
     * Judul kolom: tujuh judul lama, lalu "Nama [kode]" untuk komponen selain tiga komponen lama.
     *
     * @param  list<string>  $legacyHeadings
     * @param  Collection<string, AttendanceComponentData>  $components  tiga komponen lama di depan
     * @return list<string>
     */
    public static function headingsFor(array $legacyHeadings, Collection $components): array
    {
        $headings = $legacyHeadings;

        foreach ($components as $code => $component) {
            if (AttendanceComponentData::legacyColumnFor($code) === null) {
                $headings[] = AttendanceCountInput::spreadsheetHeading($component);
            }
        }

        return $headings;
    }

    /**
     * Catatan (comment) pada judul kolom komponen: nama komponen di master dan keterangannya, mis. kolom
     * "Sakit" (dulu "Sakit or Izin") yang sejak November 2026 tidak lagi berisi izin; izin diisi di kolom Izin.
     * Dengan $rule (template satu periode), nama & keterangan mengikuti versi aturan itu (mis. "Sakit/Izin").
     *
     * @param  Collection<string, AttendanceComponentData>  $components  urutan kolom mulai kolom 5
     */
    public static function annotateComponentHeadings(Worksheet $sheet, Collection $components, ?ScoringRuleData $rule = null): void
    {
        $column = self::FIRST_COMPONENT_COLUMN;

        foreach ($components as $code => $component) {
            $isLegacy = AttendanceComponentData::legacyColumnFor($code) !== null;
            $description = AttendanceCountInput::componentHelperText($component, $rule);

            // Judul "Nama [kode]" sudah memuat nama; catatan hanya perlu bila ada keterangan.
            if ($isLegacy || filled($description)) {
                $text = AttendanceCountInput::componentLabel($component, $rule)." ({$component->unit})"
                    .(filled($description) ? "\n{$description}" : '')
                    .($component->isActive ? '' : "\nKomponen nonaktif.");

                $comment = $sheet->getComment(Coordinate::stringFromColumnIndex($column).'1');
                $comment->getText()->createTextRun($text);
                $comment->setWidth('240pt');
                $comment->setHeight('80pt');
            }

            $column++;
        }
    }

    /**
     * @return Collection<string, AttendanceComponentData>
     */
    private function components(): Collection
    {
        return $this->components ??= AttendanceCountInput::spreadsheetComponents($this->rule());
    }

    private function rule(): ScoringRuleData
    {
        return ScoringRuleService::forPeriode($this->periode);
    }

    /**
     * Nama sheet template; muncul di pesan import bila HR menambah sheet lain (mis. catatan).
     */
    public function title(): string
    {
        return 'Kehadiran';
    }
}
