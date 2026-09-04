<?php

namespace App\Providers;

use App\Filament\Auth\Pages\PhoneLogin;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Observers\KpiCategoryObserver;
use App\Observers\KpiDescriptionObserver;
use App\Services\ApprovalScopeService;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Tables\Table;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Octane resets scoped bindings between requests and queue jobs.
        $this->app->scoped(ApprovalScopeService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::component('phone-login', PhoneLogin::class);

        // Register observers for cache management
        KpiDescription::observe(KpiDescriptionObserver::class);
        KpiCategory::observe(KpiCategoryObserver::class);

        // Configure the application's Filament layout defaults.
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
    }
}
