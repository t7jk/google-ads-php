<?php
declare(strict_types=1);

namespace App\Ads;

/** Port src/ads/money.ts. */
final class Money
{
    /** PLN -> micros (rounded to the grosz/cent), returned as a string (int64 in Google Ads JSON). */
    public static function plnToMicros(float $pln): string
    {
        $cents = (int) round((float) sprintf('%.12G', $pln * 100));
        return (string) ($cents * 10000);
    }

    public static function microsToPln(string|int|float $micros): float
    {
        return (float) $micros / 1_000_000;
    }
}
