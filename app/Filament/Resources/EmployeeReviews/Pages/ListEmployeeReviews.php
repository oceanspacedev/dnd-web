<?php

namespace App\Filament\Resources\EmployeeReviews\Pages;

use App\Exports\EmployeeReviewExport;
use App\Exports\EmployeeReviewImportTemplateExport;
use App\Filament\Resources\EmployeeReviews\EmployeeReviewResource;
use App\Imports\EmployeeReviewImport;
use App\Services\ApprovalScopeService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
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

            // Check if file exists in the data
            if (! isset($data['file']) || empty($data['file'])) {
                Notification::make()
                    ->title('Error: No file was uploaded')
                    ->danger()
                    ->send();

                return;
            }

            // Debug the file data - Fix: Pass as array context
            Log::info('File data:', ['file' => $data['file']]);

            // Try a few different approaches to get the file path
            if (is_array($data['file']) && count($data['file']) > 0) {
                // If it's an array of files, take the first one
                $filePath = $data['file'][0];
            } else {
                // Otherwise use the value directly
                $filePath = $data['file'];
            }

            // Try different methods to get the actual file
            if (Storage::disk('public')->exists($filePath)) {
                $fullPath = Storage::disk('public')->path($filePath);
            } elseif (Storage::disk('local')->exists($filePath)) {
                $fullPath = Storage::disk('local')->path($filePath);
            } else {
                // If all else fails, try to use the path directly
                $fullPath = $filePath;

                // Check if it looks like a URL/uploaded path and get the file directly
                if (filter_var($filePath, FILTER_VALIDATE_URL) || strpos($filePath, 'livewire-tmp') !== false) {
                    $import = new EmployeeReviewImport($allowedUserIds);
                    Excel::import($import, $filePath);

                    // If we make it here, we successfully imported without using a local file path
                    goto summarize_import;
                }
            }

            // Check if file exists at the path
            if (! file_exists($fullPath)) {
                Log::error('File not found at path: '.$fullPath);
                Log::info('Original file data: ', ['data' => $data['file']]);

                Notification::make()
                    ->title('Error: File not found. Please try uploading again.')
                    ->body('Technical details: File path could not be resolved correctly.')
                    ->danger()
                    ->send();

                return;
            }

            $import = new EmployeeReviewImport($allowedUserIds);
            Excel::import($import, $fullPath);

            summarize_import:

            // Check if getImportSummary method exists
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

            // Store skipped details in session for reference if needed
            session()->flash('skippedDetails', $summary['skippedDetails']);
        } catch (Throwable $e) {
            // Log the error for debugging
            Log::error('Import Error: '.$e->getMessage());
            Log::error($e->getTraceAsString());

            // Send a notification with the error message
            Notification::make()
                ->title('Error during import: '.$e->getMessage())
                ->danger()
                ->send();
        }
    }
}
