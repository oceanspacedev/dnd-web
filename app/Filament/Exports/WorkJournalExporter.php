<?php

namespace App\Filament\Exports;

use App\Models\WorkJournal;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class WorkJournalExporter extends Exporter
{
    protected static ?string $model = WorkJournal::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('date')
                ->label('Tanggal'),
            ExportColumn::make('user.nama_lengkap')
                ->label('Nama Karyawan'),
            ExportColumn::make('user.username')
                ->label('Username'),
            ExportColumn::make('user.divisi.name')
                ->label('Divisi'),
            ExportColumn::make('user.position.name')
                ->label('Posisi / Jabatan'),
            ExportColumn::make('activity')
                ->label('Aktivitas yang Dikerjakan'),
            ExportColumn::make('notes')
                ->label('Catatan Tambahan'),
            ExportColumn::make('created_at')
                ->label('Waktu Submit'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Ekspor jurnal harian selesai dan ' . number_format($export->successful_rows) . ' data berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' data gagal diekspor.';
        }

        return $body;
    }
}
