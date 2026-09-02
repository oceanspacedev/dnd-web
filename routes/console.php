<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::useCache((string) config('kpi-reminders.cache_store', 'kpi_reminders'));

Schedule::command('kpi:send-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping(720)
    ->onOneServer();
