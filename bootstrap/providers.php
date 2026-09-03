<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    \Dedoc\Scramble\ScrambleServiceProvider::class,
    \Laravel\Sanctum\SanctumServiceProvider::class,
];
