<?php
declare(strict_types=1);

namespace App\Ga4;

use App\Ads\Params;
use App\Ads\Table;
use App\Ads\UserError;
use App\Google\Ads;
use App\Google\Ga4;
use App\Google\Ga4Admin;
use App\Lib\Mcp;
use App\Report\Js;
use App\Report\Range;

/** GA4 tool logic: ga4_report (port of tools-read.ts) and ga4_key_events, ga4_data_streams, ga4_ads_links (port of tools-ga4.ts). */
final class Tools
{
    private const NO_PROPERTY = 'GA4_PROPERTY_ID is missing from the configuration';

    // ---- ga4_report ----

    public static function report(array $args): array
    {
        if (!config('ga4_property_id')) {
            return Mcp::error(self::NO_PROPERTY);
        }
        $range = Range::fromArgs($args);
        $dr = is_array($range) ? ['startDate' => $range['from'], 'endDate' => $range['to']]
            : ['startDate' => ['LAST_7_DAYS' => '7daysAgo', 'LAST_30_DAYS' => '30daysAgo', 'LAST_90_DAYS' => '90daysAgo', 'ALL_TIME' => '2020-01-01'][$range], 'endDate' => 'today'];
        $mode = (string) ($args['pathMatch'] ?? 'prefix');
        if (!in_array($mode, ['prefix', 'contains', 'exact', 'any'], true)) {
            throw new UserError('Parameter pathMatch must be one of: prefix, contains, exact, any');
        }
        $view = $args['view'] ?? null;
        if ($view !== null && $view !== 'leads_by_source') {
            throw new UserError('Parameter view must equal leads_by_source');
        }
        $eventName = isset($args['eventName']) && is_string($args['eventName']) ? $args['eventName'] : null;
        if ($eventName !== null && (mb_strlen($eventName) < 1 || mb_strlen($eventName) > 100)) {
            throw new UserError('Parameter eventName must be 1-100 characters');
        }
        $shape = ReportShape::resolve($view, self::enumList($args, 'dimensions', ReportShape::DIMENSIONS), self::enumList($args, 'metrics', ReportShape::METRICS), $eventName);
        $shown = Params::int($args, 'limit', 1, 500, 100);

        $raw = trim((string) ($args['path'] ?? config('landing_path_prefix', '')));
        $bare = rtrim($raw, '/'); // "/lp2/" -> "/lp2"
        $pathFilter = $shape['pathFilter'] && $raw !== '' && $mode !== 'any' ? self::pathFilter($mode, $raw, $bare) : null;
        $filter = ReportShape::combineFilters($pathFilter, $shape['eventName']);
        $rows = Ga4::runReport([
            'dateRanges' => [$dr],
            'dimensions' => array_map(fn($n) => ['name' => $n], $shape['dimensions']),
            'metrics' => array_map(fn($n) => ['name' => $n], $shape['metrics']),
            ...($filter ? ['dimensionFilter' => $filter] : []),
            'limit' => 500,
        ])['rows'];
        $sessions = ReportShape::sumMetric($rows, 'sessions');
        $keyEvents = ReportShape::sumMetric($rows, 'keyEvents');
        if (!$rows && $pathFilter) {
            return self::emptyResult($dr, $mode, $raw, $sessions, $keyEvents);
        }
        $first = $shape['metrics'][0];
        $sorted = ReportShape::sortAndLimit($rows, $first, $shown);
        $totals = ['sessions' => $sessions, 'keyEvents' => $keyEvents];
        foreach ($shape['metrics'] as $m) {
            if ($m !== 'engagementRate') {
                $totals[$m] = ReportShape::sumMetric($rows, $m);
            }
        }
        $summary = implode(', ', array_map(fn($m) => "$m {$totals[$m]}", array_filter($shape['metrics'], fn($m) => $m !== 'engagementRate')));
        $extra = [];
        $lines = ['GA4' . ($view ? " (view $view)" : '') . ': ' . count($rows) . ' rows' . ($summary !== '' ? ", $summary" : '') . '.'];
        if ($view === 'leads_by_source') {
            $cpc = ReportShape::googleCpcTotal($rows);
            $extra['googleCpcLeads'] = $cpc;
            $lines[] = 'Total generate_lead for ' . ReportShape::GOOGLE_CPC . ": $cpc.";
        }
        $lines[] = !$rows ? 'No rows.' : 'Showing ' . count($sorted) . ' of ' . count($rows) . " (sorted descending by $first).";
        if ($sorted) {
            $lines[] = ReportShape::table($sorted, $shape['dimensions'], $shape['metrics']);
        }
        return Mcp::json(implode("\n", $lines), ['rows' => $rows, 'totals' => $totals, 'shownRows' => count($sorted), 'totalRows' => count($rows)] + $extra);
    }

