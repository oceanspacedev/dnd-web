<?php

namespace App\Filament\Resources\ScoringRules\Pages;

use App\Filament\Resources\ScoringRules\ScoringRuleResource;
use App\Models\ScoringRule;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman baca versi aturan. Versi terkunci hanya bisa diubah nama dan catatannya; parameter penilaian
 * diubah dengan "Duplikat jadi versi baru".
 */
class ViewScoringRule extends ViewRecord
{
    protected static string $resource = ScoringRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ScoringRuleResource::editDetailsAction(),
            ScoringRuleResource::duplicateAction(),
            ScoringRuleResource::deleteAction(),
        ];
    }

    /**
     * Tampilkan aturan kehadiran persis seperti yang tersimpan (tanpa menawarkan komponen lain).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ScoringRule $record */
        $record = $this->getRecord();

        return [
            ...$data,
            ...ScoringRuleResource::formData($record, offerActiveComponents: false),
        ];
    }
}
