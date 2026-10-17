<?php

namespace App\Filament\Exports;

use App\Models\User;
use App\Services\DataAccessService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * Pertahanan berlapis: dipanggil saat aksi export dijalankan (sebelum query diserialisasi ke job).
     * Query tabel tiap menu (Karyawan / Tim Saya) sudah lebih sempit; di sini dipastikan export tidak
     * pernah memuat karyawan di luar badan usaha, tim, atau diri actor.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function modifyQuery(Builder $query): Builder
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return $query;
        }

        return DataAccessService::scopeOwnedRecords($query, $actor, $query->qualifyColumn('id'));
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your user export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }

    public function getJobQueue(): ?string
    {
        return 'exports';
    }
}
