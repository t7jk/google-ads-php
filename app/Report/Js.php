<?php
declare(strict_types=1);

namespace App\Report;

/**
 * Small equivalents of JavaScript behaviours on which the result text depends 1:1 with the Node version
 * (Number(x)||0, Math.round, toFixed, `${x}` interpolation, nested field access via ?.).
 */
final class Js
{
    /** Number(v) || 0: numbers and numeric strings, everything else (null, "", NaN) = 0. Whole numbers are returned as int (like JS: 12, not 12.0). */
    public static function num(mixed $v): int|float
    {
        if (is_bool($v)) {
            $n = $v ? 1 : 0;
        } elseif (is_int($v) || is_float($v)) {
            $n = $v;
        } elseif (is_string($v) && is_numeric(trim($v))) {
            $n = (float) trim($v);
        } else {
            return 0;
        }
        return self::normalize((float) $n);
    }

    /** Whole number as int, fraction as float. */
    public static function normalize(float $f): int|float
    {
        return is_finite($f) && floor($f) === $f && abs($f) < 1e15 ? (int) $f : $f;
    }

    /** Math.round (halves round up). */
    public static function round(float $x): int|float
    {
        return self::normalize(floor($x + 0.5));
    }

    /** Math.round(x * 100) / 100. */
    public static function round2(float|int $x): int|float
    {
        return self::normalize(floor($x * 100 + 0.5) / 100);
    }

    /** (x).toFixed(2). */
    public static function fixed2(float|int $x): string
    {
        return number_format((float) $x, 2, '.', '');
    }

    /** `${v}` interpolation: null -> "null", bool -> true/false, numbers without ".0". */
    public static function str(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_float($v)) {
            return (string) self::normalize($v);
        }
        return (string) $v;
    }

    /** Access to a nested field by path "a.b.c" (like a?.b?.c); missing = $default. */
    public static function get(mixed $a, string $path, mixed $default = null): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($a) || !array_key_exists($key, $a)) {
                return $default;
            }
            $a = $a[$key];
        }
        return $a ?? $default;
    }

    /** JSON.stringify: without escaping Unicode characters and slashes. */
    public static function jsonLine(mixed $v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
