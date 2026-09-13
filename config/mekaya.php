<?php

return [

    'admin' => [
        // Panel path. Should match the panel's ->path().
        'path' => 'admin',

        // Optional panel version metadata exposed through mekaya()->version().
        'version' => 'v2',

        // Optional brand logo path from the host application's /public directory.
        // A logo configured directly on the Filament panel takes precedence.
        'brand' => 'icon.svg',

        // Optional compact brand icon path from the host application's /public directory.
        // When neither a panel logo nor this icon exists, the application name is used.
        'brand_icon' => null,

        // Optional logo height. Null preserves the value configured on the panel.
        'brand_logo_height' => '2rem',

        // Optional favicon path from the host application's /public directory.
        // Null preserves the favicon configured directly on the Filament panel.
        'favicon' => 'icon.svg',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sidebar documentation link
    |--------------------------------------------------------------------------
    |
    | Optional footer link in the Mekaya sidebar (Shopper-style). Disabled by
    | default — enable and set a URL via env/config, or override from
    | MekayaPlugin::documentation(...). The link only renders when enabled
    | and the URL is non-empty. Plugin fluent calls take precedence.
    |
    */
    'documentation' => [
        'enabled' => env('MEKAYA_DOCS_ENABLED', false),
        'url' => env('MEKAYA_DOCS_URL'),
        'label' => env('MEKAYA_DOCS_LABEL'),
        'open_in_new_tab' => env('MEKAYA_DOCS_NEW_TAB', true),
    ],

];
