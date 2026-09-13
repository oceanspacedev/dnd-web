<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Exports\AttendanceExport;
use App\Exports\AttendanceImportTemplateExport;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Imports\AttendanceImport;
use App\Services\ApprovalScopeService;
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
                ->action(function () {
                    $currentPeriod = Date::now()->format('Y-m');
                    $fileName = "attendance_import_template_{$currentPeriod}.xlsx";

                    return Excel::download(new AttendanceImportTemplateExport, $fileName);
                }),
            ActionGroup::make([
                Action::make('export')
                    ->label('Export')
                    ->icon('heroicon-s-arrow-down-tray')
                    ->action(function () {
                        return Excel::download(new AttendanceExport, 'attendance_data.xlsx');
                    }),
                Action::make('import')
                    ->icon('heroicon-s-arrow-up-tray')
                    ->color('gray')
                    ->schema([
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

    public function processImport(array $data)
    {
        try {
            $allowedUserIds = null;
            if (! AttendanceResource::canAccessAllAttendance(auth()->user())) {
                $allowedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
            }

            if (! isset($data['file']) || empty($data['file'])) {
                Notification::make()
                    ->title('Error: No file was uploaded')
                    ->danger()
                    ->send();

                return;
            }

            $import = new AttendanceImport($allowedUserIds);
            StoredSpreadsheetUpload::import($import, $data['file'], 'imports/attendances');

            if (! method_exists($import, 'getImportSummary')) {
                Notification::make()
                    ->title('Error: Import summary method not found')
                    ->danger()
                    ->send();

                return;
            }

            $summary = $import->getImportSummary();

            if ($summary['importedCount'] > 0) {
                $message = "Data Kehadiran berhasil diimport. {$summary['importedCount']} data ditambahkan, {$summary['skippedCount']} data dilewati.";
                Notification::make()
                    ->title($message)
                    ->success()
                    ->send();
            } else {
                $message = "Tidak ada data yang diimport. Semua data ({$summary['skippedCount']}) sudah ada atau tidak valid.";
                Notification::make()
                    ->title($message)
                    ->warning()
                    ->send();
            }

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
