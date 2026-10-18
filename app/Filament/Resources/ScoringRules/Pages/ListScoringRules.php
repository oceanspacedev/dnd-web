<?php

namespace App\Filament\Resources\ScoringRules\Pages;

use App\Filament\Resources\ScoringRules\ScoringRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScoringRules extends ListRecords
{
    protected static string $resource = ScoringRuleResource::class;

    protected ?string $subheading = 'Versi aturan nilai kinerja (bobot KPI/Kehadiran/Penilaian dan aturan kehadiran). Setiap bulan memakai versi terakhir yang berlaku mulai bulan itu atau sebelumnya.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Buat Versi Baru'),
        ];
    }
}
