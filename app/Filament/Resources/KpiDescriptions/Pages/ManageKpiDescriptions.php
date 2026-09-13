<?php

namespace App\Filament\Resources\KpiDescriptions\Pages;

use App\Filament\Resources\KpiDescriptions\KpiDescriptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageKpiDescriptions extends ManageRecords
{
    protected static string $resource = KpiDescriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->modalWidth('md'),
        ];
    }
}
