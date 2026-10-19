<?php

// Shared demo-database check for guard.php, demo_seed.php and flows/aturan-template.php (call it after the app booted).
// Checking config('database.connections.<default>.database') alone is not enough: config/database.php also sets
// 'url' => env('DB_URL'), and Laravel merges that URL over the connection config only when the connection opens. With a
// DB_URL in .env or the shell the config would still name the demo DB while every query (and migrate:fresh) ran on the
// database in the URL. env.sh exports an empty DB_URL; this check refuses a URL anyway and asks the live connection.

use Illuminate\Support\Facades\DB;

/**
 * Null when the app's default connection really is the demo database named by DB_DATABASE, otherwise the reason why not.
 *
 * @param  list<string>|null  $allowed  exact demo database names; null accepts any dnd_demo_* name
 */
function guideGifsDemoDatabaseProblem(?array $allowed = null): ?string
{
    $expected = (string) getenv('DB_DATABASE');
    if (! str_starts_with($expected, 'dnd_demo_') || ($allowed !== null && ! in_array($expected, $allowed, true))) {
        return 'DB_DATABASE is '.($expected === '' ? 'not set' : $expected).', expected '.($allowed === null ? 'dnd_demo_*' : implode(' or ', $allowed));
    }

    $connection = (string) config('database.default');
    if (filled(config("database.connections.$connection.url"))) {
        return 'a database URL (DB_URL) is configured: it would replace DB_DATABASE when the connection opens';
    }

    $configured = (string) config("database.connections.$connection.database");
    if ($configured !== $expected) {
        return "app is configured for $configured, expected the demo DB $expected from DB_DATABASE";
    }

    try {
        $live = DB::connection()->getDatabaseName();
        $selected = DB::selectOne('select database() as db')?->db;
    } catch (Throwable) {
        return "cannot open $expected (run setup-db.sh first)";
    }

    if ($live !== $expected || $selected !== $expected) {
        return 'app is connected to '.($selected ?? $live).", expected the demo DB $expected";
    }

    return null;
}
