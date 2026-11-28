<?php
// Front controller: all requests (except static files) pass through here. See app/routes.php.
// The public directory (this file) and the private one (app/, data, config) are separate:
// only webroot/ is ever served; the application code and data live one level above it.
// The release package (tools/build-release.sh) uses ONE directory instead: this file, .htaccess and assets/ next to app/.
declare(strict_types=1);

// Locate the application directory (the one that contains app/bootstrap.php). Order:
//  1. APP_PRIVATE_DIR env, 2. production "google-ads-mcp-php" next to public_html, 3. dev /var/www/google-ads-php
//     (the public dir is /var/www/html/google-ads-php) -- the separate public/private layout (docs/adr/0001);
//  4. __DIR__ itself: the single-directory ("easy") layout of the release package, where app/ lies next to this file
//     (docs/adr/0008). It is LAST so that a separate layout never silently switches to a stray app/ in the public directory.
// We pick the first that contains app/bootstrap.php (the name alone is not enough: on dev /var/www/html/google-ads-php exists too).
$candidates = array_filter([
    getenv('APP_PRIVATE_DIR') ?: null,
    dirname(__DIR__) . '/google-ads-mcp-php',
    dirname(__DIR__, 2) . '/google-ads-php',
    __DIR__,
]);
$privateDir = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate . '/app/bootstrap.php')) {
        $privateDir = $candidate;
        break;
    }
}
if ($privateDir === null) {
    http_response_code(500);
    exit('Application directory not found (set APP_PRIVATE_DIR).');
}

define('APP_WWW_ROOT', __DIR__); // public directory; bootstrap.php exposes it as PUBLIC_PATH
define('APP_PRIVATE_PATH', $privateDir);

require $privateDir . '/app/bootstrap.php';

App\Lib\Router::dispatch(require APP_PATH . '/routes.php');
