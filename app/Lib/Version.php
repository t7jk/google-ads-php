<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * Application version, read from app/VERSION (semantic versioning, one line, e.g. "0.2.0").
 * The file lives inside app/ on purpose: it is uploaded and rolled back together with the code (see tools/deploy.sh).
 * Used by the MCP "initialize" response and by GET /health. Release steps: README, section "Releases".
 */
final class Version
{
    private static ?string $cached = null;

    public static function current(): string
    {
        if (self::$cached === null) {
            $raw = is_file(APP_PATH . '/VERSION') ? trim((string) file_get_contents(APP_PATH . '/VERSION')) : '';
            // A broken file must not break the server: fall back to a placeholder.
            self::$cached = preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $raw) === 1 ? $raw : 'unknown';
        }
        return self::$cached;
    }
}
