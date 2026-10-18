<?php

// Local stand-in for the WagHub WhatsApp gateway during guide recordings (php -S router; wa-stub.sh starts it).
// It never forwards anything: messages only end up in .work/wa-outbox.jsonl, so a flow can read the OTP the app sent.
//
//   POST /api/v1/messages   Same request as App\Services\WhatsAppService::send. Needs "Authorization: Bearer <WAG_TOKEN
//                           from env.sh>", answers 200 JSON (WhatsAppService only checks for a 2xx status) and appends
//                           {time, time_ms, recipient, text} to the outbox. Headers are never stored or logged.
//   GET  /last?phone=081200000101[&since=<unix ms>]
//                           Latest message for that number (08…, 62… or +62… spelling) received at or after `since`;
//                           404 when there is none yet. lib.js readOtp() polls this.
//   GET  /health            "wa-stub ok" (serve.sh uses it to see whether the stub is already running).

$expectedToken = (string) getenv('WAG_TOKEN');
$workDir = getenv('GUIDE_GIFS_WORK_DIR') ?: __DIR__.'/.work';
$outbox = $workDir.'/wa-outbox.jsonl';

$respond = function (int $status, array|string $body): bool {
    http_response_code($status);
    if (is_string($body)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
    } else {
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return true;
};

// Local 08… form, like WhatsAppService::normalizePhoneNumber, so any spelling of the demo number matches.
$local = function (?string $phone): string {
    $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
    if (str_starts_with($digits, '0062')) {
        $digits = '0'.substr($digits, 4);
    } elseif (str_starts_with($digits, '62')) {
        $digits = '0'.substr($digits, 2);
    } elseif (str_starts_with($digits, '8')) {
        $digits = '0'.$digits;
    }

    return $digits;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && $path === '/health') {
    return $respond(200, 'wa-stub ok');
}

if ($method === 'POST' && rtrim($path, '/') === '/api/v1/messages') {
    if ($expectedToken === '') {
        return $respond(500, ['success' => false, 'message' => 'wa-stub: WAG_TOKEN is not set (start it with wa-stub.sh)']);
    }
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (! hash_equals('Bearer '.$expectedToken, $auth)) {
        return $respond(401, ['success' => false, 'message' => 'wa-stub: invalid token']);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    $recipient = is_array($body) ? ($body['recipient']['value'] ?? null) : null;
    $text = is_array($body) ? ($body['message']['text'] ?? null) : null;
    if (! is_string($recipient) || ! is_string($text)) {
        return $respond(422, ['success' => false, 'message' => 'wa-stub: recipient.value and message.text are required']);
    }
    $ms = (int) floor(microtime(true) * 1000);
    @mkdir($workDir, 0777, true);
    file_put_contents($outbox, json_encode([
        'time' => date('c', intdiv($ms, 1000)),
        'time_ms' => $ms,
        'recipient' => $local($recipient),
        'text' => $text,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);

    return $respond(200, ['success' => true, 'data' => ['id' => 'stub-'.$ms, 'status' => 'sent']]);
}

if ($method === 'GET' && $path === '/last') {
    $phone = $local($_GET['phone'] ?? '');
    $since = (int) ($_GET['since'] ?? 0);
    if ($phone === '') {
        return $respond(400, ['message' => 'wa-stub: ?phone= is required']);
    }
    $last = null;
    foreach (is_file($outbox) ? file($outbox, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        $message = json_decode($line, true);
        if (is_array($message) && ($message['recipient'] ?? null) === $phone && (int) ($message['time_ms'] ?? 0) >= $since) {
            $last = $message;
        }
    }

    return $last === null ? $respond(404, ['message' => 'no message yet']) : $respond(200, $last);
}

return $respond(404, ['message' => 'wa-stub: unknown route']);
