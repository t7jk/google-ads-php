<?php
declare(strict_types=1);

namespace App\Lp2;

/**
 * Host allow-list (config allowed_url_hosts) and resolving relative URLs (port of checkUrlHost from src/plans/guard.ts
 * + new URL(rel, base)). Shared by verify_lp2, lp2_leads and image downloads.
 * Note for the Plans agent: the same host rule; optionally plug your guard in here instead of duplicating it.
 */
final class UrlHost
{
    /** @return list<string> */
    public static function allowed(): array
    {
        return array_values(array_map('strval', (array) config('allowed_url_hosts', [])));
    }

    /** Error message or null: https only and a host from the list (www. prefix also accepted). */
    public static function check(string $url): ?string
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            return "Invalid URL: $url";
        }
        $host = strtolower($p['host']);
        $ok = strtolower($p['scheme']) === 'https' && array_filter(self::allowed(), fn($h) => $host === $h || $host === "www.$h");
        return $ok ? null : 'The URL must be https on the domain ' . implode(', ', self::allowed()) . ": $url";
    }

    /** Whether the URL is https and on an allowed domain. */
    public static function isAllowed(string $url): bool
    {
        return self::check($url) === null;
    }

    /** Equivalent of `new URL($ref, $base).href`; null when an absolute URL cannot be built. */
    public static function resolve(string $ref, string $base): ?string
    {
        $ref = trim($ref);
        $b = parse_url($base);
        if ($b === false || !isset($b['scheme'], $b['host'])) {
            return null;
        }
        $origin = strtolower($b['scheme']) . '://' . strtolower($b['host']) . (isset($b['port']) ? ':' . $b['port'] : '');
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $ref)) {
            return self::normalize($ref);
        }
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $ref)) {
            return $ref; // data:, javascript:, mailto: — absolute, no host (check() rejects them)
        }
        if (str_starts_with($ref, '//')) {
            return self::normalize(strtolower($b['scheme']) . ':' . $ref);
        }
        $basePath = $b['path'] ?? '/';
        if (str_starts_with($ref, '/')) {
            return self::normalize($origin . $ref);
        }
        if (str_starts_with($ref, '?')) {
            return self::normalize($origin . $basePath . $ref);
        }
        if (str_starts_with($ref, '#')) {
            return self::normalize($origin . $basePath . (isset($b['query']) ? '?' . $b['query'] : '') . $ref);
        }
        $dir = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        return self::normalize($origin . $dir . $ref);
    }

    /** Lowercase host, default path "/", removal of . and .. segments; fragment (#...) kept as in URL.href. */
    private static function normalize(string $url): ?string
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            return null;
        }
        $segments = [];
        foreach (explode('/', $p['path'] ?? '/') as $i => $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.' && !($i === 0 && $seg === '')) {
                $segments[] = $seg;
            }
        }
        $path = '/' . implode('/', $segments);
        return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '')
            . $path . (isset($p['query']) ? '?' . $p['query'] : '') . (isset($p['fragment']) ? '#' . $p['fragment'] : '');
    }
}
