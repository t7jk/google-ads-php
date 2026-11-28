<?php
declare(strict_types=1);

namespace App\Ads;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Port src/ads/time.ts: time conversion using an explicitly given time zone (not the process time zone). */
final class Time
{
    /** Maximum change_event reach in the API: the range start cannot be older than 29 days. */
    public const CHANGE_EVENT_MAX_DAYS = 30;

    /** "YYYY-MM-DD HH:mm:ss[.ffffff]" in zone $tz -> instant. */
    public static function zonedToDate(string $s, string $tz): DateTimeImmutable
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d+))?$/', trim($s), $m)) {
            throw new \InvalidArgumentException("Invalid date: $s");
        }
        // Like Node (Date with ms): keep the fractional second, truncated to milliseconds.
        $ms = substr(str_pad($m[3] ?? '', 3, '0'), 0, 3);
        return new DateTimeImmutable("$m[1] $m[2].{$ms}000", new DateTimeZone($tz));
    }

    /** Instant -> "YYYY-MM-DD HH:mm:ss" in zone $tz. */
    public static function dateToZoned(DateTimeInterface $d, string $tz): string
    {
        return DateTimeImmutable::createFromInterface($d)->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i:s');
    }

    /** Calendar date YYYY-MM-DD in zone $tz. */
    public static function dateInZone(DateTimeInterface $d, string $tz): string
    {
        return substr(self::dateToZoned($d, $tz), 0, 10);
    }

    /** @return list<string> all YYYY-MM-DD dates from $from to $to inclusive (calendar, no zones; max 4000). */
    public static function eachDate(string $from, string $to): array
    {
        $out = [];
        for ($t = strtotime("$from 00:00:00 UTC"), $end = strtotime("$to 00:00:00 UTC"); $t <= $end && count($out) < 4000; $t += 86400) {
            $out[] = gmdate('Y-m-d', $t);
        }
        return $out;
    }

    /** Date range for change_event: start = today - (min(days,30) - 1) days 00:00:00; end = tomorrow 23:59:59 (account time zone). */
    public static function changeEventRange(DateTimeInterface $now, string $tz, int $days = self::CHANGE_EVENT_MAX_DAYS): array
    {
        $d = min(max($days, 1), self::CHANGE_EVENT_MAX_DAYS);
        $today = self::dateInZone($now, $tz);
        $shift = fn(int $n): string => gmdate('Y-m-d', strtotime("$today 00:00:00 UTC") + $n * 86400);
        return ['from' => $shift(-($d - 1)) . ' 00:00:00', 'to' => $shift(1) . ' 23:59:59'];
    }
}
