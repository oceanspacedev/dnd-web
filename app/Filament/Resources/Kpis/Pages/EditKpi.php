<?php

namespace App\Filament\Resources\Kpis\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\Kpis\KpiResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EditKpi extends EditRecord
{
    protected static string $resource = KpiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function resolveRecord($key): Model
    {
        return parent::resolveRecord($key)
            ->load([
                'kpi_detail.kpi_description',
                'user.position',
                'kpi_category',
                'kpi_type'
            ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }

    public function mount($record): void
    {
        // Enable query logging untuk debugging
        DB::enableQueryLog();

        parent::mount($record);

        // Log queries untuk debugging
        $queries = DB::getQueryLog();
        Log::info('KPI Edit Queries', [
            'count' => count($queries),
            'queries' => $queries
        ]);
    }
}
