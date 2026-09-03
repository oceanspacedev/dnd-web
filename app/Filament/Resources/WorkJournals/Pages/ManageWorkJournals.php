<?php

namespace App\Filament\Resources\WorkJournals\Pages;

use App\Filament\Exports\WorkJournalExporter;
use App\Filament\Resources\WorkJournals\WorkJournalResource;
use App\Models\User;
use App\Services\WorkJournalReportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ManageRecords;

class ManageWorkJournals extends ManageRecords
{
    protected static string $resource = WorkJournalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label('Ekspor Jurnal')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->exporter(WorkJournalExporter::class),

            Action::make('buatRekap')
                ->label('Buat Rekap')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->modalHeading('Buat Rekap Jurnal')
                ->modalDescription('Pilih rentang tanggal untuk merangkum seluruh aktivitas kerja Anda.')
                ->modalWidth('md')
                ->form([
                    DatePicker::make('date_from')
                        ->label('Dari Tanggal')
                        ->default(fn () => now()->startOfMonth()->toDateString())
                        ->required(),

                    DatePicker::make('date_until')
                        ->label('Sampai Tanggal')
                        ->default(fn () => now()->toDateString())
                        ->required(),

                    Radio::make('format')
                        ->label('Pilihan Format Unduhan')
                        ->options([
                            'pdf' => 'PDF Document (.pdf)',
                            'docs' => 'Microsoft Word (.doc / .docx)',
                        ])
                        ->default('pdf')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $user = auth()->user();

                    return app(WorkJournalReportService::class)->generateAndDownload(
                        user: $user,
                        dateFrom: $data['date_from'],
                        dateUntil: $data['date_until'],
                        format: $data['format'],
                    );
                }),

            CreateAction::make()
                ->label('Tulis Jurnal Baru')
                ->icon('heroicon-o-plus')
                ->modalWidth('md'),
        ];
    }
}
