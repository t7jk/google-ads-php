<?php
declare(strict_types=1);

namespace App\Report;

use App\Ads\Time;
use App\Ads\UserError;
use DateTimeInterface;

/**
 * Date range of the report tools (port of DateRange from src/ads/queries.ts + rangeSchema).
 * Representation: the text LAST_7_DAYS | LAST_30_DAYS | LAST_90_DAYS | ALL_TIME or an array ['from'=>'YYYY-MM-DD','to'=>'YYYY-MM-DD'].
 */
final class Range
{
    private const NAMES = ['LAST_7_DAYS', 'LAST_30_DAYS', 'LAST_90_DAYS', 'ALL_TIME'];
    private const DATE_RE = '/^\d{4}-\d{2}-\d{2}\z/'; // \z, not $: $ lets a trailing "\n" through (GAQL injection)
    public const DESCRIPTION = 'Date range: LAST_7_DAYS, LAST_30_DAYS, LAST_90_DAYS, ALL_TIME or {from,to} in YYYY-MM-DD format';

    /** inputSchema fragment for the range parameter ($description allows passing a shortened description). */
    public static function schema(string $description = self::DESCRIPTION): array
    {
        $date = ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'];
        return [
            'default' => 'LAST_30_DAYS',
            'description' => $description,
            'anyOf' => [
                ['type' => 'string', 'enum' => self::NAMES],
                ['type' => 'object', 'properties' => ['from' => $date, 'to' => $date], 'required' => ['from', 'to']],
            ],
        ];
    }

    /** The only place that validates a date going into GAQL: strict YYYY-MM-DD format + a real calendar date. */
    public static function assertDate(mixed $d): string
    {
        if (!is_string($d) || !preg_match(self::DATE_RE, $d)
            || !checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) {
            throw new UserError('Date must have the format YYYY-MM-DD and be a valid calendar date');
        }
        return $d;
    }

    private static function isDate(string $d): bool
    {
        try {
            self::assertDate($d);
            return true;
        } catch (UserError) {
            return false;
        }
    }

    /** Reads and validates the range argument (missing = LAST_30_DAYS). */
    public static function fromArgs(array $args): string|array
    {
        $r = $args['range'] ?? 'LAST_30_DAYS';
        if (is_string($r) && in_array($r, self::NAMES, true)) {
            return $r;
        }
        if (is_array($r) && isset($r['from'], $r['to']) && is_string($r['from']) && is_string($r['to'])
            && self::isDate($r['from']) && self::isDate($r['to'])) {
            return ['from' => $r['from'], 'to' => $r['to']];
        }
        throw new UserError('Parameter range must be one of: LAST_7_DAYS, LAST_30_DAYS, LAST_90_DAYS, ALL_TIME or {from,to} in YYYY-MM-DD format');
    }

    public static function label(string|array $r): string
    {
        return is_array($r) ? "{$r['from']} – {$r['to']}" : $r;
    }

    /** WHERE fragment for segments.date ("" for ALL_TIME), prefixed with " AND ". LAST_90_DAYS: UTC dates (as in Node). */
    public static function dateClause(string|array $r, DateTimeInterface $now): string
    {
        if ($r === 'ALL_TIME') {
            return '';
        }
        if ($r === 'LAST_90_DAYS') {
            $from = gmdate('Y-m-d', $now->getTimestamp() - 90 * 86400);
            return " AND segments.date BETWEEN '$from' AND '" . gmdate('Y-m-d', $now->getTimestamp()) . "'";
        }
        if ($r === 'LAST_7_DAYS' || $r === 'LAST_30_DAYS') {
            return " AND segments.date DURING $r";
        }
        if (is_string($r)) {
            throw new \InvalidArgumentException("Unsupported date range: $r");
        }
        return " AND segments.date BETWEEN '" . self::assertDate($r['from']) . "' AND '" . self::assertDate($r['to']) . "'";
    }

    /** YYYY-MM-DD dates in the account time zone; null for ALL_TIME. LAST_7/30_DAYS end yesterday (as in Google Ads). */
    public static function dailyBounds(string|array $r, DateTimeInterface $now, string $tz): ?array
    {
        if ($r === 'ALL_TIME') {
            return null;
        }
        $today = Time::dateInZone($now, $tz);
        $shift = fn(string $d, int $n): string => gmdate('Y-m-d', strtotime("$d 00:00:00 UTC") + $n * 86400);
        return match (true) {
            $r === 'LAST_7_DAYS' => ['from' => $shift($today, -7), 'to' => $shift($today, -1)],
            $r === 'LAST_30_DAYS' => ['from' => $shift($today, -30), 'to' => $shift($today, -1)],
            $r === 'LAST_90_DAYS' => ['from' => $shift($today, -90), 'to' => $today],
            is_string($r) => throw new \InvalidArgumentException("Unsupported date range: $r"),
            default => ['from' => $r['from'], 'to' => $r['to']],
        };
    }

    /** Previous period of the same length, or the same period a year earlier; null for ALL_TIME ($b = null). */
    public static function compareBounds(?array $b, string $mode): ?array
    {
        if (!$b) {
            return null;
        }
        $t = fn(string $s): int => strtotime("$s 00:00:00 UTC");
        $f = fn(int $ts): string => gmdate('Y-m-d', $ts);
        if ($mode === 'previous_year') {
            // As in Node: the same day a year earlier; Feb 29 -> Feb 28 (if the month shifted, step back to the end of the previous month).
            $shift = function (string $s): string {
                $d = new \DateTimeImmutable("$s 00:00:00 UTC");
                $month = (int) $d->format('n');
                $y = $d->setDate((int) $d->format('Y') - 1, $month, (int) $d->format('j'));
                return (int) $y->format('n') !== $month ? $y->modify('last day of previous month')->format('Y-m-d') : $y->format('Y-m-d');
            };
            return ['from' => $shift($b['from']), 'to' => $shift($b['to'])];
        }
        $days = (int) round(($t($b['to']) - $t($b['from'])) / 86400) + 1;
        return ['from' => $f($t($b['from']) - $days * 86400), 'to' => $f($t($b['from']) - 86400)];
    }
}
