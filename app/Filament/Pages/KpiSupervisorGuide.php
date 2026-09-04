<?php

namespace App\Filament\Pages;

use App\Filament\Resources\EmployeeReviews\EmployeeReviewResource;
use App\Filament\Resources\Kpis\KpiResource;
use Filament\Pages\Page;

class KpiSupervisorGuide extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Panduan KPI Manajerial';

    protected static string | \UnitEnum | null $navigationGroup = 'Panduan';

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'panduan-kpi-atasan';

    protected static ?string $title = 'Panduan KPI Manajerial';

    protected string $view = 'filament.pages.kpi-supervisor-guide';

    public function getKpiListUrl(): string
    {
        return KpiResource::getUrl('index');
    }

    public function getKpiCreateUrl(): string
    {
        return KpiResource::getUrl('create');
    }

    public function getReviewListUrl(): string
    {
        return EmployeeReviewResource::getUrl('index');
    }

    public function getReviewCreateUrl(): string
    {
        return EmployeeReviewResource::getUrl('create');
    }
}
