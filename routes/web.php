<?php

use App\Http\Controllers\LeaderboardExportController;
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

Route::middleware('auth')->group(function () {
    Route::get('admin/leaderboard/export', [LeaderboardExportController::class, 'export'])
        ->name('leaderboard.export');
});
