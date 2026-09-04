<?php

namespace App\Filament\Resources\Users\Pages;

use Filament\Actions\CreateAction;
use Log;
use Throwable;
use Filament\Schemas\Components\Tabs\Tab;
use App\Exports\TemplateExport;
use App\Filament\Exports\UserExporter;
use App\Filament\Resources\Users\UserResource;
use App\Imports\UsersImport;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use App\Services\UserJsonImportService;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('downloadTemplate')
                ->label('Download Template')
                ->icon('heroicon-s-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    return Excel::download(new TemplateExport(), 'user_template.xlsx');
                }),
            ActionGroup::make([
                ExportAction::make()
                    ->label('Export User')
                    ->icon('heroicon-s-arrow-down-tray')
                    ->exporter(UserExporter::class),
                Action::make('import')
                    ->label('Import User')
                    ->icon('heroicon-s-arrow-up-tray')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()?->role?->name === 'ADMIN')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Upload File Excel:')
                            ->acceptedFileTypes([
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ]),
                    ])
                    ->action(function (array $data) {
                        return $this->processImport($data);
                    })
                    ->modalWidth('md')
                    ->modalHeading('Import User')
                    ->modalSubmitActionLabel('Import'),
                Action::make('import_json')
                    ->label('Import JSON (Talenta)')
                    ->icon('heroicon-s-code-bracket')
                    ->color('gray')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Upload File JSON (Talenta):')
                            ->required()
                            ->acceptedFileTypes([
                                'application/json',
                                'text/json',
                                'text/plain',
                            ])
                            ->maxSize(12 * 1024)
                            ->disk('local')
                            ->visibility('private')
                            ->storeFiles(false)
                            ->helperText('Setiap user baru wajib memiliki field initial_password/password yang unik (minimal 12 karakter, maksimal 72 byte). Field ini diabaikan untuk user existing.'),
                    ])
                    ->action(function (array $data) {
                        return $this->processJsonImport($data);
                    })
                    ->visible(fn (): bool => auth()->user()?->role?->name === 'ADMIN')
                    ->modalWidth('md')
                    ->modalHeading('Import User dari File JSON')
                    ->modalSubmitActionLabel('Import JSON'),
            ])
                ->label('Import/Export')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button(),
        ];
    }

    public function processImport(array $data)
    {
        abort_unless(auth()->user()?->role?->name === 'ADMIN', 403);

        try {
            set_time_limit(300);
            ini_set('max_execution_time', '300');
            ini_set('memory_limit', '512M');

            if (!isset($data['file']) || empty($data['file'])) {
                Notification::make()
                    ->title('Error: No file was uploaded')
                    ->danger()
                    ->send();
                return;
            }

            Log::info('User import file data:', ['file' => $data['file']]);

            if (is_array($data['file']) && count($data['file']) > 0) {
                $filePath = $data['file'][0];
            } else {
                $filePath = $data['file'];
            }

            if (Storage::disk('public')->exists($filePath)) {
                $fullPath = Storage::disk('public')->path($filePath);
            } elseif (Storage::disk('local')->exists($filePath)) {
                $fullPath = Storage::disk('local')->path($filePath);
            } else {
                $fullPath = $filePath;

                if (filter_var($filePath, FILTER_VALIDATE_URL) || strpos($filePath, 'livewire-tmp') !== false) {
                    $import = new UsersImport();
                    Excel::import($import, $filePath);

                    goto summarize_import;
                }
            }

            if (!file_exists($fullPath)) {
                Log::error('User import file not found at path: ' . $fullPath);
                Log::info('Original file data: ', ['data' => $data['file']]);

                Notification::make()
                    ->title('Error: File not found. Please try uploading again.')
                    ->body('Technical details: File path could not be resolved correctly.')
                    ->danger()
                    ->send();
                return;
            }

            $import = new UsersImport();
            Excel::import($import, $fullPath);

            summarize_import:

            $errors = method_exists($import, 'getErrors') ? $import->getErrors() : [];
            $errorCount = count($errors);
            $successCount = method_exists($import, 'getSuccessCount') ? $import->getSuccessCount() : 0;
            $processedCount = method_exists($import, 'getProcessedCount') ? $import->getProcessedCount() : 0;

            if ($errorCount > 0) {
                Log::warning('User import errors', ['errors' => $errors]);

                $previewErrors = array_slice($errors, 0, 5);
                $bodyText = "Berhasil: {$successCount} row | Gagal: {$errorCount} row\n\nDetail error:\n- " . implode("\n- ", $previewErrors);
                if ($errorCount > 5) {
                    $bodyText .= "\n... dan " . ($errorCount - 5) . " error lainnya.";
                }

                Notification::make()
                    ->title("Import Selesai dengan {$errorCount} Error")
                    ->body($bodyText)
                    ->warning()
                    ->persistent()
                    ->send();
            } else {
                Notification::make()
                    ->title('Import User Berhasil')
                    ->body("Seluruh {$successCount} data user berhasil di-import/diperbarui.")
                    ->success()
                    ->send();
            }

            session()->flash('importErrors', $errors);
        } catch (Throwable $e) {
            Log::error('Import Error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            Notification::make()
                ->title('Error during import: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function processJsonImport(array $data)
    {
        abort_unless(auth()->user()?->role?->name === 'ADMIN', 403);

        try {
            set_time_limit(300);

            if (!isset($data['file']) || empty($data['file'])) {
                Notification::make()
                    ->title('Error: Tidak ada file JSON yang diunggah')
                    ->danger()
                    ->send();
                return;
            }

            $rawFile = is_array($data['file']) && count($data['file']) > 0 ? $data['file'][0] : $data['file'];

            if (! $rawFile instanceof UploadedFile || ! $rawFile->isValid()) {
                Notification::make()
                    ->title('Import JSON Gagal')
                    ->body('File upload tidak valid. Silakan unggah ulang file JSON.')
                    ->danger()
                    ->send();
                return;
            }

            if ($rawFile->getSize() > 12 * 1024 * 1024) {
                Notification::make()
                    ->title('Import JSON Gagal')
                    ->body('Ukuran file JSON maksimal 12 MB.')
                    ->danger()
                    ->send();
                return;
            }

            $content = $rawFile->get();
            if (! is_string($content)) {
                Notification::make()
                    ->title('Import JSON Gagal')
                    ->body('File JSON tidak dapat dibaca. Silakan unggah ulang file.')
                    ->danger()
                    ->send();
                return;
            }

            $res = UserJsonImportService::importFromContent($content);

            if (!$res['success']) {
                Notification::make()
                    ->title('Import JSON Gagal')
                    ->body($res['message'])
                    ->danger()
                    ->send();
                return;
            }

            $successCount = $res['success_count'];
            $errorCount = $res['error_count'];
            $errors = $res['errors'];
            $createdCount = $res['created_count'];

            if ($errorCount > 0) {
                $preview = array_slice($errors, 0, 5);
                $bodyText = "Berhasil: {$successCount} karyawan (user baru: {$createdCount}) | Gagal: {$errorCount} karyawan\n\nDetail error:\n- " . implode("\n- ", $preview);

                Notification::make()
                    ->title("Import JSON Selesai dengan {$errorCount} Catatan")
                    ->body($bodyText)
                    ->warning()
                    ->persistent()
                    ->send();
            } else {
                Notification::make()
                    ->title('Import User (JSON) Berhasil')
                    ->body("Seluruh {$successCount} data karyawan berhasil di-import/diperbarui dari file JSON. User baru: {$createdCount}.")
                    ->success()
                    ->send();
            }
        } catch (Throwable $e) {
            Log::error('JSON Import Error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            Notification::make()
                ->title('Error saat import JSON')
                ->body('File tidak dapat diproses. Periksa format file lalu coba kembali.')
                ->danger()
                ->send();
        } finally {
            if (isset($rawFile) && $rawFile instanceof TemporaryUploadedFile) {
                try {
                    $rawFile->delete();
                } catch (Throwable $cleanupError) {
                    Log::warning('JSON import temporary file cleanup failed', [
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }
        }
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua')
                ->modifyQueryUsing(fn (Builder $query) => $query->withoutTrashed()),
            'archived' => Tab::make('Arsip')
                ->modifyQueryUsing(fn (Builder $query) => $query->onlyTrashed()),
        ];
    }
}

// user/export
