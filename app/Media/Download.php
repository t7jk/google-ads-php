<?php
declare(strict_types=1);

namespace App\Media;

/**
 * A single GET request (curl) with a body size limit, no automatic redirects and no cookies.
 * Redirects are handled by the caller (each step is checked against its own host allow-list).
 * Pitfall: exceeding the limit stops the download (truncated = true); it is not an error.
 */
final class Download
{
    /**
     * Resolves the host (A+AAAA) and rejects it if ANY address is private/loopback/link-local/reserved
     * and returns entries for CURLOPT_RESOLVE ("host:port:ip,ip") so curl does not resolve the name a second time (anti DNS rebinding).
     * @return list<string>
     * @throws \RuntimeException
     */
    private static function resolvePublic(string $url): array
    {
        $p = parse_url($url);
        $host = trim((string) ($p['host'] ?? ''), '[]');
        if (strtolower((string) ($p['scheme'] ?? '')) !== 'https' || $host === '') {
            throw new \RuntimeException('only https URLs are allowed');
        }
        $port = (int) ($p['port'] ?? 443);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = array_column(dns_get_record($host, DNS_A) ?: [], 'ip');
            $ips = array_merge($ips, array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
        }
        if (!$ips) {
            throw new \RuntimeException("could not resolve host $host");
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new \RuntimeException("host $host points to a non-public address");
            }
        }
        return ["$host:$port:" . implode(',', $ips)];
    }

    /**
     * Public (globally routable) address check used for every A/AAAA record before connecting (SSRF guard).
     * PHP's private/reserved filter flags are the base; we add explicit cases for what is easy to get wrong:
     * an IPv4-mapped IPv6 address (::ffff:127.0.0.1) wraps an IPv4 address and is judged by that IPv4 address,
     * and the link-local range 169.254.0.0/16 (cloud metadata endpoints) is always rejected.
     */
    private static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $ip = (string) inet_ntop(substr($packed, 12)); // the wrapped IPv4 address
        }
        if (str_starts_with($ip, '169.254.')) {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, headers:array<string,string>, body:string, bytes:int, truncated:bool}
     * @throws \RuntimeException on a network error / timeout
     */
    public static function get(string $url, array $headers, int $maxBytes, int $timeoutSec = 10): array
    {
        $body = '';
        $truncated = false;
        $respHeaders = [];
        $pin = self::resolvePublic($url); // SSRF: odrzuca prywatne IP i przypina IP (anty DNS-rebinding)
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSec),
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_RESOLVE => $pin,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$n, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($n))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$truncated, $maxBytes): int {
                $room = $maxBytes - strlen($body);
                if (strlen($chunk) > $room) {
                    $body .= substr($chunk, 0, max(0, $room));
                    $truncated = true;
                    return 0; // przerywa transfer
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false && !$truncated) {
            throw new \RuntimeException($error !== '' ? $error : 'connection error');
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body, 'bytes' => strlen($body), 'truncated' => $truncated];
    }
}
