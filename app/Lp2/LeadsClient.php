<?php
declare(strict_types=1);

namespace App\Lp2;

use App\Media\Download;

/** Client for the lp2-admin endpoint api/ads-leads.php (GET only, Bearer; port of src/lp2/leads.ts). Passes through only known fields (no personal data). */
final class LeadsClient
{
    public const NOT_DEPLOYED = 'The lp2 endpoint is not deployed yet (api/ads-leads.php in lp2-admin returned 404) — deploy lp2-admin from the feat/ads-offline-conversions branch.';
    public const NOT_CONFIGURED = 'LP2_ADMIN_API_URL or LP2_ADMIN_API_TOKEN is missing from the connector configuration — the lp2 endpoint is not configured.';

    private static function str(mixed $v, int $max): string
    {
        return is_string($v) ? mb_substr($v, 0, $max) : '';
    }

    /** @throws Lp2NotAvailable */
    private static function get(array $params): array
    {
        $base = (string) config('lp2_admin_api_url', '');
        $token = (string) config('lp2_admin_api_token', '');
        if ($base === '' || $token === '') {
            throw new Lp2NotAvailable(self::NOT_CONFIGURED);
        }
        $bad = UrlHost::check($base);
        if ($bad !== null) {
            throw new Lp2NotAvailable("LP2_ADMIN_API_URL: $bad");
        }
        $url = $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($params);
        try {
            $r = Download::get($url, ['Authorization' => "Bearer $token", 'Accept' => 'application/json'], 1_048_576, 15);
        } catch (\RuntimeException $e) {
            throw new Lp2NotAvailable('Could not connect to the lp2 endpoint: ' . str_replace($token, '<token>', $e->getMessage()));
        }
        $status = $r['status'];
        if ($status >= 300 && $status < 400) {
            throw new Lp2NotAvailable('Could not connect to the lp2 endpoint: unexpected redirect');
        }
        match (true) {
            $status === 404 => throw new Lp2NotAvailable(self::NOT_DEPLOYED),
            $status === 403 => throw new Lp2NotAvailable('The lp2 endpoint rejected the token (403): check that LP2_ADMIN_API_TOKEN is the same as ads_leads_api_token in lp2-admin.'),
            $status === 429 => throw new Lp2NotAvailable('The lp2 endpoint blocked requests after invalid tokens (429) — try again in a few minutes.'),
            default => null,
        };
        $j = json_decode($r['body'], true);
        if (!is_array($j)) {
            throw new Lp2NotAvailable("The lp2 endpoint returned an invalid response (HTTP $status)");
        }
        if ($status < 200 || $status >= 300 || ($j['ok'] ?? null) !== true) {
            $err = self::str($j['error'] ?? null, 80);
            throw new Lp2NotAvailable('The lp2 endpoint returned an error: ' . ($err !== '' ? $err : "HTTP $status"));
        }
        return $j;
    }

    /** @return list<array{day:string,source:string,withClickId:bool,count:int}> */
    public static function aggregates(string $from, string $to): array
    {
        $j = self::get(['from' => $from, 'to' => $to]);
        $out = [];
        foreach ((array) ($j['aggregates'] ?? []) as $a) {
            if (!is_array($a) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['day'] ?? ''))) {
                continue;
            }
            $out[] = ['day' => $a['day'], 'source' => self::str($a['source'] ?? null, 40), 'withClickId' => ($a['with_click_id'] ?? null) === true,
                'count' => max(0, (int) floor((float) ($a['count'] ?? 0)))];
        }
        return $out;
    }

    /** @return list<array<string,string>> leads with a click identifier for offline conversions (no personal data) */
    public static function forUpload(string $from, string $to): array
    {
        $j = self::get(['from' => $from, 'to' => $to, 'forUpload' => '1']);
        $out = [];
        foreach ((array) ($j['leads'] ?? []) as $l) {
            if (!is_array($l) || !is_string($l['id'] ?? null) || !is_string($l['click_id'] ?? null)) {
                continue;
            }
            $out[] = ['id' => self::str($l['id'], 64), 'createdAt' => self::str($l['created_at'] ?? null, 40), 'clickType' => self::str($l['click_type'] ?? null, 10),
                'clickId' => self::str($l['click_id'], 512), 'clickTs' => self::str($l['click_ts'] ?? null, 40),
                'adUserData' => self::str($l['ad_user_data'] ?? null, 10) ?: 'unknown'];
        }
        return $out;
    }
}
