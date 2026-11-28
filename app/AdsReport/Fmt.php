<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Ads\AdsApiError;

/**
 * Small helpers shared by the ads_report views (port of src/mcp/ads-report/types.ts).
 * Pitfall: JS does not distinguish 12 from 12.0, but PHP json_encode does ("12.0") - so every number goes through norm().
 */
final class Fmt
{
    /** Integer (int) when the value is whole; otherwise float. */
    public static function norm(float $v): int|float
    {
        return is_finite($v) && floor($v) === $v && abs($v) < 1e15 ? (int) $v : $v;
    }

    /** JS Number(v) || 0. */
    public static function num(mixed $v): int|float
    {
        if (is_bool($v)) {
            return (int) $v;
        }
        return is_numeric($v) ? self::norm((float) $v) : 0;
    }

    /** JS Math.round(n * 100) / 100 (rounds .5 up, also for negatives). */
    public static function r2(int|float $n): int|float
    {
        return self::norm(floor($n * 100 + 0.5) / 100);
    }

    /** Micros -> PLN (2 decimals); null/"" -> null. (Method name "zl" kept for compatibility.) */
    public static function zl(mixed $micros): int|float|null
    {
        return $micros === null || $micros === '' ? null : self::r2(self::num($micros) / 1e6);
    }

    /** Last segment of a resource name ("customers/1/campaigns/5" -> "5"). */
    public static function lastId(mixed $resource): string
    {
        $parts = explode('/', (string) ($resource ?? ''));
        return end($parts);
    }

    /** Value from a nested array by path "a.b.c" (missing/null -> $default). */
    public static function get(mixed $a, string $path, mixed $default = null): mixed
    {
        foreach (explode('.', $path) as $k) {
            if (!is_array($a) || !array_key_exists($k, $a)) {
                return $default;
            }
            $a = $a[$k];
        }
        return $a ?? $default;
    }

    /** List from a field that should be an array (otherwise empty). */
    public static function list(mixed $v): array
    {
        return is_array($v) ? array_values($v) : [];
    }

    /** "YYYY-MM-DD" from the start of a date string, or null. */
    public static function day(mixed $v): ?string
    {
        $s = (string) ($v ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $s) ? substr($s, 0, 10) : null;
    }

    /** Range label: "from – to" or "whole period". */
    public static function rangeLabel(?array $b): string
    {
        return $b ? "{$b['from']} – {$b['to']}" : 'whole period';
    }

    /** String comparison like JS localeCompare (ICU when available). */
    public static function cmp(string $a, string $b): int
    {
        static $collator = null;
        $collator ??= class_exists(\Collator::class) ? new \Collator('en_US') : false;
        return $collator ? (int) $collator->compare($a, $b) : strcmp($a, $b);
    }

    /** Day shifted by $n days (YYYY-MM-DD). */
    public static function shiftDay(string $d, int $n): string
    {
        return gmdate('Y-m-d', strtotime("$d 00:00:00 UTC") + $n * 86400);
    }

    /** Access denied to a resource (Explorer account, missing role, no billing): shown as a message instead of an error. */
    public static function isAccessDenied(\Throwable $e): bool
    {
        if (!$e instanceof AdsApiError) {
            return false;
        }
        $denyCodes = ['AUTHORIZATION_ERROR', 'PERMISSION_DENIED', 'CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION', 'USER_PERMISSION_DENIED', 'CUSTOMER_NOT_ENABLED', 'ACCESS_DENIED', 'NOT_ALLOWLISTED_FOR_THIS_FEATURE'];
        return $e->status === 403 || preg_match('/explorer access/i', $e->getMessage()) === 1 || array_intersect($denyCodes, $e->codes) !== [];
    }

    /** Runs a read; on access denial returns denied=true instead of throwing (other errors propagate). */
    public static function guarded(callable $fn): array
    {
        try {
            return ['value' => $fn(), 'denied' => false];
        } catch (\Throwable $e) {
            if (self::isAccessDenied($e)) {
                return ['value' => null, 'denied' => true];
            }
            throw $e;
        }
    }
}
