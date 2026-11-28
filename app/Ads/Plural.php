<?php
declare(strict_types=1);

namespace App\Ads;

/** English pluralisation (ported from the Node version src/mcp/plural.ts, which had Polish rules). */
final class Plural
{
    /** |n| == 1 -> $one, otherwise $many. $few is kept only for signature compatibility and ignored. */
    public static function plural(int|float $n, string $one, string $few, string $many): string
    {
        $a = abs((int) $n);
        return $a === 1 ? $one : $many;
    }

    /** e.g. "5 rows". */
    public static function withCount(int $n, string $one, string $few, string $many): string
    {
        return $n . ' ' . self::plural($n, $one, $few, $many);
    }
}
