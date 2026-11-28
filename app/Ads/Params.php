<?php
declare(strict_types=1);

namespace App\Ads;

/**
 * Port of src/mcp/params.ts. A client with a stale schema sends numbers and booleans as text - we accept only
 * explicit forms: digits and "true"/"false". Reads tool arguments with a default value and range.
 */
final class Params
{
    public static function textNumber(mixed $v): mixed
    {
        return is_string($v) && preg_match('/^\s*\d+\s*$/', $v) ? (int) $v : $v;
    }

    public static function textBool(mixed $v): mixed
    {
        return $v === 'true' ? true : ($v === 'false' ? false : $v);
    }

    /** Integer within [min, max]; missing -> $default; out of range / wrong type -> UserError (like zod validation). */
    public static function int(array $args, string $key, int $min, int $max, int $default): int
    {
        if (!array_key_exists($key, $args) || $args[$key] === null) {
            return $default;
        }
        $v = self::textNumber($args[$key]);
        if (!is_int($v) && !(is_float($v) && floor($v) === $v)) {
            throw new UserError("Parameter $key must be an integer");
        }
        if ($v < $min || $v > $max) {
            throw new UserError("Parameter $key must be in the range $min-$max");
        }
        return (int) $v;
    }

    public static function bool(array $args, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $args) || $args[$key] === null) {
            return $default;
        }
        $v = self::textBool($args[$key]);
        if (!is_bool($v)) {
            throw new UserError("Parameter $key must be true or false");
        }
        return $v;
    }
}
