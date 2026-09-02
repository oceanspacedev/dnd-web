<?php

namespace App\Filament\Resources\Areas\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\Areas\AreaResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageAreas extends ManageRecords
{
    protected static string $resource = AreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->modalWidth('md'),
        ];
    }
}
