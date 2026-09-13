<?php

namespace App\Filament\Resources\EmployeeReviews\Pages;

use App\Exports\EmployeeReviewExport;
use App\Exports\EmployeeReviewImportTemplateExport;
use App\Filament\Resources\EmployeeReviews\EmployeeReviewResource;
use App\Imports\EmployeeReviewImport;
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

                    return Excel::download(new EmployeeReviewImportTemplateExport, $fileName);
                }),
            ActionGroup::make([
                Action::make('export')
                    ->label('Export')
                    ->icon('heroicon-s-arrow-down-tray')
                    ->action(function () {
                        return Excel::download(new EmployeeReviewExport, 'employee_review_data.xlsx');
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
            $allowedUserIds = null;
            if (auth()->user()->role?->name !== 'ADMIN') {
                $allowedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
            }

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

            if ($summary['importedCount'] > 0) {
                $message = "Data Penilaian berhasil diimport. {$summary['importedCount']} data ditambahkan, {$summary['skippedCount']} data dilewati.";
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
