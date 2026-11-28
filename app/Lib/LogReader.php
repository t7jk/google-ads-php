<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * Reads the newest events from the JSONL application log (DATA_PATH/logs/app-YYYY-MM-DD.log, written by Events) for the Logs tab.
 *
 * Assumptions and pitfalls:
 * - Read-only. Only the END of each file is read (backwards, in blocks), never the whole file: a busy day can be large.
 * - Newest first. When today has fewer matching lines than the limit, older day files are read as well.
 * - A filter is applied BEFORE the limit, but at most MAX_SCAN lines are examined in total, so a rare event type cannot make
 *   the page read the whole history. The page then shows fewer rows than the limit.
 * - Every row is passed through Events::clean() again (defence in depth: a line written by an older version may be unmasked).
 * - Lines that are not valid JSON objects are skipped.
 */
final class LogReader
{
    public const LIMIT = 100;
    public const MAX_SCAN = 5000;
    public const FILTERS = ['all' => 'All', 'connections' => 'Connections', 'failed' => 'Failed', 'logins' => 'Logins'];

    private const BLOCK_BYTES = 65_536;
    private const SUMMARY_MAX = 240;
    /** Fields shown in their own columns; everything else goes into the summary. */
    private const OWN_COLUMNS = ['ts', 'type', 'request_id', 'ip', 'client', 'ok'];
    private const CONNECTION_TYPES = ['mcp_request', 'mcp_tool', 'authorize', 'token', 'connections'];
    private const LOGIN_TYPES = ['admin_login', 'login_link', 'google_login'];
    private const FAILED_TYPES = ['error', 'rate_limited'];

    /** An unknown filter value falls back to "all" (the value comes from the query string). */
    public static function normalizeFilter(string $filter): string
    {
        return array_key_exists($filter, self::FILTERS) ? $filter : 'all';
    }

    /**
     * @return list<array{ts:string,type:string,client:string,ip:string,summary:string,failed:bool}> newest first, at most $limit rows
     */
    public static function recent(string $filter = 'all', int $limit = self::LIMIT, ?string $dir = null): array
    {
        $filter = self::normalizeFilter($filter);
        $rows = [];
        $scanned = 0;
        foreach (self::files($dir ?? DATA_PATH . '/logs') as $file) {
            foreach (self::tailLines($file, min(self::MAX_SCAN - $scanned, self::MAX_SCAN)) as $line) {
                $scanned++;
                $row = self::parse($line);
                if ($row !== null && self::matches($row, $filter)) {
                    $rows[] = self::present($row);
                    if (count($rows) >= $limit) {
                        return $rows;
                    }
                }
            }
            if ($scanned >= self::MAX_SCAN) {
                break;
            }
        }
        return $rows;
    }

    /** @return list<string> log files, newest day first (the date is part of the name, so a name sort is a date sort) */
    private static function files(string $dir): array
    {
        $files = glob($dir . '/app-????-??-??.log') ?: [];
        rsort($files);
        return $files;
    }

    /**
     * The last $max non-empty lines of a file, newest first. Reads blocks from the end until enough lines are found.
     * @return list<string>
     */
    private static function tailLines(string $file, int $max): array
    {
        $handle = $max > 0 ? @fopen($file, 'rb') : false;
        if ($handle === false) {
            return [];
        }
        $position = (int) filesize($file);
        $buffer = '';
        while ($position > 0 && substr_count($buffer, "\n") <= $max) {
            $step = min(self::BLOCK_BYTES, $position);
            $position -= $step;
            fseek($handle, $position);
            $buffer = (string) fread($handle, $step) . $buffer;
        }
        fclose($handle);
        $lines = explode("\n", $buffer);
        if ($position > 0) {
            array_shift($lines); // the first line was cut by the block boundary
        }
        $lines = array_values(array_filter($lines, fn(string $l) => trim($l) !== ''));
        return array_slice(array_reverse($lines), 0, $max);
    }

    /** @return array<string,mixed>|null the masked event, or null for a line that is not a JSON object */
    private static function parse(string $line): ?array
    {
        $data = json_decode($line, true);
        if (!is_array($data) || !isset($data['type']) || !is_string($data['type'])) {
            return null;
        }
        return Events::clean($data);
    }

    private static function matches(array $row, string $filter): bool
    {
        return match ($filter) {
            'connections' => in_array($row['type'], self::CONNECTION_TYPES, true),
            'logins' => in_array($row['type'], self::LOGIN_TYPES, true),
            'failed' => self::isFailure($row),
            default => true,
        };
    }

    /** A failure: an explicit ok=false, an error event or a rate-limit hit. */
    private static function isFailure(array $row): bool
    {
        return ($row['ok'] ?? null) === false || in_array($row['type'], self::FAILED_TYPES, true);
    }

    /** @return array{ts:string,type:string,client:string,ip:string,summary:string,failed:bool} */
    private static function present(array $row): array
    {
        return [
            'ts' => (string) ($row['ts'] ?? ''),
            'type' => $row['type'],
            'client' => is_scalar($row['client'] ?? null) ? (string) $row['client'] : '',
            'ip' => is_scalar($row['ip'] ?? null) ? (string) $row['ip'] : '',
            'summary' => self::summary($row),
            'failed' => self::isFailure($row),
        ];
    }

    /** "key: value" pairs of the fields that have no column of their own, shortened. */
    private static function summary(array $row): string
    {
        $parts = [];
        foreach ($row as $key => $value) {
            if (in_array($key, self::OWN_COLUMNS, true) || $value === null || $value === '') {
                continue;
            }
            $text = is_scalar($value) ? (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value)
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $parts[] = $key . ': ' . $text;
        }
        $summary = implode(', ', $parts);
        return mb_strlen($summary) > self::SUMMARY_MAX ? mb_substr($summary, 0, self::SUMMARY_MAX) . '…' : $summary;
    }
}
