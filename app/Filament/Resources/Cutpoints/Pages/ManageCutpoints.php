<?php

namespace App\Filament\Resources\Cutpoints\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\Cutpoints\CutpointResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageCutpoints extends ManageRecords
{
    protected static string $resource = CutpointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->modalWidth('md'),
        ];
    }
}
