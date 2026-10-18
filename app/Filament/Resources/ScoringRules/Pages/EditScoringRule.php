<?php

namespace App\Filament\Resources\ScoringRules\Pages;

use App\Filament\Resources\ScoringRules\ScoringRuleResource;
use App\Models\ScoringRule;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Hanya untuk versi yang belum terkunci (ScoringRulePolicy::update); versi terkunci dibuka di halaman lihat.
 */
class EditScoringRule extends EditRecord
{
    protected static string $resource = ScoringRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ScoringRuleResource::duplicateAction(),
            ScoringRuleResource::deleteAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ScoringRule $record */
        $record = $this->getRecord();

        return [
            ...$data,
            ...ScoringRuleResource::formData($record),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ScoringRuleResource::mutateFormDataForSave($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ScoringRuleResource::saveWithFieldErrors(fn (): Model => parent::handleRecordUpdate($record, $data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
