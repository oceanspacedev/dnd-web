<?php

namespace App\Filament\Resources\Divisis\Pages;

use App\Filament\Resources\Divisis\DivisiResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDivisis extends ManageRecords
{
    protected static string $resource = DivisiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->modalWidth('md'),
        ];
    }
}