    /** List of values from an allow-list (zod: array of enum); missing = null. */
    private static function enumList(array $args, string $key, array $allowed): ?array
    {
        $v = $args[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_array($v) || array_diff($v, $allowed)) {
            throw new UserError("Parameter $key may contain only: " . implode(', ', $allowed));
        }
        return array_values($v);
    }

    /** Landing page filter: contains/exact or prefix (exactly /lp2 and everything under /lp2/). */
    private static function pathFilter(string $mode, string $raw, string $bare): array
    {
        $sf = fn(string $type, string $value) => ['filter' => ['fieldName' => 'landingPage', 'stringFilter' => ['matchType' => $type, 'value' => $value]]];
        return match ($mode) {
            'contains' => $sf('CONTAINS', $raw),
            'exact' => $sf('EXACT', $raw),
            default => ['orGroup' => ['expressions' => [$sf('EXACT', $bare !== '' ? $bare : '/'), $sf('BEGINS_WITH', "$bare/")]]],
        };
    }

    /** Empty result with a path filter: shows the 5 most frequent landing pages without the filter. */
    private static function emptyResult(array $dr, string $mode, string $raw, int|float $sessions, int|float $keyEvents): array
    {
        $top = Ga4::runReport([
            'dateRanges' => [$dr], 'dimensions' => [['name' => 'landingPage']], 'metrics' => [['name' => 'sessions']],
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]], 'limit' => 5,
        ])['rows'];
        $topPages = array_map(fn($r) => ['landingPage' => $r['landingPage'] ?? null, 'sessions' => Js::num($r['sessions'] ?? null)], $top);
        $list = implode(', ', array_map(fn($t) => Js::str($t['landingPage']) . " ({$t['sessions']})", $topPages)) ?: 'no traffic in this range';
        return Mcp::json("GA4: 0 rows for filter $mode \"{$raw}\". The landingPage dimension contains just the path (no host or parameters), e.g. /lp2 or /lp2/. Most frequent landing pages without the filter: $list. Try pathMatch=contains or any.",
            ['rows' => [], 'totals' => ['sessions' => $sessions, 'keyEvents' => $keyEvents], 'topLandingPages' => $topPages, 'filter' => ['mode' => $mode, 'path' => $raw]]);
    }

    // ---- GA4 Admin tools ----

    /** Events of the last days with counts (Data API). @return list<array{eventName:string,eventCount:int|float}> */
    public static function recentEvents(int $days): array
    {
        $rows = Ga4::runReport([
            'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'today']], 'dimensions' => [['name' => 'eventName']], 'metrics' => [['name' => 'eventCount']],
            'orderBys' => [['metric' => ['metricName' => 'eventCount'], 'desc' => true]], 'limit' => 250,
        ])['rows'];
        return array_map(fn($r) => ['eventName' => (string) ($r['eventName'] ?? ''), 'eventCount' => Js::num($r['eventCount'] ?? null)], $rows);
    }

    /** Shared guard of the GA4 Admin tools: no property -> message; 403 errors get a hint about the Analytics scope. */
    private static function guarded(callable $fn): array
    {
        try {
            if (!config('ga4_property_id')) {
                return Mcp::error(self::NO_PROPERTY);
            }
            return $fn();
        } catch (\Throwable $e) {
            $m = $e->getMessage();
            return Mcp::error(preg_match('/403|PERMISSION|insufficient/i', $m) ? "$m. If this is a missing Analytics scope, click \"Connect with Google\" on the connector home page again." : $m);
        }
    }

    private static function yesNo(mixed $v): string
    {
        return $v === null ? '' : ($v ? 'yes' : 'no');
    }

    public static function keyEvents(): array
    {
        return self::guarded(function (): array {
            $keys = Ga4Admin::listKeyEvents();
            $recent = null;
            $recentErr = '';
            try {
                $recent = self::recentEvents(7);
            } catch (\Throwable $e) {
                $recentErr = $e->getMessage();
            }
            $keySet = array_column($keys, 'eventName');
            $warnings = KeyEvents::warnings($keys, $recent);
            $keyRows = array_map(fn($k) => ['eventName' => $k['eventName'], 'countingMethod' => $k['countingMethod'], 'createTime' => $k['createTime'] ?? '', 'deletable' => self::yesNo($k['deletable'] ?? null)], $keys);
            $evRows = array_map(fn($e) => ['eventName' => $e['eventName'], 'eventCount' => $e['eventCount'], 'keyEvent' => in_array($e['eventName'], $keySet, true) ? 'yes' : 'no'], $recent ?? []);
            $lines = ['GA4 key events: ' . count($keys) . '.', $keys ? Table::toTsv($keyRows) : 'No key events.', '',
                $recent !== null ? 'Events of the last 7 days: ' . count($evRows) . '.' : "Could not fetch the events of the last 7 days: $recentErr"];
            if ($evRows) {
                $lines[] = Table::toTsv($evRows);
            }
            if ($warnings) {
                array_push($lines, '', ...array_map(fn($w) => "Warning: $w", $warnings));
            }
            return Mcp::json(implode("\n", $lines), ['keyEvents' => $keyRows, 'recentEvents' => $evRows, 'warnings' => $warnings]);
        });
    }

    public static function dataStreams(): array
    {
        return self::guarded(function (): array {
            $s = Ga4Admin::listDataStreams();
            $rows = array_map(fn($x) => ['type' => $x['type'], 'measurementId' => $x['measurementId'] ?? '', 'defaultUri' => $x['defaultUri'] ?? '', 'displayName' => $x['displayName'] ?? ''], $s);
            return Mcp::json('GA4 data streams: ' . count($s) . '.' . ($s ? "\n" . Table::toTsv($rows) : ''), ['streams' => $rows]);
        });
    }

    public static function adsLinks(): array
    {
        return self::guarded(function (): array {
            $links = Ga4Admin::listGoogleAdsLinks();
            $mine = Ads::customerId();
            $rows = array_map(fn($l) => [
                'customerId' => $l['customerId'], 'status' => str_replace('-', '', $l['customerId']) === $mine ? 'OK' : 'other account',
                'adsPersonalizationEnabled' => self::yesNo($l['adsPersonalizationEnabled'] ?? null), 'canManageClients' => !empty($l['canManageClients']) ? 'yes' : 'no',
                'createTime' => $l['createTime'] ?? '',
            ], $links);
            $linked = (bool) array_filter($rows, fn($r) => $r['status'] === 'OK');
            $verdict = $linked
                ? "OK: the property is linked to Google Ads account $mine."
                : "NOT linked to Google Ads account $mine" . ($links ? ' (only other accounts are linked)' : ' (no links at all)') . ' - link manually in GA4: Admin → Product links → Google Ads links.';
            return Mcp::json($verdict . ($rows ? "\n" . Table::toTsv($rows) : ''), ['linked' => $linked, 'links' => $rows]);
        });
    }
}
