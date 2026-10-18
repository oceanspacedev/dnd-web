<?php

namespace App\Exports;

use App\Models\Attendance;
use App\Support\Scoring\AttendanceComponentData;
use App\Support\Scoring\AttendanceCountInput;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Export data kehadiran. Kolom 1-7 tetap seperti export lama; hanya judul kolom 7 yang sengaja diubah dari
 * "Sakit or Izin" menjadi "Sakit" karena sejak November 2026 izin punya kolom sendiri. Lalu satu kolom per
 * komponen kehadiran lain berjudul "Nama [kode]" (AttendanceCountInput::exportComponents(), termasuk komponen
 * nonaktif agar tidak ada hitungan yang hilang). Susunan kolomnya dikenali AttendanceImport, sehingga file
 * hasil export bisa diimport kembali. Nilai 0 ditulis sebagai 0, bukan sel kosong (WithStrictNullComparison), agar
 * isi file sama dengan tabel Kehadiran dan Hari Kerja 0 tidak terbaca sebagai baris template yang belum diisi.
 */
class AttendanceExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison, WithTitle
{
    /** Judul kolom 1-7 (urutan sama dengan export lama; kolom 7 dulu "Sakit or Izin", import menerima keduanya). */
    public const LEGACY_HEADINGS = [
        'ID Karyawan',
        'Nama Lengkap',
        'Periode',
        'Hari Kerja',
        'Late Less 30 min',
        'Late More 30 min',
        'Sakit',
    ];

    /** @var Collection<string, AttendanceComponentData>|null */
    private ?Collection $components = null;

    /**
     * @param  array<int>|null  $userIds  Pemilik data yang boleh diekspor. null = seluruh karyawan;
     *                                    pemanggil HTTP/Livewire mengisi cakupan actor (AttendancePolicy::visibleUserIds).
     */
    public function __construct(private readonly ?array $userIds = null) {}

    /**
     * Mengambil data koleksi untuk ekspor
     *
     * @return Collection
     */
    public function collection(): Enumerable
    {
        $codes = $this->components()->keys()->all();

        // Ambil data attendance dalam cakupan (termasuk milik karyawan yang sudah dihapus)
        return Attendance::with('user') // Pastikan relasi dengan model User tersedia
            ->when($this->userIds !== null, fn ($query) => $query->whereIn('user_id', $this->userIds))
            ->get()
            ->map(function (Attendance $attendance) use ($codes) {
                return [
                    'employee_id' => $attendance->user->employee_id ?? '-',
                    'nama_lengkap' => $attendance->user->nama_lengkap ?? '-',
                    'periode' => $attendance->periode,
                    'work_days' => $attendance->work_days,
                    'late_less_30' => $attendance->late_less_30,
                    'late_more_30' => $attendance->late_more_30,
                    'sick_days' => $attendance->sick_days,
                    // Hitungan per kode komponen (kolom lama maupun component_counts).
                    'components' => $attendance->componentCounts($codes),
                ];
            });
    }

    /**
     * Menentukan heading atau judul kolom di Excel: tujuh judul lama, lalu satu kolom per komponen lain.
     */
    public function headings(): array
    {
        return AttendanceImportTemplateExport::headingsFor(self::LEGACY_HEADINGS, $this->components());
    }

    /**
     * Menentukan bagaimana data setiap baris akan dipetakan ke dalam array
     *
     * @param  array<string, mixed>  $attendance
     */
    public function map(mixed $attendance): array
    {
        $row = [
            $attendance['employee_id'],      // ID Karyawan
            $attendance['nama_lengkap'],     // Nama Lengkap
            $attendance['periode'],          // Periode
            $attendance['work_days'],        // Hari Kerja
        ];

        // Kolom 5-7: tiga komponen lama (Late Less 30 min, Late More 30 min, Sakit), lalu komponen lain.
        foreach ($this->components()->keys() as $code) {
            $row[] = $attendance['components'][$code] ?? 0;
        }

        return $row;
    }

    /**
     * Menentukan nama sheet untuk file Excel
     */
    public function title(): string
    {
        return 'Attendance Data';
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                AttendanceImportTemplateExport::annotateComponentHeadings($event->sheet->getDelegate(), $this->components());
            },
        ];
    }

    /**
     * @return Collection<string, AttendanceComponentData>
     */
    private function components(): Collection
    {
        return $this->components ??= AttendanceCountInput::exportComponents();
    }
}
