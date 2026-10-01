<?php

namespace App\Filament\Resources\ApprovalRules\Pages;

use App\Filament\Resources\ApprovalRules\ApprovalRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageApprovalRules extends ManageRecords
{
    protected static string $resource = ApprovalRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->modalWidth('lg'),
        ];
    }
}
