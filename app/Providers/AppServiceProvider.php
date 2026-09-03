<?php

namespace App\Providers;

use App\Models\KpiDescription;
use App\Observers\KpiDescriptionObserver;
use App\Models\KpiCategory;
use App\Observers\KpiCategoryObserver;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Tables\Table;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Register observers for cache management
        KpiDescription::observe(KpiDescriptionObserver::class);
        KpiCategory::observe(KpiCategoryObserver::class);

        // Preserve Filament v3 layout defaults during v4 upgrade
        Table::configureUsing(fn (Table $table) => $table
            ->deferFilters(false));

        Fieldset::configureUsing(fn (Fieldset $fieldset) => $fieldset
            ->columnSpanFull());

        Grid::configureUsing(fn (Grid $grid) => $grid
            ->columnSpanFull());

        Section::configureUsing(fn (Section $section) => $section
            ->columnSpanFull());

        // Host Filament layout override must win over Mekaya's prepended package views
        // (Filament v4 removed <x-filament-panels::sidebar>; Mekaya's fallback broke panels).
        View::prependNamespace(
            'filament-panels',
            resource_path('views/vendor/filament-panels'),
        );

        // Attach security requirements directly to operations for Stoplight Elements UI
        if (class_exists(\Dedoc\Scramble\Scramble::class)) {
            \Dedoc\Scramble\Scramble::afterOpenApiGenerated(function (\Dedoc\Scramble\Support\Generator\OpenApi $openApi) {
                foreach ($openApi->paths as $pathItem) {
                    foreach ($pathItem->operations as $operation) {
                        if ($operation->security !== []) {
                            $operation->security = [
                                new \Dedoc\Scramble\Support\Generator\SecurityRequirement('http'),
                            ];
                        }
                    }
                }
            });
        }
    }
}
