<?php
// Offline tests of the request-level protections: CSRF in the Router, secret masking in the event log, the rate limiter.
// A THROWAWAY data directory is used (GADS_DATA_DIR); the Router runs in child processes (tests/_child.php, spec "dispatch").
// Run: php tests/web-hardening.php
declare(strict_types=1);
require __DIR__ . '/_env.php';

putenv('GADS_DATA_DIR=' . ($dataDir = make_data_dir()));
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Lib\{Db, Events, RateLimiter};

$fail = 0;
$check = checker($fail);
ini_set('error_log', $dataDir . '/php-error.log'); // log_exception() writes there instead of the console

// ---- A. CSRF in the Router: every POST needs the session token, except the three API routes with their own credentials ----
/** Dispatches a request through the route table in a child process; returns [status, body]. */
$dispatch = function (string $method, string $path, bool $csrf = false) use ($dataDir): array {
    $out = call_controller($dataDir, ['dispatch' => true, 'csrf' => $csrf, 'server' => ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path]]);
    [$body, $headers] = array_pad(explode("\n--HEADERS--\n", $out, 2), 2, '');
    preg_match('/^Status: (\d+)/m', $headers, $s);
    return [(int) ($s[1] ?? 0), $body];
};
$isCsrfRefusal = fn(array $r): bool => $r[0] === 403 && str_contains($r[1], 'Invalid form token');
$check('csrf: a POST without a token is refused with 403', $isCsrfRefusal($dispatch('POST', '/logout')));
foreach (['/login', '/setup', '/settings/email', '/connections/add', '/connections/revoke', '/google/disconnect', '/upload', '/login/verify'] as $path) {
    $check("csrf: POST $path without a token is refused", $isCsrfRefusal($dispatch('POST', $path)), $path);
}
$check('csrf: a POST with the session token passes the check', !$isCsrfRefusal($dispatch('POST', '/logout', true)));
$check('csrf: a GET needs no token', !$isCsrfRefusal($dispatch('GET', '/health')));
foreach (['/token', '/mcp', '/plan/approve'] as $path) {
    $check("csrf: POST $path is exempt (own credentials), not a CSRF error", !$isCsrfRefusal($dispatch('POST', $path)), $path);
}
$check('csrf: the exempt routes still refuse an anonymous caller', $dispatch('POST', '/token')[0] === 401 && $dispatch('POST', '/mcp')[0] === 401);
$check('csrf: every other POST route in the table has no csrf exemption', (function (): bool {
    $exempt = [];
    foreach (require APP_PATH . '/routes.php' as $route) {
        if ($route[0] === 'POST' && ($route[3]['csrf'] ?? true) === false) {
            $exempt[] = $route[1];
        }
    }
    sort($exempt);
    return $exempt === ['/mcp', '/plan/approve', '/token'];
})());
$check('csrf: a wrong token is refused', (function () use ($dataDir): bool {
    $out = call_controller($dataDir, ['dispatch' => true, 'post' => ['_csrf' => 'forged'], 'server' => ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/logout']]);
    return str_contains($out, 'Invalid form token');
})());

// ---- B. Events: secrets are masked in the database and in the log file ----
$lastEvent = fn(string $type): array => json_decode((string) Db::one('SELECT data_json FROM events WHERE type = ? ORDER BY id DESC LIMIT 1', [$type])['data_json'], true);
Events::log('probe', [
    'token' => 'sekret-1', 'client_secret' => 'sekret-2', 'password' => 'sekret-3', 'Authorization' => 'Bearer sekret-4', 'image_base64' => 'AAAA',
    'nested' => ['refresh_token' => 'sekret-5', 'fine' => 'visible'],
    'url' => 'https://example.test/plan?id=abc&t=sekret-6&x=1', 'text' => 'login at /login/verify?t=sekret-7 now', 'code' => 'see ?code=sekret-8',
    'tool' => 'run_gaql', 'long' => str_repeat('x', 900),
]);
$event = $lastEvent('probe');
$check('events: secret-looking keys are masked', ($event['token'] ?? '') === '***' && $event['client_secret'] === '***' && $event['password'] === '***'
    && $event['Authorization'] === '***' && $event['image_base64'] === '***', json_encode($event));
$check('events: nested secret keys are masked, other nested values kept', ($event['nested']['refresh_token'] ?? '') === '***' && $event['nested']['fine'] === 'visible');
$check('events: ?t= and ?code= in text values are masked, the rest of the URL stays', $event['url'] === 'https://example.test/plan?id=abc&t=***&x=1'
    && $event['text'] === 'login at /login/verify?t=*** now' && $event['code'] === 'see ?code=***', json_encode($event));
$check('events: ordinary values stay, long strings are truncated', $event['tool'] === 'run_gaql' && mb_strlen($event['long']) < 600);
$check('events: every event carries a request id', preg_match('/^[0-9a-f]{8,}$/', (string) ($event['request_id'] ?? '')) === 1, (string) ($event['request_id'] ?? ''));
$logFile = (string) file_get_contents($dataDir . '/logs/app-' . gmdate('Y-m-d') . '.log');
$check('events: the log file never contains a secret value', !str_contains($logFile, 'sekret-') && str_contains($logFile, 'run_gaql'));
$_SERVER['REQUEST_URI'] = '/plan?id=abc&t=sekret-path';
log_exception(new RuntimeException('failed for /login/verify?t=sekret-msg'), 'ctx ?t=sekret-ctx');
$errorEvent = Db::one("SELECT data_json FROM events WHERE type = 'error' ORDER BY id DESC LIMIT 1")['data_json'];
$errorLog = (string) @file_get_contents($dataDir . '/php-error.log');
$check('events: log_exception masks ?t= in the path and the message (database and php-error.log)', !str_contains($errorEvent . $errorLog, 'sekret-')
    && str_contains($errorLog, 'RuntimeException'), $errorEvent);
unset($_SERVER['REQUEST_URI']);

// ---- C. Rate limiter ----
$hits = fn(string $bucket, int $limit, int $n): array => array_map(fn() => RateLimiter::hit($bucket, $limit, 60), range(1, $n));
$check('limiter: hits up to the limit pass, the next ones are refused', $hits('t1', 3, 5) === [true, true, true, false, false]);
$check('limiter: buckets are independent', RateLimiter::hit('t2', 3, 60) === true && RateLimiter::isLimited('t1', 3) && !RateLimiter::isLimited('t2', 3));
$check('limiter: isLimited reads without counting', (function (): bool {
    RateLimiter::hit('t3', 2, 60);
    RateLimiter::isLimited('t3', 2);
    RateLimiter::isLimited('t3', 2);
    return !RateLimiter::isLimited('t3', 2) && RateLimiter::hit('t3', 2, 60) && RateLimiter::isLimited('t3', 2);
})());
Db::exec('UPDATE rate_limits SET window_ends_at = ? WHERE bucket = ?', [now_iso(-1), 't1']);
$check('limiter: a new window starts after the old one ends', !RateLimiter::isLimited('t1', 3) && RateLimiter::hit('t1', 3, 60) && (int) Db::one('SELECT hits FROM rate_limits WHERE bucket = ?', ['t1'])['hits'] === 1);
$check('limiter: a limit of 0 refuses everything', RateLimiter::hit('t4', 0, 60) === false);
$check('limiter: enforce() ends the request with the rate_limited error', (function () use ($dataDir): bool {
    $php = 'require ' . var_export(dirname(__DIR__) . '/app/bootstrap.php', true) . ';'
        . ' for ($i = 0; $i < 3; $i++) { App\Lib\RateLimiter::enforce("enf", 2, 60); echo "pass$i\n"; }';
    $out = (string) shell_exec('GADS_DATA_DIR=' . escapeshellarg($dataDir) . ' php -r ' . escapeshellarg($php) . ' 2>&1');
    return str_contains($out, 'pass1') && !str_contains($out, 'pass2') && str_contains($out, '"error":"rate_limited"');
})());

remove_dir($dataDir);
echo $fail === 0 ? "All OK\n" : "FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
