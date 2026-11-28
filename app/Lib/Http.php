<?php
declare(strict_types=1);

namespace App\Lib;

/** curl-based HTTP client. A network error = RuntimeException; an HTTP status >= 400 does NOT throw (the caller decides). */
final class Http
{
    private const TIMEOUT_SECONDS = 25;
    private const MAX_RESPONSE_BYTES = 33_554_432; // 32 MiB: large GAQL reports fit, a runaway response does not exhaust memory

    /**
     * A request with a JSON body (array) or a raw one (string).
     * @return array{0:int, 1:string, 2:array<string,string>} [status, body, response headers (lowercase)]
     */
    public static function request(string $method, string $url, array $headers = [], array|string|null $body = null): array
    {
        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers['Content-Type'] ??= 'application/json';
        }
        $responseHeaders = [];
        $text = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            // Only known Google HTTPS endpoints are called: never follow a redirect or speak another protocol.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$text): int {
                $text .= $chunk;
                return strlen($text) > self::MAX_RESPONSE_BYTES ? 0 : strlen($chunk); // returning less than received aborts the transfer
            },
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $done = curl_exec($ch);
        if ($done === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Connection error: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $text, $responseHeaders];
    }

    /** @return array{0:int, 1:array} [status, decoded JSON (an empty array when it is not JSON)] */
    public static function json(string $method, string $url, array $headers = [], array|string|null $body = null): array
    {
        [$status, $text] = self::request($method, $url, $headers, $body);
        $data = json_decode($text, true);
        return [$status, is_array($data) ? $data : []];
    }

    /** POST application/x-www-form-urlencoded (Google OAuth endpoints). */
    public static function form(string $url, array $fields): array
    {
        return self::json('POST', $url, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    }
}
