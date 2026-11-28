<?php
declare(strict_types=1);

namespace App\Plans;

/**
 * JSON for plans. PHP pitfall: json_decode(assoc) turns {} into [], and Google Ads rejects [] where it expects an object
 * (e.g. maximizeConversions: {}). That is why plans are written and read through this class: empty objects come back as \stdClass.
 */
final class Json
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    public static function encode(mixed $v): string
    {
        return json_encode($v, self::FLAGS | JSON_THROW_ON_ERROR);
    }

    /** Decodes to associative arrays; an empty JSON object stays \stdClass (not []). */
    public static function decode(string $json): mixed
    {
        return self::arrayify(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    private static function arrayify(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map([self::class, 'arrayify'], $v);
        }
        if ($v instanceof \stdClass) {
            $vars = get_object_vars($v);
            if (!$vars) {
                return $v;
            }
            return array_map([self::class, 'arrayify'], $vars);
        }
        return $v;
    }

    /** JSON with recursively sorted (object) keys; lists keep their order. */
    public static function canonical(mixed $v): string
    {
        return json_encode(self::sortKeys($v), self::FLAGS);
    }

    private static function sortKeys(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map([self::class, 'sortKeys'], $v);
        }
        ksort($v, SORT_STRING);
        return array_map([self::class, 'sortKeys'], $v);
    }

    public static function sha256(string $s): string
    {
        return hash('sha256', $s);
    }

    /** Fingerprint of arbitrary data: sha256 of the canonical JSON. */
    public static function fingerprint(mixed $rows): string
    {
        return self::sha256(self::canonical($rows));
    }

    /** Deep copy via JSON (keeps \stdClass as an object). */
    public static function clone(mixed $v): mixed
    {
        return $v === null ? null : self::decode(self::encode($v));
    }
}
