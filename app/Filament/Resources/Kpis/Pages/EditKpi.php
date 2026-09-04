<?php

namespace App\Filament\Resources\Kpis\Pages;

use App\Filament\Resources\Kpis\KpiResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

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
                'kpi_type',
            ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }
}
