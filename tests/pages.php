<?php
// Offline tests of the Logs, Help and About tabs through the real route table (child processes, tests/_child.php):
// admin-only access, rendered content, escaping, the LICENSE text and the navigation. Run: php tests/pages.php
declare(strict_types=1);
require __DIR__ . '/_env.php';

putenv('GADS_DATA_DIR=' . ($dataDir = make_data_dir()));
require dirname(__DIR__) . '/app/bootstrap.php';

$fail = 0;
$check = checker($fail);
mkdir($dataDir . '/logs', 0770, true);
file_put_contents($dataDir . '/logs/app-' . gmdate('Y-m-d') . '.log', json_encode(
    ['ts' => '2026-11-28T01:00:00Z', 'type' => 'admin_login', 'ok' => false, 'ip' => '203.0.113.9', 'result' => '<b>x</b> ?t=SECRETTOKEN']) . "\n");

/** Dispatches GET $path through the route table; returns [status, body]. */
$get = function (string $path, bool $admin, array $query = []) use ($dataDir): array {
    $out = call_controller($dataDir, ['dispatch' => true, 'admin' => $admin, 'get' => $query, 'server' => ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path]]);
    [$body, $headers] = array_pad(explode("\n--HEADERS--\n", $out, 2), 2, '');
    preg_match('/^Status: (\d+)/m', $headers, $s);
    return [(int) ($s[1] ?? 0), $body];
};
$noPhpErrors = fn(string $body): bool => !preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error)\b.*on line/i', $body);

$routes = [];
foreach (require APP_PATH . '/routes.php' as $route) {
    $routes[$route[0] . ' ' . $route[1]] = true;
}
foreach (['/logs' => 'Logs', '/help' => 'Help', '/about' => 'About'] as $path => $title) {
    $check("route: GET $path is registered", isset($routes["GET $path"]));
    [$status, $body] = $get($path, false);
    $check("$path: anonymous visitors get a redirect, not the page", $status === 302 && !str_contains($body, "<h1>$title</h1>"), "status $status");
    [$status, $body] = $get($path, true);
    $check("$path: admin gets the page (200)", $status === 200 && str_contains($body, "<h1>$title</h1>"), "status $status");
    $check("$path: no PHP errors in the output", $noPhpErrors($body));
    $check("$path: the menu marks the current tab", str_contains($body, 'href="' . url($path) . '" aria-current="page"') || str_contains($body, 'href="' . $path . '" aria-current="page"'));
    $check("$path: no inline script without a nonce", !preg_match('/<script(?![^>]*nonce=)/', $body));
}

// ---- Logs ----
[, $logs] = $get('/logs', true);
$check('logs: events are listed, tokens masked, HTML escaped', str_contains($logs, 'admin_login') && !str_contains($logs, 'SECRETTOKEN') && str_contains($logs, '&lt;b&gt;x&lt;/b&gt;') && !str_contains($logs, '<b>x</b>'));
$check('logs: failures are highlighted', str_contains($logs, 'class="failed"'));
[, $failed] = $get('/logs', true, ['f' => 'connections']);
$check('logs: the filter works on the server (connections shows no login)', str_contains($failed, 'No events to show') && !str_contains($failed, 'admin_login <'));
[, $bad] = $get('/logs', true, ['f' => '<script>']);
$check('logs: an invalid filter is ignored', str_contains($bad, 'admin_login') && !str_contains($bad, '<script>'));

// ---- Help ----
[, $help] = $get('/help', true);
foreach (['what', 'connect', 'talk', 'look', 'change', 'write', 'media', 'create', 'conversions', 'safety', 'trouble'] as $id) {
    $check("help: section #$id has a menu link and an anchor", str_contains($help, "href=\"#$id\"") && str_contains($help, "id=\"$id\""));
}
$check('help: every tool named in the text exists', (function () use ($help): bool {
    preg_match_all('/\b((?:prepare|list|get|show|verify|account|campaign|search|ads|pmax|change|keyword|ga4|lp2|recent|upload|run)_[a-z0-9_]+)\b/', $help, $m);
    $tools = array_keys(\App\Lib\ToolRegistry::all());
    return array_diff(array_unique($m[1]), $tools) === [];
})());

// ---- About ----
[, $about] = $get('/about', true);
$licenseFile = trim((string) file_get_contents(dirname(__DIR__) . '/LICENSE'));
$check('about: shows the LICENSE file content (escaped)', str_contains($about, htmlspecialchars($licenseFile, ENT_QUOTES | ENT_SUBSTITUTE)));
$check('about: shows the version', str_contains($about, \App\Lib\Version::current()));
$check('about: author and external links are safe', str_contains($about, 'Tomasz Kalinowski')
    && str_contains($about, 'href="https://github.com/t7jk/google-ads-php" target="_blank" rel="noopener noreferrer"')
    && str_contains($about, 'href="https://x.com/tomas3man" target="_blank" rel="noopener noreferrer"'));
$check('about: disclaimer present', str_contains($about, 'THE SOFTWARE IS PROVIDED "AS IS"') && str_contains($about, 'takes no responsibility'));

remove_dir($dataDir);
exit($fail === 0 ? 0 : 1);
