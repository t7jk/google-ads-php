<?php
declare(strict_types=1);

namespace App\Report;

use App\Ads\Params;
use App\Ads\Plural;
use App\Ads\Time;
use App\Ads\UserError;
use App\Ads\Table;
use App\Google\Ads;
use App\Health\Audit;
use DateTimeImmutable;

/**
 * Tool logic: account_overview, list_campaigns, campaign_details, campaign_health_check, search_terms
 * (port of src/mcp/tools-read.ts). Each method takes the tool arguments and returns an MCP result.
 */
final class CampaignTools
{
    private const SEARCH_TERMS_CHAR_LIMIT = 20000;

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function tz(): string
    {
        return (string) config('account_time_zone', 'Europe/Warsaw');
    }

    public static function accountOverview(): array
    {
        $cust = Ads::search(Queries::account())[0] ?? [];
        $c = $cust['customer'] ?? [];
        $byStatus = [];
        foreach (Ads::search("SELECT campaign.id, campaign.status FROM campaign WHERE campaign.status != 'REMOVED'") as $r) {
            $st = Js::str(Js::get($r, 'campaign.status', 'undefined'));
            $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
        }
        // The PHP server does not count API operations (no background process or counter) - opsToday is always 0.
        $opsToday = 0;
        $data = [
            'name' => $c['descriptiveName'] ?? null, 'currency' => $c['currencyCode'] ?? null, 'timeZone' => $c['timeZone'] ?? null,
            'autoTagging' => $c['autoTaggingEnabled'] ?? null, 'finalUrlSuffix' => $c['finalUrlSuffix'] ?? null, 'trackingUrlTemplate' => $c['trackingUrlTemplate'] ?? null,
            'campaignsByStatus' => (object) $byStatus, 'opsToday' => $opsToday, 'opsLimit' => 2880,
        ];
        $st = implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($byStatus), $byStatus)) ?: 'none';
        return \App\Lib\Mcp::json("Account \"" . Js::str($data['name']) . '" (' . Js::str($data['currency']) . ', ' . Js::str($data['timeZone']) . "). Campaigns: $st. API operations today: $opsToday/2880.", $data);
    }

    public static function listCampaigns(array $args): array
    {
        $range = Range::fromArgs($args);
        $campaigns = [];
        $lines = [];
        foreach (Ads::search(Queries::campaignList($range, self::now())) as $r) {
            $m = Metrics::of($r['metrics'] ?? []);
            $c = [
                'id' => (string) Js::get($r, 'campaign.id', 'undefined'), 'name' => Js::get($r, 'campaign.name'), 'status' => Js::get($r, 'campaign.status'),
                'type' => Js::get($r, 'campaign.advertisingChannelType'), 'dailyBudget' => Metrics::pln(Js::get($r, 'campaignBudget.amountMicros')),
                'biddingStrategy' => Js::get($r, 'campaign.biddingStrategyType'),
            ] + $m;
            $campaigns[] = $c;
            $lines[] = '- ' . Js::str($c['name']) . " [{$c['id']}] " . Js::str($c['status']) . ': cost ' . Js::fixed2($c['cost']) . " PLN, clicks {$c['clicks']}, conversions {$c['conversions']}, CTR {$c['ctrPercent']}%";
        }
        return \App\Lib\Mcp::json('Campaigns (' . count($campaigns) . "):\n" . implode("\n", $lines), ['campaigns' => $campaigns]);
    }

    /** Daily metrics padded with zero days within the range bounds (account time zone). */
    private static function dailyMetrics(string $cid, string|array $range): array
    {
        $b = Range::dailyBounds($range, self::now(), self::tz());
        $byDate = [];
        foreach (Ads::search(Queries::campaignDaily($cid, $b)) as $x) {
            $byDate[(string) Js::get($x, 'segments.date')] = $x['metrics'] ?? [];
        }
        $dates = $b ? Time::eachDate($b['from'], $b['to']) : (function () use ($byDate) {
            $k = array_map('strval', array_keys($byDate)); sort($k); return $k;
        })();
        $out = [];
        foreach ($dates as $date) {
            $mm = $byDate[$date] ?? [];
            $imp = Js::num($mm['impressions'] ?? null);
            $clk = Js::num($mm['clicks'] ?? null);
            $base = Metrics::of(array_merge($mm, ['ctr' => $imp ? $clk / $imp : 0, 'averageCpc' => $clk ? Js::num($mm['costMicros'] ?? null) / $clk : 0]));
            $out[] = ['date' => $date] + $base;
        }
        return $out;
    }

    public static function campaignDetails(array $args): array
    {
        $id = Metrics::resolveCampaignId(Metrics::requireString($args, 'campaign'));
        $range = Range::fromArgs($args);
        $daily = Params::bool($args, 'daily', false);
        $snap = PmaxSnapshot::load($id);
        if (Js::get($snap['campaign'], 'advertisingChannelType') === 'PERFORMANCE_MAX') {
            return self::pmaxDetails($id, $range, $daily, $snap);
        }
        return self::searchDetails($id, $range, $snap);
    }

    private static function pmaxDetails(string $id, string|array $range, bool $daily, array $snap): array
    {
        $d = PmaxDescribe::describe($snap);
        $sum = [];
        foreach (Ads::search(Queries::campaignMetrics($id, $range, self::now())) as $r) {
            $sum = Metrics::sum($sum, $r['metrics'] ?? []);
        }
        $m = Metrics::of($sum);
        $days = $daily ? self::dailyMetrics($id, $range) : null;
        $mText = 'Metrics (' . Range::label($range) . "): impressions {$m['impressions']}, clicks {$m['clicks']}, cost " . Js::fixed2($m['cost'])
            . " PLN, CTR {$m['ctrPercent']}%, CPC " . Js::fixed2($m['avgCpc']) . " PLN, conversions {$m['conversions']}, cost/conversion "
            . ($m['costPerConversion'] === null ? 'n/a' : Js::fixed2($m['costPerConversion']) . ' PLN') . '.';
        $dText = '';
        if ($days) {
            $dText = "\nDaily breakdown (time zone " . self::tz() . "):\n" . implode("\n", array_map(
                fn($x) => "{$x['date']}: impr. {$x['impressions']}, clicks {$x['clicks']}, " . Js::fixed2($x['cost']) . " PLN, conv. {$x['conversions']}", $days));
        }
        $notes = ['The campaign logo (brand guidelines) is set at campaign level, not asset group level - "logo 0" in an asset group is correct when the logo appears in the campaign assets.'];
        $structured = ['id' => $id, 'name' => $snap['campaign']['name'] ?? null, 'channelType' => 'PERFORMANCE_MAX', 'metrics' => $m]
            + ($days !== null ? ['daily' => $days] : []) + ['notes' => $notes] + $d['data'];
        return \App\Lib\Mcp::json("$mText$dText\n{$d['text']}", $structured);
    }

    private static function searchDetails(string $id, string|array $range, array $snap): array
    {
        $q = Queries::campaignDetails($id, $range, self::now());
        $x = Queries::campaignDetailsExtra($id);
        $camp = Ads::search($q['campaign']);
        $adGroups = Ads::search($q['adGroups']);
        $adRows = Ads::search($q['ads']);
        $keywords = Ads::search($q['keywords']);
        $negatives = Ads::search($x['negatives']);
        $locations = Ads::search($x['locations']);
        $settings = Ads::search($x['settings']);
        $c = $camp[0] ?? [];
        $texts = fn($list) => array_map(fn($h) => $h['text'] ?? null, $list ?? []);
        $common = PmaxDescribe::commonSettings($snap);
        $data = [
            'id' => $id, 'name' => Js::get($c, 'campaign.name'), 'status' => Js::get($c, 'campaign.status'), 'biddingStrategy' => Js::get($c, 'campaign.biddingStrategyType'),
            'dailyBudget' => Metrics::pln(Js::get($c, 'campaignBudget.amountMicros')),
        ] + Metrics::of($c['metrics'] ?? []) + [
            'adGroups' => array_map(fn($r) => ['id' => (string) Js::get($r, 'adGroup.id', 'undefined'), 'name' => Js::get($r, 'adGroup.name'), 'status' => Js::get($r, 'adGroup.status')] + Metrics::of($r['metrics'] ?? []), $adGroups),
            'ads' => array_map(fn($r) => [
                'adGroupId' => (string) Js::get($r, 'adGroup.id', 'undefined'), 'adId' => (string) Js::get($r, 'adGroupAd.ad.id', 'undefined'),
                'finalUrls' => Js::get($r, 'adGroupAd.ad.finalUrls', []), 'status' => Js::get($r, 'adGroupAd.status'),
                'approval' => Js::get($r, 'adGroupAd.policySummary.approvalStatus'),
                'headlines' => $texts(Js::get($r, 'adGroupAd.ad.responsiveSearchAd.headlines')),
                'descriptions' => $texts(Js::get($r, 'adGroupAd.ad.responsiveSearchAd.descriptions')),
                'path1' => Js::get($r, 'adGroupAd.ad.responsiveSearchAd.path1'), 'path2' => Js::get($r, 'adGroupAd.ad.responsiveSearchAd.path2'),
            ] + Metrics::of($r['metrics'] ?? []), $adRows),
            'keywords' => array_map(fn($r) => [
                'adGroupId' => (string) Js::get($r, 'adGroup.id', 'undefined'), 'criterionId' => (string) Js::get($r, 'adGroupCriterion.criterionId', 'undefined'),
                'text' => Js::get($r, 'adGroupCriterion.keyword.text'), 'matchType' => Js::get($r, 'adGroupCriterion.keyword.matchType'), 'status' => Js::get($r, 'adGroupCriterion.status'),
                'qualityScore' => Js::get($r, 'adGroupCriterion.qualityInfo.qualityScore'),
            ] + Metrics::of($r['metrics'] ?? []), $keywords),
            'negativeKeywords' => array_map(fn($r) => ['criterionId' => (string) Js::get($r, 'campaignCriterion.criterionId', 'undefined'), 'text' => Js::get($r, 'campaignCriterion.keyword.text'), 'matchType' => Js::get($r, 'campaignCriterion.keyword.matchType')], $negatives),
            'locations' => array_map(fn($r) => ['geoTarget' => Js::get($r, 'campaignCriterion.location.geoTargetConstant'), 'proximity' => Js::get($r, 'campaignCriterion.proximity'), 'negative' => !empty(Js::get($r, 'campaignCriterion.negative'))], $locations),
            'networks' => Js::get($settings[0] ?? [], 'campaign.networkSettings'),
            'customParameters' => Js::get($settings[0] ?? [], 'campaign.urlCustomParameters', []),
            'trackingUrlTemplate' => Js::get($settings[0] ?? [], 'campaign.trackingUrlTemplate'),
            'finalUrlSuffix' => Js::get($settings[0] ?? [], 'campaign.finalUrlSuffix'),
            'geoTargetType' => $common['geoTargetType'], 'startDateTime' => $common['startDateTime'], 'endDateTime' => $common['endDateTime'], 'biddableActions' => $common['biddableActions'],
            'campaignAssets' => PmaxDescribe::campaignAssetsOf($snap),
        ];
        $text = 'Campaign "' . Js::str($data['name']) . "\" [$id] " . Js::str($data['status']) . ': ' . Plural::withCount(count($data['adGroups']), 'ad group', 'ad groups', 'ad groups') . ', ' . Plural::withCount(count($data['ads']), 'ad', 'ads', 'ads') . ', '
            . Plural::withCount(count($data['keywords']), 'keyword', 'keywords', 'keywords') . ', ' . Plural::withCount(count($data['negativeKeywords']), 'negative keyword', 'negative keywords', 'negative keywords') . '. Cost ' . Js::fixed2($data['cost']) . ' PLN. Schedule: '
            . ($data['startDateTime'] ?? 'none') . ' – ' . ($data['endDateTime'] ?? 'none') . '. ' . PmaxDescribe::campaignAssetsLine($data['campaignAssets']);
        return \App\Lib\Mcp::json($text, $data);
    }

    public static function healthCheck(array $args): array
    {
        $id = Metrics::resolveCampaignId(Metrics::requireString($args, 'campaign'));
        $r = Audit::auditCampaign($id, self::now());
        return \App\Lib\Mcp::json(Audit::text($r['name'], $r['problems']), ['campaignId' => $id, 'problems' => $r['problems']]);
    }

    public static function searchTerms(array $args): array
    {
        $id = Metrics::resolveCampaignId(Metrics::requireString($args, 'campaign'));
        $range = Range::fromArgs($args);
        $limit = Params::int($args, 'limit', 1, 1000, 200);
        $minImpressions = Params::int($args, 'minImpressions', 0, 1_000_000, 0);
        $onlyWithCost = Params::bool($args, 'onlyWithCost', false);
        $noConversions = Params::bool($args, 'noConversions', false);
        $ngrams = Params::int($args, 'ngrams', 0, 3, 0);
        $minCost = self::minCost($args);

        $chRow = Ads::search(Queries::campaignChannel($id))[0] ?? null;
        $channel = Js::get($chRow, 'campaign.advertisingChannelType');
        $pmax = $channel === 'PERFORMANCE_MAX';
        if ($channel && !$pmax && $channel !== 'SEARCH' && $channel !== 'SHOPPING') {
            return \App\Lib\Mcp::json("The campaign has type $channel: the Google Ads API does not provide search terms for this type (search_term_view covers Search and Shopping, campaign_search_term_view covers Performance Max). This is not \"0 terms\", it is missing data.",
                ['campaignId' => $id, 'channelType' => $channel, 'source' => 'none', 'terms' => []]);
        }
        $source = $pmax ? 'campaign_search_term_view' : 'search_term_view';
        $now = self::now();
        $rows = Ads::search($pmax ? Queries::campaignSearchTerms($id, $range, $limit, $now) : Queries::searchTerms($id, $range, $limit, $now));
        $allTerms = array_map(fn($r) => ['term' => Js::get($r, $pmax ? 'campaignSearchTermView.searchTerm' : 'searchTermView.searchTerm')]
            + Metrics::of($r['metrics'] ?? []), $rows);
        $terms = array_values(array_filter($allTerms, fn($t) => $t['impressions'] >= $minImpressions
            && (!$onlyWithCost || $t['cost'] > 0) && ($minCost === null || $t['cost'] >= $minCost) && (!$noConversions || $t['conversions'] === 0)));
        $coverage = null;
        if ($pmax) {
            $camp = Ads::search(Queries::campaignImpressions($id, $range, $now));
            $coverage = [
                'termImpressions' => array_sum(array_column($allTerms, 'impressions')),
                'campaignImpressions' => array_sum(array_map(fn($r) => Js::num(Js::get($r, 'metrics.impressions')), $camp)),
            ];
        }
        $shown = array_slice($terms, 0, $limit);
        $head = 'Search terms (' . count($terms) . "), data source: $source" . ($channel ? ", campaign type $channel" : '') . '.'
            . ($coverage ? " Google reports only terms above the privacy threshold - coverage {$coverage['termImpressions']} of {$coverage['campaignImpressions']} campaign impressions." : '');
        $lines = array_map(fn($t) => '- ' . Js::str($t['term']) . ": {$t['impressions']} impr., {$t['clicks']} clicks, " . Js::fixed2($t['cost']) . " PLN, conversions {$t['conversions']}"
            . ($t['costPerConversion'] === null ? '' : ', ' . Js::fixed2($t['costPerConversion']) . ' PLN/conv.'), $shown);
        $textCut = false;
        if (mb_strlen(implode("\n", $lines)) > self::SEARCH_TERMS_CHAR_LIMIT) {
            $kept = [];
            $size = 0;
            foreach ($lines as $l) {
                if ($size + mb_strlen($l) + 1 > self::SEARCH_TERMS_CHAR_LIMIT) {
                    break;
                }
                $kept[] = $l;
                $size += mb_strlen($l) + 1;
            }
            $lines = $kept;
            $textCut = true;
        }
        $more = $textCut || count($terms) > count($shown) ? "\n...showing " . count($lines) . ' of ' . count($terms) . ' terms (full list in structuredContent).' : '';
        $grams = $ngrams ? Ngrams::compute(array_map(fn($t) => ['term' => (string) ($t['term'] ?? ''), 'impressions' => $t['impressions'], 'clicks' => $t['clicks'], 'cost' => $t['cost'], 'conversions' => $t['conversions']], $terms), $ngrams) : null;
        $gramText = '';
        if ($grams !== null) {
            $table = $grams ? Table::toTsv(array_map(fn($g) => [
                'n-gram' => $g['ngram'], 'terms' => $g['terms'], 'impressions' => $g['impressions'], 'clicks' => $g['clicks'], 'cost' => $g['cost'], 'conversions' => $g['conversions'],
            ], array_slice($grams, 0, 50))) : 'none';
            $gramText = "\n\nN-grams ($ngrams) from terms without conversions, sorted by cost (" . count($grams) . (count($grams) > 50 ? ', showing 50' : '') . "):\n$table";
        }
        $structured = ['campaignId' => $id, 'channelType' => $channel, 'source' => $source, 'terms' => $terms] + ($grams !== null ? ['ngrams' => $grams] : []) + ($coverage ? ['coverage' => $coverage] : []);
        return \App\Lib\Mcp::json("$head\n" . implode("\n", $lines) . $more . $gramText, $structured);
    }

    /** minCost: a number 0..1,000,000 (numeric text also accepted), missing = null. */
    private static function minCost(array $args): ?float
    {
        $v = $args['minCost'] ?? null;
        if ($v === null) {
            return null;
        }
        if (is_string($v) && trim($v) !== '') {
            $v = is_numeric($v) ? (float) $v : $v;
        }
        if (!is_int($v) && !is_float($v) || $v < 0 || $v > 1_000_000) {
            throw new UserError('Parameter minCost must be a number in the range 0-1000000');
        }
        return (float) $v;
    }
}
