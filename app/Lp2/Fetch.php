<?php
declare(strict_types=1);

namespace App\Lp2;

use App\Media\Download;

/**
 * Fetching the landing page with manual redirect following (port of src/lp2/fetch.ts).
 * EVERY step (the initial URL and every Location) is checked against the host allow-list BEFORE fetching. No cookies, GET only.
 */
final class Fetch
{
    public const MAX_REDIRECTS = 5;
    public const TIMEOUT_S = 10;
    public const MAX_BYTES = 2 * 1024 * 1024;
    private const UA = 'google-ads-mcp-verify-lp2/1.0 (read-only, no cookies)';
    public const TEST_PARAMS = ['gclid' => 'TEST_gclid_0001', 'gbraid' => 'TEST_gbraid_0002', 'wbraid' => 'TEST_wbraid_0003'];

    /**
     * @return array{hops:list<array>, finalUrl?:string, status?:int, body?:string, bytes?:int, truncated?:bool, contentType?:string, blocked?:string, error?:string}
     */
    public static function chain(string $startUrl, bool $readBody = true): array
    {
        $hops = [];
        $url = $startUrl;
        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            $bad = UrlHost::check($url);
            if ($bad !== null) {
                $hops[] = ['url' => $url, 'status' => 0, 'blocked' => $bad];
                return ['hops' => $hops, 'blocked' => $i === 0 ? $bad : "Redirect outside the allowed list (step $i): $bad"];
            }
            try {
                $r = Download::get($url, ['User-Agent' => self::UA, 'Accept' => 'text/html,application/javascript,*/*;q=0.5'], $readBody ? self::MAX_BYTES : 0, self::TIMEOUT_S);
            } catch (\RuntimeException $e) {
                $hops[] = ['url' => $url, 'status' => 0];
                return ['hops' => $hops, 'error' => "Could not fetch $url: " . $e->getMessage()];
            }
            $loc = $r['headers']['location'] ?? '';
            if ($r['status'] >= 300 && $r['status'] < 400 && $loc !== '') {
                $next = UrlHost::resolve($loc, $url);
                if ($next === null) {
                    $hops[] = ['url' => $url, 'status' => $r['status']];
                    return ['hops' => $hops, 'error' => "Invalid Location header: $loc"];
                }
                $hops[] = ['url' => $url, 'status' => $r['status'], 'location' => $next];
                $url = $next;
                continue;
            }
            $hops[] = ['url' => $url, 'status' => $r['status']];
            $base = ['hops' => $hops, 'finalUrl' => $url, 'status' => $r['status']];
            if (isset($r['headers']['content-type'])) {
                $base['contentType'] = $r['headers']['content-type'];
            }
            return $readBody ? $base + ['body' => $r['body'], 'bytes' => $r['bytes'], 'truncated' => $r['truncated']] : $base;
        }
        return ['hops' => $hops, 'error' => 'Too many redirects (more than ' . self::MAX_REDIRECTS . ')'];
    }

    /** Value of a query parameter from the URL, or null. */
    private static function queryValue(string $url, string $name): ?string
    {
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $q);
        return isset($q[$name]) && is_string($q[$name]) ? $q[$name] : null;
    }

    private static function withTestParams(string $url): string
    {
        $base = explode('#', $url, 2)[0];
        [$path, $query] = array_pad(explode('?', $base, 2), 2, '');
        $keep = array_filter(explode('&', $query), fn($kv) => $kv !== '' && !array_key_exists(urldecode(explode('=', $kv, 2)[0]), self::TEST_PARAMS));
        foreach (self::TEST_PARAMS as $k => $v) {
            $keep[] = "$k=$v";
        }
        return $path . '?' . implode('&', $keep);
    }

    /**
     * Plain GET with test parameters; checks each parameter separately in the final URL of the chain.
     * @return array{paramsPreserved:array<string,?bool>, lostAt?:array<string,int>, note?:string, hops:list<array>}
     */
    public static function checkParams(string $url): array
    {
        $ch = self::chain(self::withTestParams($url), false);
        if (isset($ch['blocked']) || isset($ch['error']) || !isset($ch['finalUrl'])) {
            return ['paramsPreserved' => ['gclid' => null, 'gbraid' => null, 'wbraid' => null],
                'note' => $ch['blocked'] ?? $ch['error'] ?? 'no final response', 'hops' => $ch['hops']];
        }
        $res = [];
        $lostAt = [];
        foreach (self::TEST_PARAMS as $name => $value) {
            $kept = self::queryValue($ch['finalUrl'], $name) === $value;
            $res[$name] = $kept;
            if (!$kept) {
                foreach ($ch['hops'] as $idx => $h) { // the first step whose Location no longer contains the parameter
                    if (($h['location'] ?? '') !== '' && self::queryValue($h['location'], $name) !== $value) {
                        $lostAt[$name] = $idx + 1;
                        break;
                    }
                }
            }
        }
        return ['paramsPreserved' => $res] + ($lostAt ? ['lostAt' => $lostAt] : []) + ['hops' => $ch['hops']];
    }
}
