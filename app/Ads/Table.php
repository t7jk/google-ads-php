<?php
declare(strict_types=1);

namespace App\Ads;

/** Port src/mcp/table.ts: flattening GAQL results into a compact table (TSV). Rows come from json_decode($json, true). */
final class Table
{
    /** Flattens a row: dotted paths, no resourceName; for *Micros fields adds a *Micros_zl column (in PLN; the "_zl" suffix is kept for compatibility). */
    public static function flattenRow(mixed $row): array
    {
        $out = [];
        self::walk($row, '', $out);
        return $out;
    }

    private static function walk(mixed $v, string $prefix, array &$out): void
    {
        if (is_array($v) && !array_is_list($v)) {
            foreach ($v as $k => $x) {
                if ($k !== 'resourceName') {
                    self::walk($x, $prefix === '' ? (string) $k : "$prefix.$k", $out);
                }
            }
            return;
        }
        $key = $prefix === '' ? 'value' : $prefix;
        $out[$key] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $v;
        if (str_ends_with($key, 'Micros') && $v !== null && $v !== '' && is_numeric($v)) {
            $out[$key . '_zl'] = round(((float) $v) / 10_000) / 100;
        }
    }

    /** Columns (in order of first appearance) for a set of flattened rows. */
    public static function columnsOf(array $rows): array
    {
        $cols = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $k) {
                $cols[$k] = true;
            }
        }
        return array_map('strval', array_keys($cols));
    }

    public static function tsvHeader(array $cols): string
    {
        return implode("\t", $cols);
    }

    public static function tsvLine(array $cols, array $row): string
    {
        return implode("\t", array_map(fn($c) => self::cell($row[$c] ?? null), $cols));
    }

    public static function toTsv(array $rows): string
    {
        $cols = self::columnsOf($rows);
        return implode("\n", [self::tsvHeader($cols), ...array_map(fn($r) => self::tsvLine($cols, $r), $rows)]);
    }

    /** How many rows (from the start) fit in $maxChars including the header; at least 1 if there are any. */
    public static function rowsThatFit(array $rows, int $maxChars): int
    {
        $cols = self::columnsOf($rows);
        $used = mb_strlen(self::tsvHeader($cols)) + 1;
        $n = 0;
        foreach ($rows as $row) {
            $len = mb_strlen(self::tsvLine($cols, $row)) + 1;
            if ($n > 0 && $used + $len > $maxChars) {
                break;
            }
            $used += $len;
            $n++;
        }
        return $n;
    }

    private static function cell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        $s = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        return (string) preg_replace('/[\t\r\n]+/', ' ', $s);
    }
}
