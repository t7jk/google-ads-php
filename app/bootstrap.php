<?php
// App start: path constants, autoloader (App\Foo\Bar -> app/Foo/Bar.php), helpers.
// No Composer: shared hosting, upload via FTP only.
declare(strict_types=1);

define('APP_PATH', __DIR__);
// Private directory (one level above app/): production domains/<domain>/google-ads-mcp-php, dev /var/www/google-ads-php.
// webroot/index.php passes it in (APP_PRIVATE_PATH); the CLI (tests, tools) derives it from this file's location.
define('PRIVATE_PATH', defined('APP_PRIVATE_PATH') ? APP_PRIVATE_PATH : dirname(__DIR__));
// Public directory (the web root). The CLI has no web root, so it falls back to webroot/ in the repository.
define('PUBLIC_PATH', defined('APP_WWW_ROOT') ? APP_WWW_ROOT : PRIVATE_PATH . '/webroot');
// Private data (config.php, app.sqlite, logs, uploads/) lives out of the web server's reach; resolve_data_path() decides where.
// Tests and tools may point the CLI at another data directory (e.g. a temporary copy of the database) with GADS_DATA_DIR.
// Never honoured over the web: an environment variable must not be able to redirect a web request to other data.
define('DATA_PATH', (PHP_SAPI === 'cli' && getenv('GADS_DATA_DIR') !== false && getenv('GADS_DATA_DIR') !== '')
    ? rtrim((string) getenv('GADS_DATA_DIR'), '/')
    : resolve_data_path(PRIVATE_PATH));

/**
 * The two places the data directory (google-ads-mcp-php-data) may be, relative to the installation directory ($privatePath):
 * 'local' = inside it (the classic layout; also the only option when the web server may not write above it) and
 * 'outside' = next to it, one level up (safer in the single-directory "easy" layout, where the installation directory is served).
 * @return array{local:string, outside:string}
 */
function data_dir_candidates(string $privatePath): array
{
    return ['local' => $privatePath . '/google-ads-mcp-php-data', 'outside' => dirname($privatePath) . '/google-ads-mcp-php-data'];
}

/**
 * The ONE place that decides DATA_PATH. Rule: the candidate that already holds data (config.php or app.sqlite) wins, local first
 * (so every existing installation keeps its directory); with no data yet, an existing writable outside directory is used
 * (the installer creates it when that is safe, see Install\DataDir), otherwise the local one.
 * Pitfall: on a fresh install the answer changes once the installer has created the outside directory, so the installer
 * redirects after creating it. open_basedir may forbid looking above the installation directory: the @ is intended.
 */
function resolve_data_path(string $privatePath): string
{
    $candidates = data_dir_candidates($privatePath);
    foreach ($candidates as $dir) {
        if (@is_file($dir . '/config.php') || @is_file($dir . '/app.sqlite')) {
            return $dir;
        }
    }
    return @is_dir($candidates['outside']) && @is_writable($candidates['outside']) ? $candidates['outside'] : $candidates['local'];
}

umask(0002); // files in the data directory are shared with the web server group (CLI and Apache)
date_default_timezone_set('UTC'); // dates in the database are ISO 8601 UTC; the Ads account time zone is passed explicitly (Ads\Time)
mb_internal_encoding('UTF-8');

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_PATH . '/helpers.php';

ini_set('display_errors', config('debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', DATA_PATH . '/php-error.log');
secure_error_log(DATA_PATH . '/php-error.log');

// Unhandled exception in a web page: written to the log, with a readable 500 page instead of a blank screen.
// In the CLI we keep the default behavior (the full message on screen).
if (PHP_SAPI !== 'cli') {
    header('X-Request-Id: ' . request_id());
    set_exception_handler(function (\Throwable $e): void {
        log_exception($e, ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '')); // log_exception masks ?t= tokens
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo '<!doctype html><meta charset="utf-8"><title>Server error</title><h1>Server error</h1>'
            . '<p>An error occurred. Details are in the server log (request id ' . htmlspecialchars(request_id()) . ').</p>';
    });
}
