<?php

namespace App\Filament\Resources\KpiReminderSettings\Pages;

use App\Filament\Resources\KpiReminderSettings\KpiReminderSettingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKpiReminderSetting extends EditRecord
{
    protected static string $resource = KpiReminderSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
