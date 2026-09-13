<?php

namespace App\Filament\Resources\Cutpoints\Pages;

use App\Filament\Resources\Cutpoints\CutpointResource;
use Filament\Actions\CreateAction;
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
