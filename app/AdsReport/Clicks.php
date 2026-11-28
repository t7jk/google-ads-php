<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Ads\PlNames;
use App\Google\Ads;

/** B5: click_view for a single day (optionally matching lp2 leads by gclid - counts only). */
final class Clicks
{
    /** gclid shortened for text output (first 8 characters + "..."); the full value is only in structuredContent. */
    public static function shortGclid(mixed $g): string
    {
        $s = (string) ($g ?? '');
        return mb_strlen($s) > 8 ? mb_substr($s, 0, 8) . '…' : $s;
    }

    private static function geoId(mixed $v): string
    {
        $id = Fmt::lastId($v);
        return preg_match('/^\d+$/', $id) ? $id : '';
    }

    /** City/region id from the location of presence, falling back to the area of interest. */
    private static function loc(array $r, string $k): string
    {
        return self::geoId(Fmt::get($r, "clickView.locationOfPresence.$k")) ?: self::geoId(Fmt::get($r, "clickView.areaOfInterest.$k"));
    }

    public static function view(array $a): array
    {
        $date = $a['date'];
        $raw = Ads::search(Queries::clicks($a['campaignId'], $date));
        $names = self::geoNames($raw);
        $nm = fn(string $i) => $i === '' ? '' : (($names[$i] ?? '') ?: $i);
        $rows = [];
        foreach ($raw as $r) {
            $adGroupId = Fmt::get($r, 'adGroup.id');
            $rows[] = [
                'gclid' => Fmt::get($r, 'clickView.gclid', ''), 'campaignId' => (string) Fmt::get($r, 'campaign.id', ''), 'campaign' => Fmt::get($r, 'campaign.name', ''),
                'adGroupId' => $adGroupId !== null && (string) $adGroupId !== '0' ? (string) $adGroupId : null, 'adGroup' => Fmt::get($r, 'adGroup.name') ?: null,
                'device' => PlNames::deviceName(Fmt::get($r, 'segments.device')), 'clickType' => Fmt::get($r, 'segments.clickType'),
                'keyword' => Fmt::get($r, 'clickView.keywordInfo.text'), 'matchType' => Fmt::get($r, 'clickView.keywordInfo.matchType'),
                'city' => $nm(self::loc($r, 'city')), 'region' => $nm(self::loc($r, 'region')), 'clicks' => Fmt::num(Fmt::get($r, 'metrics.clicks')) ?: 1,
            ];
        }
        $total = array_sum(array_column($rows, 'clicks'));
        $dev = [];
        foreach ($rows as $r) {
            $dev[$r['device']] = ($dev[$r['device']] ?? 0) + $r['clicks'];
        }
        arsort($dev); // stable in PHP 8: ties keep the order of first appearance
        $match = $a['matchLeads'] ? self::matchLeadCounts($date, array_filter(array_unique(array_map(fn($r) => (string) $r['gclid'], $rows)))) : null;
        $matchText = '';
        if ($match) {
            $matchText = $match['available']
                ? " lp2 leads (±1 day) with gclid: {$match['leadsWithGclid']}, matched to clicks of that day: {$match['matched']}, without a click in click_view: {$match['withoutClickInView']}."
                : " Lead matching skipped: {$match['note']}";
        }
        $devText = $dev ? '; devices: ' . implode(', ', array_map(fn($d, $n) => "$d $n", array_keys($dev), $dev)) : '';
        return [
            'header' => "Clicks on $date: $total$devText.$matchText",
            'rows' => $rows, 'textRows' => array_map(fn($r) => ['gclid' => self::shortGclid($r['gclid'])] + $r, $rows),
            'extra' => ['date' => $date, 'totalClicks' => $total, 'devices' => (object) $dev] + ($match ? ['matchLeads' => $match] : []),
            'empty' => "No clicks in click_view on $date" . ($a['campaignId'] ? " (campaign {$a['campaignId']})" : '') . ".$matchText",
            'footer' => 'click_view works for a single day (range is ignored), with data up to 90 days back. click_view does not expose click times. gclid is shortened in the text (full value in structuredContent). Search/PMax with gclid only; city and region by user location (falling back to interests).',
        ];
    }

    /** City and region names (id -> name); a query error = no names (only IDs are shown). */
    private static function geoNames(array $raw): array
    {
        $ids = [];
        foreach ($raw as $r) {
            foreach (['city', 'region'] as $k) {
                if (($i = self::loc($r, $k)) !== '') {
                    $ids[$i] = true;
                }
            }
        }
        $names = [];
        if ($ids) {
            try {
                foreach (Ads::search(Queries::geoNames(array_map('strval', array_slice(array_keys($ids), 0, 500)))) as $g) {
                    $names[(string) Fmt::get($g, 'geoTargetConstant.id')] = (string) Fmt::get($g, 'geoTargetConstant.name', '');
                }
            } catch (\Throwable) {
                // without names: only the IDs remain
            }
        }
        return $names;
    }

    /**
     * Matching lp2 leads by gclid: returns COUNTS ONLY.
     * The lp2 client (App\Lp2\LeadsClient::forUpload($from, $to): list<array{clickType,clickId}>) is written by another group; until it
     * exists we return the same message as the Node version when it is not configured.
     */
    private static function matchLeadCounts(string $date, array $gclids): array
    {
        $class = '\\App\\Lp2\\LeadsClient';
        if (!class_exists($class) || !method_exists($class, 'forUpload')) {
            return ['available' => false, 'note' => 'LP2_ADMIN_API_URL or LP2_ADMIN_API_TOKEN is missing from the connector configuration - the lp2 endpoint is not configured.'];
        }
        $from = Fmt::shiftDay($date, -1);
        $to = Fmt::shiftDay($date, 1);
        try {
            $leads = $class::forUpload($from, $to);
        } catch (\App\Ads\UserError $e) {
            return ['available' => false, 'note' => $e->getMessage()];
        }
        $withG = array_filter($leads, fn($l) => ($l['clickType'] ?? '') === 'gclid' && ($l['clickId'] ?? '') !== '');
        $matched = count(array_filter($withG, fn($l) => in_array($l['clickId'], $gclids, true)));
        return ['available' => true, 'window' => "$from – $to", 'leadsWithGclid' => count($withG), 'matched' => $matched, 'withoutClickInView' => count($withG) - $matched];
    }
}
