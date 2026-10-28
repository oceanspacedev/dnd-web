<?php

use App\Filament\Auth\Pages\EmailLogin;
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

Route::middleware([
    DisableBladeIconComponents::class,
    DispatchServingFilamentEvent::class,
])->group(function () {
    Route::get('/email-login', EmailLogin::class)->name('email-login');

    // Magic link dari email. Membuka URL saja tidak menghabiskan token; lihat EmailLogin::$magicToken.
    Route::get('/email-login/link/{token}', EmailLogin::class)
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('email-login.link');
});

Route::middleware('auth')->group(function () {
    Route::get('admin/leaderboard/export', [LeaderboardExportController::class, 'export'])
        ->name('leaderboard.export');
});
