<?php

return [
    /*
    |--------------------------------------------------------------------------
    | KPI Checklist Lock Days
    |--------------------------------------------------------------------------
    |
    | Number of days after end of KPI month when checklist editing is still
    | allowed for non-admin users.
    |
    */
    'checklist_lock_days' => (int) env('KPI_CHECKLIST_LOCK_DAYS', 5),

    /*
    |--------------------------------------------------------------------------
    | KPI Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Keep these short for frequently changing KPI setup data.
    |
    */
    'cache_ttl_seconds' => [
        'categories' => (int) env('KPI_CACHE_TTL_CATEGORIES_SECONDS', 300),
        'descriptions' => (int) env('KPI_CACHE_TTL_DESCRIPTIONS_SECONDS', 300),
        'positions' => (int) env('KPI_CACHE_TTL_POSITIONS_SECONDS', 300),
        'leaderboard' => (int) env('KPI_CACHE_TTL_LEADERBOARD_SECONDS', 60),
    ],
];
