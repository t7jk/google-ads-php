<?php
declare(strict_types=1);

namespace App\Report;

use App\Ads\Params;
use App\Ads\Table;
use App\Ads\UserError;
use App\Google\Ads;
use App\Lib\Mcp;

/** The pmax_report tool (port of src/mcp/tools-reports.ts): Performance Max campaign reports with optional period comparison. */
final class PmaxReport
{
    private const ROW_CAP = 10000;
    private const DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    public static function run(array $args): array
    {
        $campaign = Metrics::requireString($args, 'campaign');
        $view = $args['view'] ?? null;
        if (!is_string($view) || !in_array($view, Queries::VIEWS, true)) {
            throw new UserError('Parameter view must be one of: ' . implode(', ', Queries::VIEWS));
        }
        $range = Range::fromArgs($args);
        $compareTo = $args['compareTo'] ?? null;
        if ($compareTo !== null && !in_array($compareTo, ['previous_period', 'previous_year'], true)) {
            throw new UserError('Parameter compareTo must be previous_period or previous_year');
        }
        $limit = Params::int($args, 'limit', 1, 500, 50);

        $cid = Metrics::resolveCampaignId($campaign);
        $cols = Queries::viewMetrics($view);
        $b = Range::dailyBounds($range, CampaignTools::now(), CampaignTools::tz());
        $raw = Ads::search(Queries::view($view, $cid, $b, self::ROW_CAP));
        $rows = ReportRows::build($raw, true, $cols);
        $capped = count($raw) >= self::ROW_CAP;
        $note = '';
        $extra = '';
        if ($compareTo) {
            $pb = Range::compareBounds($b, $compareTo);
            if (!$pb) {
                return Mcp::error('compareTo does not work for the ALL_TIME range - provide a specific date range.');
            }
            $rawPrev = Ads::search(Queries::view($view, $cid, $pb, self::ROW_CAP));
            $capped = $capped || count($rawPrev) >= self::ROW_CAP;
            [$rows, $onlyPrev] = self::withDeltas($rows, ReportRows::build($rawPrev, true, $cols));
            $extra = $onlyPrev ? " Only in the previous period: $onlyPrev." : '';
            $note = ' Comparison: ' . ($compareTo === 'previous_year' ? 'year earlier' : 'previous period') . " ({$pb['from']} – {$pb['to']}).";
        }
        self::sort($rows, $view);
        $total = count($rows);
        $rows = array_slice($rows, 0, $limit);
        if (!$total) {
            return Mcp::json("No data: view $view, campaign $cid.$note", ['view' => $view, 'campaignId' => $cid, 'rows' => []]);
        }
        $label = $b ? "{$b['from']} – {$b['to']}" : 'current state';
        $capNote = $capped ? ' Note: Google returned ' . self::ROW_CAP . '+ rows, the result is truncated and may be incomplete - narrow the date range.' : '';
        return Mcp::json("Report $view, campaign $cid, $label; " . count($rows) . "/$total rows.$note$extra$capNote\n" . Table::toTsv($rows),
            ['view' => $view, 'campaignId' => $cid, 'range' => $label, 'total' => $total, 'rows' => $rows]);
    }

    /** Adds Δ columns relative to the previous period. @return array{0:list<array>,1:int} rows and the number of rows present only in the previous period */
    private static function withDeltas(array $cur, array $prev): array
    {
        $pm = [];
        foreach ($prev as $r) {
            $pm[ReportRows::descKey($r)] = $r;
        }
        $curKeys = array_flip(array_map([ReportRows::class, 'descKey'], $cur));
        $onlyPrev = count(array_filter($prev, fn($r) => !isset($curKeys[ReportRows::descKey($r)])));
        $rows = array_map(function ($r) use ($pm) {
            $p = $pm[ReportRows::descKey($r)] ?? null;
            $d = [];
            if (isset($r['impressions'])) {
                $d['Δimpressions'] = $r['impressions'] - Js::num($p['impressions'] ?? null);
            }
            if (isset($r['clicks'])) {
                $d['Δclicks'] = $r['clicks'] - Js::num($p['clicks'] ?? null);
            }
            if (isset($r['cost'])) {
                $d['Δcost'] = Js::round2($r['cost'] - Js::num($p['cost'] ?? null));
            }
            if (isset($r['conversions'])) {
                $d['Δconversions'] = Js::round2($r['conversions'] - Js::num($p['conversions'] ?? null));
            }
            return $r + $d;
        }, $cur);
        return [$rows, $onlyPrev];
    }

    private static function sort(array &$rows, string $view): void
    {
        if ($view === 'hours') {
            usort($rows, function ($a, $c) {
                $da = array_search((string) ($a['segments.dayOfWeek'] ?? ''), self::DAYS, true);
                $dc = array_search((string) ($c['segments.dayOfWeek'] ?? ''), self::DAYS, true);
                return ($da === false ? -1 : $da) <=> ($dc === false ? -1 : $dc) ?: Js::num($a['segments.hour'] ?? null) <=> Js::num($c['segments.hour'] ?? null);
            });
            return;
        }
        usort($rows, fn($a, $c) => Js::num($c['cost'] ?? null) <=> Js::num($a['cost'] ?? null) ?: Js::num($c['impressions'] ?? null) <=> Js::num($a['impressions'] ?? null));
    }
}
