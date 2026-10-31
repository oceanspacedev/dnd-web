<?php

// Local sendmail stub for the guide recordings. env.sh points the app's sendmail mailer here
// (MAIL_MAILER=sendmail, MAIL_SENDMAIL_PATH="php <this file> -t -i"), so the "Email" login button shows up like in
// production while no message ever leaves the machine.
//
// Symfony's SendmailTransport runs: php mail-stub.php -i -f'<sender>' -- '<recipient>'... and writes the raw message
// to stdin. This script only appends {time, time_ms, recipients, raw} to .work/mail-outbox.jsonl; it never relays.
// lib.js reads it back (rec.readEmail) to get the login code the app sent.

$work = getenv('GUIDE_GIFS_WORK_DIR') ?: __DIR__.'/.work';
if (! is_dir($work) && ! mkdir($work, 0777, true) && ! is_dir($work)) {
    fwrite(STDERR, "mail-stub: cannot create $work\n");
    exit(1);
}

$args = array_slice($argv, 1);
$separator = array_search('--', $args, true);
$recipients = $separator === false ? [] : array_values(array_slice($args, $separator + 1));

$raw = stream_get_contents(STDIN);
if ($raw === false) {
    fwrite(STDERR, "mail-stub: cannot read the message\n");
    exit(1);
}

$entry = [
    'time' => date('c'),
    'time_ms' => (int) floor(microtime(true) * 1000),
    'recipients' => $recipients,
    'raw' => $raw,
];

file_put_contents($work.'/mail-outbox.jsonl', json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
