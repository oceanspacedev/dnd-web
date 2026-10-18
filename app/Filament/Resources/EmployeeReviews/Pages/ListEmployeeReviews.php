<?php

namespace App\Filament\Resources\EmployeeReviews\Pages;

use App\Exports\EmployeeReviewExport;
use App\Exports\EmployeeReviewImportTemplateExport;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\EmployeeReviews\EmployeeReviewResource;
use App\Imports\EmployeeReviewImport;
use App\Policies\EmployeeReviewPolicy;
use App\Support\StoredSpreadsheetUpload;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Date;
use Log;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ListEmployeeReviews extends ListRecords
{
    protected static string $resource = EmployeeReviewResource::class;

    protected static ?string $title = 'Penilaian';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('downloadTemplate')
                ->label('Download Template')
                ->icon('heroicon-s-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $currentPeriod = Date::now()->format('Y-m');
                    $fileName = "employee_review_import_template_{$currentPeriod}.xlsx";

                    // Template hanya berisi karyawan yang boleh diisi datanya oleh user (tanpa akun ADMIN untuk admin per badan usaha).
                    return Excel::download(new EmployeeReviewImportTemplateExport(EmployeeReviewPolicy::writableUserIds(auth()->user())), $fileName);
                }),
            ActionGroup::make([
                Action::make('export')
                    ->label('Export')
                    ->icon('heroicon-s-arrow-down-tray')
                    ->action(function () {
                        // Export mengikuti cakupan daftar Penilaian.
                        return Excel::download(new EmployeeReviewExport(EmployeeReviewPolicy::visibleUserIds(auth()->user())), 'employee_review_data.xlsx');
                    }),
                Action::make('import')
                    ->icon('heroicon-s-arrow-up-tray')
                    ->color('gray')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Upload File Excel:')
                            ->required()
                            ->directory('imports/employee-reviews')
                            ->visibility('private')
                            ->preventFilePathTampering()
                            ->acceptedFileTypes(['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
                    ])
                    ->action(function (array $data) {
                        return $this->processImport($data);
                    })
                    ->modalWidth('md')
                    ->modalHeading('Import Penilaian Tim')
                    ->modalSubmitActionLabel('Import'),
            ])
                ->label('Import/Export')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button(),
        ];
    }

    public function processImport(array $data)
    {
        try {
            // null = seluruh karyawan; selain itu hanya karyawan yang boleh diisi datanya oleh user.
            $allowedUserIds = EmployeeReviewPolicy::writableUserIds(auth()->user());

            if (! isset($data['file']) || empty($data['file'])) {
                Notification::make()
                    ->title('Error: No file was uploaded')
                    ->danger()
                    ->send();

                return;
            }

            $import = new EmployeeReviewImport($allowedUserIds);
            StoredSpreadsheetUpload::import($import, $data['file'], 'imports/employee-reviews');

            if (! method_exists($import, 'getImportSummary')) {
                Notification::make()
                    ->title('Error: Import summary method not found')
                    ->danger()
                    ->send();

                return;
            }

            $summary = $import->getImportSummary();
            $unfilled = $summary['unfilledCount'];
            // Baris template yang belum dinilai diabaikan, bukan dianggap gagal atau disimpan sebagai penilaian 0.
            $unfilledNote = $unfilled > 0 ? " {$unfilled} baris template belum dinilai (diabaikan)." : '';

            if ($summary['importedCount'] > 0) {
                $notification = Notification::make()
                    ->title("Data Penilaian berhasil diimport. {$summary['importedCount']} data ditambahkan, {$summary['skippedCount']} data dilewati.{$unfilledNote}")
                    ->success();
            } elseif ($summary['skippedCount'] === 0) {
                $notification = Notification::make()
                    ->title($unfilled > 0
                        ? "Tidak ada data yang diimport. Semua baris ({$unfilled}) belum diisi nilai penilaiannya."
                        : 'Tidak ada data yang diimport. File tidak berisi baris data.')
                    ->warning();
            } else {
                $notification = Notification::make()
                    ->title("Tidak ada data yang diimport. Semua data ({$summary['skippedCount']}) sudah ada atau tidak valid.{$unfilledNote}")
                    ->warning();
            }

            // Alasan baris dilewati (beberapa baris pertama): tetap tampil sampai ditutup agar sempat dibaca.
            if (($details = ListAttendances::importSummaryDetails($summary)) !== null) {
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
