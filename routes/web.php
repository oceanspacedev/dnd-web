<?php

use App\Filament\Auth\Pages\PhoneLogin;
use App\Http\Controllers\LeaderboardExportController;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Admin UI lives in Filament (/admin).
| Keep only the home redirect and endpoints still called from Filament.
|
*/

Route::get('/', function () {
    return redirect('/admin');
})->name('/');

Route::get('/phone-login', PhoneLogin::class)
    ->middleware([
        DisableBladeIconComponents::class,
        DispatchServingFilamentEvent::class,
    ])
    ->name('phone-login');

Route::middleware('auth')->group(function () {
    Route::get('admin/leaderboard/export', [LeaderboardExportController::class, 'export'])
        ->name('leaderboard.export');
});
