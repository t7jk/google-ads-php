<?php
declare(strict_types=1);

namespace App\Google;

use App\Lib\Events;
use App\Lib\Http;

/** Shared Google API call with a Bearer token: a single retry after 401 (the token may have been revoked) and an event on error. */
final class GoogleApi
{
    /**
     * @return array{0:int, 1:string, 2:array<string,string>} [status, body, headers]; error status codes are returned without throwing
     */
    public static function send(string $method, string $url, ?array $body = null, array $extraHeaders = []): array
    {
        for ($attempt = 0; ; $attempt++) {
            $headers = ['Authorization' => 'Bearer ' . Token::accessToken()] + $extraHeaders;
            [$status, $text, $respHeaders] = Http::request($method, $url, $headers, $body);
            if ($status === 401 && $attempt === 0) {
                Token::invalidate();
                continue;
            }
            return [$status, $text, $respHeaders];
        }
    }

    public static function logFailure(string $api, string $endpoint, int $status, array $respHeaders, float $startedAt, string $error, array $extra = []): void
    {
        Events::log('ads_api', [
            'api' => $api, 'endpoint' => $endpoint, 'ok' => false, 'status' => $status,
            'requestId' => $respHeaders['request-id'] ?? $respHeaders['x-goog-request-id'] ?? null,
            'ms' => (int) round((microtime(true) - $startedAt) * 1000), 'error' => $error,
        ] + $extra);
    }
}
