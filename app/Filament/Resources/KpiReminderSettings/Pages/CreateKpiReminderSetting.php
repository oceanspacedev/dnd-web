<?php

namespace App\Filament\Resources\KpiReminderSettings\Pages;

use App\Filament\Resources\KpiReminderSettings\KpiReminderSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKpiReminderSetting extends CreateRecord
{
    protected static string $resource = KpiReminderSettingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
