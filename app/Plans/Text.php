<?php
declare(strict_types=1);

namespace App\Plans;

/** Small text helpers shared by the builders (port of validate-pmax.ts: normText/sameText/textLen and campaignStateLabel). */
final class Text
{
    public static function norm(string $s): string
    {
        $n = class_exists(\Normalizer::class) ? \Normalizer::normalize($s, \Normalizer::FORM_C) : $s;
        return trim($n === false ? $s : $n);
    }

    public static function same(string $a, string $b): bool
    {
        return mb_strtolower(self::norm($a)) === mb_strtolower(self::norm($b));
    }

    /** Length in code points (like [...s].length in JS for BMP/emoji text). */
    public static function len(string $s): int
    {
        return mb_strlen(self::norm($s));
    }

    /** Campaign state label shown in plans; PlanRenderer compares it with 'PAUSED', keep them in sync. */
    public static function stateLabel(?array $c): string
    {
        $status = $c['status'] ?? null;
        if ($status === 'PAUSED') {
            return 'PAUSED';
        }
        if ($status === 'ENABLED') {
            return ($c['servingStatus'] ?? null) === 'ENDED' ? 'ENABLED, ENDED' : 'ENABLED';
        }
        return (string) ($status ?? '');
    }

    /** Number from Google JSON (often an int64 string) as float. */
    public static function num(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /** Like JS String(x) for numbers: 20.0 -> "20", 0.5 -> "0.5". */
    public static function numStr(int|float|string $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return (string) $v;
    }
}
