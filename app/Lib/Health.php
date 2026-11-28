<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * Health checks behind GET /health (for an external uptime monitor and for the deploy smoke test).
 * Each check is 'ok' | 'fail' | 'warn'. 'fail' = the server cannot work (HTTP 503); 'warn' = it runs but needs attention
 * (the Google account is not connected or its token cannot be read), reported as status "degraded" with HTTP 200.
 * The endpoint is public, so it returns only these labels: no paths, no error messages (those go to the log).
 */
final class Health
{
    /** @return array{status:string, version:string, checks:array<string,string>} */
    public static function run(): array
    {
        $checks = [
            'database' => self::check(self::database(...), 'fail'),
            'data_dir_writable' => self::check(self::dataDirWritable(...), 'fail'),
            'secret_key' => self::check(self::secretKey(...), 'fail'),
            'google_token' => self::check(self::googleToken(...), 'warn'),
        ];
        $status = in_array('fail', $checks, true) ? 'error' : (in_array('warn', $checks, true) ? 'degraded' : 'ok');
        return ['status' => $status, 'version' => Version::current(), 'checks' => $checks];
    }

    public static function httpStatus(array $result): int
    {
        return $result['status'] === 'error' ? 503 : 200;
    }

    /** Runs a check; false or an exception gives $onFailure (the exception message is logged, never returned). */
    private static function check(callable $check, string $onFailure): string
    {
        try {
            return $check() ? 'ok' : $onFailure;
        } catch (\Throwable $e) {
            error_log('health check failed: ' . $e::class . ': ' . $e->getMessage());
            return $onFailure;
        }
    }

    private static function database(): bool
    {
        return Db::one('SELECT COUNT(*) AS n FROM settings') !== null;
    }

    /** A real write: the data directory holds the SQLite file, logs and uploads, so a read-only disk breaks everything. */
    private static function dataDirWritable(): bool
    {
        $probe = tempnam(DATA_PATH, 'health');
        if ($probe === false) {
            return false;
        }
        $written = file_put_contents($probe, 'ok') === 2;
        @unlink($probe);
        return $written;
    }

    private static function secretKey(): bool
    {
        $key = base64_decode((string) config('secret_key', ''), true);
        return $key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    }

    private static function googleToken(): bool
    {
        return \App\Google\Token::hasRefreshToken();
    }
}
