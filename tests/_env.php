<?php
// Shared helpers of the stage-2 tests (not a test itself). Everything runs against a THROWAWAY data directory
// (GADS_DATA_DIR, honoured by bootstrap.php in the CLI only): the dev database is never opened or written.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
} // CLI only, never via the web

/** A fresh empty data directory with a config.php (random secret_key); returns its path. Removed by remove_dir() at the end. */
function make_data_dir(array $config = []): string
{
    $dir = sys_get_temp_dir() . '/gads-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0770, true);
    $config += ['secret_key' => base64_encode(random_bytes(32)), 'public_base_url' => 'https://example.test', 'admin_email' => 'admin@example.org', 'debug' => false];
    file_put_contents($dir . '/config.php', '<?php return ' . var_export($config, true) . ';');
    return $dir;
}

function remove_dir(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) {
            continue;
        }
        is_dir($f) ? remove_dir($f) : @unlink($f);
    }
    @rmdir($dir);
}

/** Returns a check function that prints OK/FAIL lines and counts failures in $fail. */
function checker(int &$fail): Closure
{
    return function (string $name, bool $ok, string $info = '') use (&$fail): void {
        echo ($ok ? 'OK   ' : 'FAIL ') . $name . ($ok ? '' : " $info") . "\n";
        $fail += $ok ? 0 : 1;
    };
}

/**
 * Runs a controller method in a separate PHP process (controllers end with exit, and the superglobals are per request).
 * $spec: class, method, post, get, server, admin (bool: log in as admin first). Returns stdout (the page or JSON) plus a
 * "--HEADERS--" block with the response headers.
 */
function call_controller(string $dataDir, array $spec): string
{
    $cmd = 'GADS_DATA_DIR=' . escapeshellarg($dataDir) . ' php ' . escapeshellarg(__DIR__ . '/_child.php') . ' ' . escapeshellarg(json_encode($spec));
    return (string) shell_exec($cmd . ' 2>&1');
}
