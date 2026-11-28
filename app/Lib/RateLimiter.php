<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * Fixed-window rate limiter on the rate_limits table (works on any SQL database, no cron needed).
 *
 * Two usage patterns:
 *  - enforce():       count every request in a bucket and answer 429 above the limit (e.g. MCP calls per client);
 *  - isLimited()/hit(): count only failures (e.g. wrong client secrets) and refuse the bucket once it is full.
 * The window starts at the first hit and does not slide: a client may burst up to 2x the limit across a window boundary,
 * which is fine for abuse protection. Buckets are plain strings chosen by the caller ("<purpose>:<ip or client id>").
 * Pitfall: behind a proxy REMOTE_ADDR is the proxy address, so per-IP buckets then share one counter (see client_ip()).
 */
final class RateLimiter
{
    /** Counts one hit. Returns true while the bucket is still within $limit hits in the current window. */
    public static function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        if (random_int(1, 100) === 1) {
            self::prune();
        }
        $row = Db::one('SELECT hits, window_ends_at FROM rate_limits WHERE bucket = ?', [$bucket]);
        if ($row === null || $row['window_ends_at'] <= now_iso()) {
            self::startWindow($bucket, $windowSeconds);
            return $limit >= 1;
        }
        Db::exec('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
        return (int) $row['hits'] + 1 <= $limit;
    }

    /** Whether the bucket has already used up $limit hits in its current window (does not count). */
    public static function isLimited(string $bucket, int $limit): bool
    {
        $row = Db::one('SELECT hits FROM rate_limits WHERE bucket = ? AND window_ends_at > ?', [$bucket, now_iso()]);
        return $row !== null && (int) $row['hits'] >= $limit;
    }

    /** Counts a request and ends it with HTTP 429 when the bucket is over its limit. */
    public static function enforce(string $bucket, int $limit, int $windowSeconds): void
    {
        if (!self::hit($bucket, $limit, $windowSeconds)) {
            self::reject($bucket);
        }
    }

    /** Ends the request with HTTP 429 and a Retry-After header (seconds until the window ends). */
    public static function reject(string $bucket): never
    {
        $row = Db::one('SELECT window_ends_at FROM rate_limits WHERE bucket = ?', [$bucket]);
        $wait = $row === null ? 60 : max(1, strtotime((string) $row['window_ends_at']) - time());
        Events::log('rate_limited', ['bucket' => preg_replace('/:.*/', '', $bucket)]); // purpose only: the IP is logged by Events itself
        json_out(['error' => 'rate_limited', 'error_description' => 'Too many requests, retry later.'], 429, ['Retry-After' => (string) $wait]);
    }

    private static function startWindow(string $bucket, int $windowSeconds): void
    {
        Db::exec('DELETE FROM rate_limits WHERE bucket = ?', [$bucket]);
        try {
            Db::exec('INSERT INTO rate_limits (bucket, hits, window_ends_at) VALUES (?, 1, ?)', [$bucket, now_iso($windowSeconds)]);
        } catch (\PDOException) {
            // A parallel request created the window between our DELETE and INSERT: count this hit in that window.
            Db::exec('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
        }
    }

    private static function prune(): void
    {
        Db::exec('DELETE FROM rate_limits WHERE window_ends_at < ?', [now_iso()]);
    }
}
