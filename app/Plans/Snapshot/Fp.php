<?php
declare(strict_types=1);

namespace App\Plans\Snapshot;

use App\Plans\Json;

/** Shared account-state fingerprint helpers (port of VOLATILE/clean from build-changes.ts). */
final class Fp
{
    /** Fields computed by Google (they change without user action); excluded from the fingerprint. */
    public const VOLATILE = ['policySummaryInfo', 'policySummary', 'assetPerformanceLabel', 'approvalStatus', 'reviewStatus', 'primaryStatus', 'primaryStatusReasons', 'primaryStatusDetails', 'adStrength', 'disapprovalReasons'];

    public static function clean(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map([self::class, 'clean'], $v);
        }
        $out = [];
        foreach ($v as $k => $x) {
            if (!in_array($k, self::VOLATILE, true)) {
                $out[$k] = self::clean($x);
            }
        }
        return $out;
    }

    /** Sort by a string key (byte comparison, like < on strings in JS for the BMP). */
    public static function sortBy(array $rows, callable $key): array
    {
        usort($rows, fn($a, $b) => strcmp($key($a), $key($b)));
        return $rows;
    }

    public static function finish(mixed $data): string
    {
        return Json::fingerprint(self::clean($data));
    }
}
