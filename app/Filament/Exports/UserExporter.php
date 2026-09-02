<?php

namespace App\Filament\Exports;

use App\Models\User;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class UserExporter extends Exporter
{
    protected static ?string $model = User::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('nama_lengkap')
                ->label('nama_lengkap'),
            ExportColumn::make('username')
                ->label('username'),
            ExportColumn::make('employee_id')
                ->label('id_karyawan'),
            ExportColumn::make('no_hp')
                ->label('no_hp'),
            ExportColumn::make('email')
                ->label('email'),
            ExportColumn::make('role.name')
                ->label('role'),
            ExportColumn::make('area.name')
                ->label('area'),
            ExportColumn::make('divisi.name')
                ->label('divisi'),
            ExportColumn::make('position.name')
                ->label('position'),
            ExportColumn::make('approval.nama_lengkap')
                ->label('approval'),
            ExportColumn::make('created_at')
                ->label('created_at'),
            ExportColumn::make('updated_at')
                ->label('updated_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your user export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
