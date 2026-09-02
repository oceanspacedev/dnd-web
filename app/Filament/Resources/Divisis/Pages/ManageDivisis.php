<?php

namespace App\Filament\Resources\Divisis\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\Divisis\DivisiResource;
use Filament\Actions;
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
