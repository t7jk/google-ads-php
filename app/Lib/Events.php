<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * Event log (events table), read by the server_events tool.
 * Never log secrets or images: keys containing token/secret/password/authorization are masked,
 * long texts (e.g. base64) are truncated.
 */
final class Events
{
    private const MAX_STRING = 500;
    private const SECRET_KEY = '/token|secret|password|authorization|bytes|base64/i';
    private const ERROR_LOG_MAX_BYTES = 1_048_576;   // php-error.log is trimmed above this size...
    private const ERROR_LOG_KEEP_BYTES = 262_144;    // ...down to its newest part of this size

    public static function log(string $type, array $data = []): void
    {
        self::toFile($type, $data);
        try {
            Db::exec('INSERT INTO events (ts, type, data_json) VALUES (?, ?, ?)', [
                now_iso(), $type, json_encode(['request_id' => request_id()] + self::clean($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            if (random_int(1, 200) === 1) {
                self::prune();
            }
        } catch (\Throwable) {
            // The log must never break the actual operation.
        }
    }

    /**
     * A copy of the event in the file DATA_PATH/logs/app-YYYY-MM-DD.log (JSONL, one line = one event, UTC time).
     * Why: on FTP-only hosting a file can be downloaded and inspected with tools (grep), even when the database is not working.
     * The same secret mask as in the database. Retention: files older than events_retention_days are removed by prune().
     */
    private static function toFile(string $type, array $data): void
    {
        try {
            $dir = DATA_PATH . '/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }
            $row = ['ts' => now_iso(), 'type' => $type, 'request_id' => request_id(), 'ip' => PHP_SAPI === 'cli' ? 'cli' : client_ip()] + self::clean($data);
            $file = $dir . '/app-' . gmdate('Y-m-d') . '.log';
            $isNew = !is_file($file);
            @file_put_contents($file,
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
                FILE_APPEND | LOCK_EX);
            if ($isNew) {
                @chmod($file, 0660);
            }
        } catch (\Throwable) {
            // The log must never break the actual operation.
        }
    }

    /** Deletes entries older than events_retention_days (default 14), in the database and in the logs/ files; trims php-error.log. */
    public static function prune(): void
    {
        $days = (int) config('events_retention_days', 14);
        Db::exec('DELETE FROM events WHERE ts < ?', [now_iso(-$days * 86400)]);
        foreach (glob(DATA_PATH . '/logs/app-*.log') ?: [] as $file) {
            if (filemtime($file) < time() - $days * 86400) {
                @unlink($file);
            }
        }
        self::trimErrorLog(DATA_PATH . '/php-error.log');
    }

    /**
     * Keeps php-error.log from growing without bound (no logrotate on shared hosting): above ERROR_LOG_MAX_BYTES only the
     * newest ERROR_LOG_KEEP_BYTES are kept, cut at a line boundary. An append racing with the rewrite can lose a line; acceptable for a log.
     */
    private static function trimErrorLog(string $file): void
    {
        $size = @filesize($file);
        if ($size === false || $size <= self::ERROR_LOG_MAX_BYTES) {
            return;
        }
        $tail = (string) file_get_contents($file, false, null, $size - self::ERROR_LOG_KEEP_BYTES);
        $firstLine = strpos($tail, "\n");
        $tail = $firstLine === false ? $tail : substr($tail, $firstLine + 1);
        @file_put_contents($file, $tail, LOCK_EX);
    }

    /** Masks secrets in an event: secret-looking keys become ***, ?t= tokens are cut from texts, long texts are shortened. Also used by LogReader. */
    public static function clean(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key)) {
                $out[$key] = '***';
            } elseif (is_array($value)) {
                $out[$key] = self::clean($value);
            } elseif (is_string($value)) {
                $value = mask_url_secrets($value); // links with ?t= tokens may appear in any text value
                $out[$key] = mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING) . '…' : $value;
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
