<?php

namespace App\Filament\Resources\KpiCategories\Pages;

use App\Filament\Resources\KpiCategories\KpiCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageKpiCategories extends ManageRecords
{
    protected static string $resource = KpiCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->modalWidth('md'),
        ];
    }
}
