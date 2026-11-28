<?php
declare(strict_types=1);

namespace App\Ads;

/** Port of src/ads/status.ts: descriptions of primary_status reasons. */
final class Status
{
    public const UNKNOWN_REASON_TEXT = 'Google does not disclose the reason in API v26 - check in the Google Ads UI';

    public static function reasonText(mixed $reason): string
    {
        $r = is_scalar($reason) ? (string) $reason : '';
        return $r === 'UNKNOWN' || $r === '' || $r === '0' ? 'UNKNOWN (' . self::UNKNOWN_REASON_TEXT . ')' : $r;
    }

    /** @return list<string> */
    public static function reasonsText(mixed $reasons): array
    {
        return array_map([self::class, 'reasonText'], is_array($reasons) ? array_values($reasons) : []);
    }
}
