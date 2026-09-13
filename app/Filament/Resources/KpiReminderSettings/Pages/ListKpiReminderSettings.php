<?php

namespace App\Filament\Resources\KpiReminderSettings\Pages;

use App\Filament\Resources\KpiReminderSettings\KpiReminderSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKpiReminderSettings extends ListRecords
{
    protected static string $resource = KpiReminderSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
