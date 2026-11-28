<?php
declare(strict_types=1);

namespace App\Install;

/**
 * The public address of the application (public_base_url): detected from the current request as a SUGGESTION only.
 * The Host header is attacker-controlled, so the suggestion is never trusted: the administrator confirms or edits it in the
 * wizard, and only the confirmed value is stored (base_url() later refuses to build links from the Host header).
 */
final class PublicUrl
{
    /** scheme://host[:port]/path of the current request up to the directory of index.php, without a trailing slash. */
    public static function detect(): string
    {
        $https = request_is_https() || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'; // proxies/CDNs (suggestion only)
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $host) !== 1) {
            $host = strtolower((string) ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        }
        return ($https ? 'https' : 'http') . '://' . $host . base_path();
    }

    /** The normalised address (no trailing slash) or null when it is not an acceptable http(s) address. */
    public static function normalize(string $value): ?string
    {
        $value = rtrim(trim($value), '/');
        $parts = parse_url($value);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/^[a-z0-9.-]+$/i', $parts['host']) !== 1 || preg_match('/[\s<>"\']/', $value) === 1) {
            return null;
        }
        return $value;
    }

    public static function isHttps(string $normalized): bool
    {
        return str_starts_with($normalized, 'https://');
    }
}
