<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Dashboard;
use Filament\Pages\Page;

class KpiStaffGuide extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Panduan KPI Operasional';

    protected static string | \UnitEnum | null $navigationGroup = 'Panduan';

    protected static ?int $navigationSort = 81;

    protected static ?string $slug = 'panduan-kpi-bawahan';

    protected static ?string $title = 'Panduan KPI Operasional';

    protected string $view = 'filament.pages.kpi-staff-guide';

    public function getChecklistUrl(): string
    {
        return Dashboard::getUrl([
            'tab' => 'checklist',
        ]);
    }

    public function getLeaderboardUrl(): string
    {
        return Dashboard::getUrl([
            'tab' => 'leaderboard',
        ]);
    }
}
