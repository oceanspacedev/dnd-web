<?php

// Boots the app with the current environment and refuses unless it is wired to a demo DB and the local WhatsApp stub.
// The DB check asks the live connection (select database()) and refuses a DB_URL, which would override DB_DATABASE.
// serve.sh runs it before php -S starts, reset.sh after each restore. It never prints the token.
// Usage: DB_DATABASE=dnd_demo_<guide> WAG_URL=… WAG_TOKEN=… php guard.php [--clear-scoring-cache]
//        (the shell scripts source env.sh first, which sets WAG_URL/WAG_TOKEN, the mail stub, CACHE_STORE and an empty DB_URL)

use App\Services\ScoringRuleService;
use Illuminate\Contracts\Console\Kernel;

$repo = dirname(__DIR__, 2);
chdir($repo);
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/demo_db.php';

$fail = function (string $message): never {
    fwrite(STDERR, "refusing: $message\n");
    exit(1);
};

if ($app->configurationIsCached()) {
    $fail('the config is cached (bootstrap/cache/config.php), so the environment below would be ignored');
}
// Config, DB_URL and the live connection (select database()), see demo_db.php.
if (($problem = guideGifsDemoDatabaseProblem()) !== null) {
    $fail($problem);
}
$db = (string) getenv('DB_DATABASE');

$waUrl = (string) config('services.whatsapp.api_url');
if ($waUrl !== getenv('WAG_URL') || preg_match('#^http://127\.0\.0\.1:\d+/?$#', $waUrl) !== 1) {
    $fail('the WhatsApp gateway URL is not the local stub from env.sh (source env.sh before booting the app)');
}
if ((string) config('services.whatsapp.api_key') !== getenv('WAG_TOKEN')) {
    $fail('the WhatsApp token is not the dummy token from env.sh');
}
// Mail: only the sendmail mailer pointed at this folder's mail-stub.php (it writes to .work, never relays).
$mailer = (string) config('mail.default');
$expectedSendmail = 'php '.__DIR__.'/mail-stub.php -t -i';
if ($mailer !== 'sendmail' || (string) config('mail.mailers.sendmail.transport') !== 'sendmail') {
    $fail("mail.default is $mailer, expected the sendmail mailer from env.sh");
}
if ((string) config('mail.mailers.sendmail.path') !== $expectedSendmail || getenv('MAIL_SENDMAIL_PATH') !== $expectedSendmail) {
    $fail('the sendmail path is not the local mail-stub.php from env.sh');
}
if (! str_ends_with((string) config('mail.from.address'), '@example.com')) {
    $fail('the mail sender is not the example.com address from env.sh');
}

if (in_array('--clear-scoring-cache', $argv, true)) {
    ScoringRuleService::clearCache();
}

echo "guard ok: $db, WhatsApp -> $waUrl (local stub), mail -> mail-stub.php (.work/mail-outbox.jsonl)\n";
