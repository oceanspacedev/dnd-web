<?php

// Boots the app with the current environment and refuses unless it is wired to a demo DB and the local WhatsApp stub.
// serve.sh runs it before php -S starts, reset.sh after each restore. It never prints the token.
// Usage: DB_DATABASE=dnd_demo_<guide> WAG_URL=… WAG_TOKEN=… php guard.php [--clear-scoring-cache]
//        (the shell scripts source env.sh first, which sets WAG_URL/WAG_TOKEN/MAIL_MAILER/CACHE_STORE)

use App\Services\ScoringRuleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$repo = dirname(__DIR__, 2);
chdir($repo);
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = function (string $message): never {
    fwrite(STDERR, "refusing: $message\n");
    exit(1);
};

$db = (string) config('database.connections.'.config('database.default').'.database');
if ($app->configurationIsCached()) {
    $fail('the config is cached (bootstrap/cache/config.php), so the environment below would be ignored');
}
if ($db !== getenv('DB_DATABASE') || ! str_starts_with($db, 'dnd_demo_')) {
    $fail("app is connected to $db, expected the demo DB from DB_DATABASE (dnd_demo_*)");
}

$waUrl = (string) config('services.whatsapp.api_url');
if ($waUrl !== getenv('WAG_URL') || preg_match('#^http://127\.0\.0\.1:\d+/?$#', $waUrl) !== 1) {
    $fail('the WhatsApp gateway URL is not the local stub from env.sh (source env.sh before booting the app)');
}
if ((string) config('services.whatsapp.api_key') !== getenv('WAG_TOKEN')) {
    $fail('the WhatsApp token is not the dummy token from env.sh');
}
$mailer = (string) config('mail.default');
if ($mailer !== 'log') {
    $fail("mail.default is $mailer, expected log");
}

try {
    DB::select('select 1');
} catch (Throwable $e) {
    $fail("cannot open $db (run setup-db.sh first)");
}

if (in_array('--clear-scoring-cache', $argv, true)) {
    ScoringRuleService::clearCache();
}

echo "guard ok: $db, WhatsApp -> $waUrl (local stub), mail -> log\n";
