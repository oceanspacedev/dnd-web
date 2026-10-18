<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Exports\AttendanceExport;
use App\Exports\AttendanceImportTemplateExport;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Imports\AttendanceImport;
use App\Policies\AttendancePolicy;
use App\Support\Periode;
use App\Support\Scoring\AttendanceCountInput;
use App\Support\StoredSpreadsheetUpload;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Log;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected static ?string $title = 'Kehadiran';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('downloadTemplate')
                ->label('Download Template')
                ->icon('heroicon-s-arrow-down-tray')
                ->color('gray')
                ->modalHeading('Download Template Kehadiran')
                ->modalDescription('Template berisi karyawan yang bisa Anda isi datanya. Kolom Periode sudah terisi dan kolom komponen mengikuti aturan penilaian periode yang dipilih.')
                ->modalWidth('md')
                ->modalSubmitActionLabel('Download')
                ->schema([
                    AttendanceResource::periodeInput('periode', 'Periode')
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get): ?string => static::templateRuleDescription($get('periode'))),
                ])
                ->action(function (array $data) {
                    $periode = AttendanceCountInput::periode($data['periode'] ?? null) ?? Periode::previous();

                    // Template hanya berisi karyawan yang boleh diisi datanya oleh user (tanpa akun ADMIN untuk admin per badan usaha).
                    return Excel::download(
                        new AttendanceImportTemplateExport(AttendancePolicy::writableUserIds(auth()->user()), $periode),
                        AttendanceImportTemplateExport::fileName($periode),
                    );
                }),
            ActionGroup::make([
                Action::make('export')
                    ->label('Export')
                    ->icon('heroicon-s-arrow-down-tray')
                    ->action(function () {
                        // Export mengikuti cakupan daftar Kehadiran.
                        return Excel::download(new AttendanceExport(AttendancePolicy::visibleUserIds(auth()->user())), 'attendance_data.xlsx');
                    }),
                Action::make('import')
                    ->icon('heroicon-s-arrow-up-tray')
                    ->color('gray')
                    ->schema([
                        AttendanceResource::periodeInput('periode', 'Periode yang diimport')
                            ->live(onBlur: true)
                            // Konfirmasi berlaku untuk satu periode: ganti periode = konfirmasi ulang.
                            ->afterStateUpdated(fn (Set $set) => $set('confirm_open_periode', false))
                            ->helperText('Pilih periode yang sama dengan kolom Periode di file. Baris dengan kolom Periode kosong diimport ke periode ini; baris dengan periode lain dilewati.'),
                        Callout::make(fn (Get $get): string => static::isFuturePeriode($get('periode')) ? 'Periode ini belum dimulai' : 'Periode ini belum selesai')
                            ->description(fn (Get $get): string => static::openPeriodeDescription($get('periode')))
                            ->warning()
                            ->visible(fn (Get $get): bool => static::isOpenPeriode($get('periode'))),
                        Checkbox::make('confirm_open_periode')
                            ->label(fn (Get $get): string => 'Saya yakin mengimport data kehadiran periode '.Periode::label($get('periode')).'.')
                            ->accepted()
                            ->validationMessages([
                                'accepted' => 'Centang konfirmasi untuk mengimport periode yang belum selesai.',
                            ])
                            ->visible(fn (Get $get): bool => static::isOpenPeriode($get('periode'))),
                        FileUpload::make('file')
                            ->label('Upload File Excel:')
                            ->required()
                            ->directory('imports/attendances')
                            ->visibility('private')
                            ->preventFilePathTampering()
                            ->acceptedFileTypes(['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
                    ])
                    ->action(function (array $data) {
                        return $this->processImport($data);
                    })
                    ->modalWidth('md')
                    ->modalHeading('Import Data Absensi')
                    ->modalSubmitActionLabel('Import'),
            ])
                ->label('Import/Export')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button(),
        ];
    }

    /**
     * Ringkasan alasan baris dilewati (beberapa baris pertama) dan peringatan kolom untuk notifikasi import.
     *
     * @param  array{skippedDetails?: list<array<string, mixed>>, warnings?: list<string>}  $summary
     */
    public static function importSummaryDetails(array $summary, int $limit = 5): ?string
    {
        $lines = $summary['warnings'] ?? [];
        $skipped = $summary['skippedDetails'] ?? [];

        foreach (array_slice($skipped, 0, $limit) as $detail) {
            $who = collect([$detail['employee_id'] ?? null, $detail['nama_lengkap'] ?? null])
                ->filter(fn (mixed $value): bool => filled($value) && is_scalar($value))
                ->implode(' / ');
            $periode = is_scalar($detail['periode'] ?? null) ? (string) $detail['periode'] : '-';

            $lines[] = trim(($who !== '' ? $who : 'Baris tanpa nama').' ('.$periode.'): '.($detail['reason'] ?? 'Dilewati.'));
        }

        if (count($skipped) > $limit) {
            $lines[] = 'Dan '.(count($skipped) - $limit).' baris lain dilewati.';
        }

        // Isi sel Excel di-escape; body notifikasi dirender sebagai HTML.
        return $lines === [] ? null : implode('<br>', array_map(static fn (string $line): string => '&bull; '.e($line), $lines));
    }

    /**
     * Periode yang dipilih sudah bulan berjalan atau sesudahnya (bulannya belum selesai): modal import meminta
     * konfirmasi. Periode yang belum valid tidak meminta konfirmasi (validasi periode yang menolak).
     */
    public static function isOpenPeriode(mixed $periode): bool
    {
        $normalized = AttendanceCountInput::periode($periode);

        return $normalized !== null && Periode::isCurrentOrLater($normalized);
    }

    /**
     * Periode yang dipilih sesudah bulan berjalan (bulannya belum dimulai).
     */
    public static function isFuturePeriode(mixed $periode): bool
    {
        $normalized = AttendanceCountInput::periode($periode);

        return $normalized !== null && strcmp($normalized, Periode::current()) > 0;
    }

    /**
     * Keterangan peringatan periode yang belum selesai di modal Import: bulan berjalan "masih berjalan", bulan
     * sesudahnya "belum dimulai".
     */
    public static function openPeriodeDescription(mixed $periode): string
    {
        $label = Periode::label($periode);
        $status = static::isFuturePeriode($periode) ? "{$label} belum dimulai." : "{$label} masih berjalan (belum selesai).";

        return $status.' Data kehadiran biasanya direkap untuk bulan yang sudah selesai ('.Periode::label(Periode::previous()).').';
    }

    /**
     * Keterangan aturan penilaian periode template di modal Download Template.
     */
    public static function templateRuleDescription(mixed $periode): ?string
    {
        $rule = AttendanceCountInput::ruleFor($periode);

        if ($rule === null) {
            return null;
        }

        return "Aturan penilaian: {$rule->name}. ".(AttendanceCountInput::recordsIzinInSick($rule)
            ? 'Izin dicatat bersama sakit di kolom '.AttendanceCountInput::SICK_IZIN_LABEL.'.'
            : 'Sakit dan izin diisi di kolom masing-masing.');
    }

    /**
     * Import file kehadiran. $data['periode'] (modal Import) = periode yang dipilih: sel Periode kosong memakai
     * periode itu dan baris periode lain dilewati; tanpa periode, setiap baris memakai periode di filenya.
     *
     * @param  array<string, mixed>  $data
     */
    public function processImport(array $data)
    {
        try {
            // null = seluruh karyawan; selain itu hanya karyawan yang boleh diisi datanya oleh user.
            $allowedUserIds = AttendancePolicy::writableUserIds(auth()->user());

            if (! isset($data['file']) || empty($data['file'])) {
                Notification::make()
                    ->title('Error: No file was uploaded')
                    ->danger()
                    ->send();

                return;
            }

            $periode = null;

            if (filled($data['periode'] ?? null)) {
                $periode = AttendanceCountInput::periode($data['periode']);

                if ($periode === null) {
                    Notification::make()
                        ->title('Periode import tidak valid')
                        ->body(AttendanceCountInput::periodeError($data['periode']))
                        ->danger()
                        ->send();

                    return;
                }
            }

            // Karyawan yang terlihat tetapi tidak boleh diisi (akun ADMIN bagi admin per badan usaha) diberi alasan sendiri.
            $import = new AttendanceImport($allowedUserIds, AttendancePolicy::visibleUserIds(auth()->user()), $periode);
            StoredSpreadsheetUpload::import($import, $data['file'], 'imports/attendances');

            if (! method_exists($import, 'getImportSummary')) {
                Notification::make()
                    ->title('Error: Import summary method not found')
                    ->danger()
                    ->send();

                return;
            }

            $summary = $import->getImportSummary();
            $details = static::importSummaryDetails($summary);
            $unfilled = (int) ($summary['unfilledCount'] ?? 0);
            // Baris template yang belum diisi diabaikan, bukan dianggap gagal.
            $unfilledNote = $unfilled > 0 ? " {$unfilled} baris template belum diisi (diabaikan)." : '';
            // Periode yang dipilih disebut di judul agar jelas data bulan mana yang masuk.
            $prefix = $periode !== null ? 'Import Kehadiran '.Periode::label($periode).': ' : '';
            // Baris periode lain di file (dilewati karena periode yang dipilih berbeda).
            $mismatched = array_sum($summary['periodeMismatches'] ?? []);

            if ($summary['importedCount'] > 0) {
                $notification = Notification::make()
                    ->title(($periode !== null ? $prefix : 'Data Kehadiran berhasil diimport. ')
                        ."{$summary['importedCount']} data ditambahkan, {$summary['skippedCount']} data dilewati.{$unfilledNote}")
                    ->success();
            } elseif ($summary['skippedCount'] === 0) {
                $notification = Notification::make()
                    ->title($prefix.match (true) {
                        $unfilled > 0 => "Tidak ada data yang diimport. Semua baris ({$unfilled}) belum diisi Hari Kerja maupun komponen kehadirannya.",
                        // Mis. semua sheet tidak diimport karena kolom wajibnya tidak ditemukan: alasannya di isi notifikasi.
                        $details !== null => 'Tidak ada data yang diimport. Periksa keterangan di bawah.',
                        default => 'Tidak ada data yang diimport. File tidak berisi baris data.',
                    })
                    ->warning();
            } elseif ($periode !== null && $mismatched > 0) {
                // Bukan "sudah ada atau tidak valid": baris periode lain punya langkahnya sendiri (lihat keterangan).
                $invalid = $summary['skippedCount'] - $mismatched;
                $notification = Notification::make()
                    ->title($prefix.($invalid > 0
                        ? "Tidak ada data yang diimport. {$invalid} data ".Periode::label($periode)." sudah ada atau tidak valid, {$mismatched} baris periode lain dilewati."
                        : 'Tidak ada data '.Periode::label($periode).' di file. Periksa keterangan di bawah.').$unfilledNote)
                    ->warning();
            } else {
                $notification = Notification::make()
                    ->title("{$prefix}Tidak ada data yang diimport. Semua data ({$summary['skippedCount']}) sudah ada atau tidak valid.{$unfilledNote}")
                    ->warning();
            }

            // Alasan baris dilewati & peringatan kolom: tetap tampil sampai ditutup agar sempat dibaca.
            if ($details !== null) {
                $notification->body($details)->persistent();
            }

            $notification->send();

            session()->flash('skippedDetails', $summary['skippedDetails']);

        } catch (Throwable $e) {
            Log::error('Import Error: '.$e->getMessage());
            Log::error($e->getTraceAsString());

            Notification::make()
                ->title('Error during import: '.$e->getMessage())
                ->danger()
                ->send();
        }
    }
}
