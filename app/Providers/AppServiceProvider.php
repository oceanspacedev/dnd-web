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

        // Fix for PHP < 8.4 where \Dom\HTMLDocument does not exist (Filament notifications & HTML sanitization)
        if (! class_exists(\Dom\HTMLDocument::class) && interface_exists(\Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface::class)) {
            $this->app->scoped(\Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface::class, function () {
                return new class implements \Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface {
                    public function sanitize(string $input): string
                    {
                        return strip_tags($input, '<p><br><b><strong><i><em><u><a><ul><ol><li><span><div><h1><h2><h3><h4><h5><h6><table><thead><tbody><tr><th><td><img>');
                    }

                    public function sanitizeFor(string $element, string $input): string
                    {
                        return $this->sanitize($input);
                    }
                };
            });

            \Illuminate\Support\Str::macro('sanitizeHtml', function (string $html): string {
                return strip_tags($html, '<p><br><b><strong><i><em><u><a><ul><ol><li><span><div><h1><h2><h3><h4><h5><h6><table><thead><tbody><tr><th><td><img>');
            });

            \Illuminate\Support\Stringable::macro('sanitizeHtml', function (): \Illuminate\Support\Stringable {
                return new \Illuminate\Support\Stringable(\Illuminate\Support\Str::sanitizeHtml($this->value));
            });
        }
    }
}
