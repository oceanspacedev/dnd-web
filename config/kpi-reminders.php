<?php

return [
    // This store must be shared by every application server so scheduler and
    // per-recipient locks prevent duplicate deliveries across hosts.
    'cache_store' => env('KPI_REMINDER_CACHE_STORE', 'kpi_reminders'),
];
