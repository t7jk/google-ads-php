<?php
declare(strict_types=1);

namespace App\Install;

/**
 * Where the data directory (config.php, app.sqlite, logs, uploads) goes, and keeping it out of the web's reach.
 *
 * Two places (bootstrap.php: data_dir_candidates / resolve_data_path decide at run time; this class decides at install time):
 *  - "outside": ../google-ads-mcp-php-data, one level ABOVE the installation directory. Preferred in the single-directory layout
 *    (installation directory = served directory), because the web server cannot reach it at all. Used only when that parent
 *    is writable AND is not inside the document root: with the app in public_html/gads the parent IS public_html, which is served.
 *  - "local": ./google-ads-mcp-php-data inside the installation directory. Protected by .htaccess (Apache) and checked by
 *    ProtectionProbe. In the separate public/private layout the installation directory is already private, so local is always right.
 */
final class DataDir
{
    /**
     * @return array{path:string, outside:bool, inside_install:bool, exists:bool, writable:bool}
     *         outside: the plan is the directory above the installation directory; inside_install: it lies in the installation directory.
     */
    public static function plan(): array
    {
        $path = self::choose();
        $insideInstall = str_starts_with($path . '/', rtrim(PRIVATE_PATH, '/') . '/');
        $exists = is_dir($path);
        $probeTarget = $exists ? $path : dirname($path); // not there yet: can we create it?
        return ['path' => $path, 'outside' => !$insideInstall, 'inside_install' => $insideInstall,
            'exists' => $exists, 'writable' => is_dir($probeTarget) && is_writable($probeTarget)];
    }

    /** Creates the directory (if needed) and the files that keep Apache out of it. Idempotent. Returns false when it cannot be created. */
    public static function ensure(array $plan): bool
    {
        if (!is_dir($plan['path']) && !@mkdir($plan['path'], 0770, true) && !is_dir($plan['path'])) {
            return false;
        }
        if ($plan['inside_install']) {
            self::writeGuardFiles($plan['path']);
        }
        return true;
    }

    private static function choose(): string
    {
        $candidates = data_dir_candidates(PRIVATE_PATH);
        if (self::inUse(DATA_PATH) || !self::isFlatLayout()) {
            return DATA_PATH;
        }
        return self::outsideIsSafe($candidates['outside']) ? $candidates['outside'] : $candidates['local'];
    }

    private static function inUse(string $dir): bool
    {
        return is_file($dir . '/config.php') || is_file($dir . '/app.sqlite');
    }

    /** Single-directory layout: the installation directory is the served directory. */
    private static function isFlatLayout(): bool
    {
        return realpath(PRIVATE_PATH) === realpath(PUBLIC_PATH);
    }

    /** The outside directory is usable when it exists (and is writable) or its parent can hold it, and the parent is not web-served. */
    private static function outsideIsSafe(string $outside): bool
    {
        $parent = dirname($outside);
        $usable = is_dir($outside) ? is_writable($outside) : (is_dir($parent) && is_writable($parent));
        $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $realParent = realpath($parent);
        if (!$usable || $docRoot === false || $realParent === false) {
            return false; // unknown document root: stay on the local directory, which ProtectionProbe checks
        }
        return !str_starts_with($realParent . '/', rtrim($docRoot, '/') . '/');
    }

    /** .htaccess (Apache 2.4 and 2.2) plus an empty index.html: defence in depth next to the rules in the main .htaccess. */
    private static function writeGuardFiles(string $dir): void
    {
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "# Private data: never served over HTTP.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
    }

    /** Where, relative to the installation directory, the data directory is reachable over HTTP; null when it is not under it. */
    public static function urlPath(array $plan): ?string
    {
        return $plan['inside_install'] ? basename($plan['path']) : null;
    }
}
