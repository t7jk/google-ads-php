<?php
declare(strict_types=1);

namespace App\Report;

use App\Ads\Table;

/** Port of reportRows/descKey from src/mcp/tools-reports.ts: GAQL rows -> compact PMax report rows. */
final class ReportRows
{
    private const METRIC_COLS = '/^(impressions|clicks|cost|ctr|cpa|conversions)$/';
    public const ALL = ['impressions', 'clicks', 'cost', 'conversions'];

    /**
     * Groups by descriptive columns; adds only the metric columns that the view actually fetches.
     * @param list<array> $rows raw GAQL rows
     * @return list<array<string,mixed>>
     */
    public static function build(array $rows, bool $hasMetrics, array $cols = self::ALL): array
    {
        $out = [];
        foreach ($rows as $raw) {
            $metrics = $raw['metrics'] ?? null;
            unset($raw['metrics']);
            $base = Table::flattenRow($raw);
            unset($base['campaignSearchTermInsight.id'], $base['campaign.id']);
            $k = $hasMetrics ? json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) count($out);
            $out[$k] ??= ['base' => $base, 'i' => 0, 'cl' => 0, 'c' => 0, 'cv' => 0];
            if ($metrics) {
                $out[$k]['i'] += Js::num($metrics['impressions'] ?? null);
                $out[$k]['cl'] += Js::num($metrics['clicks'] ?? null);
                $out[$k]['c'] += Js::num($metrics['costMicros'] ?? null);
                $out[$k]['cv'] += Js::num($metrics['conversions'] ?? null);
            }
        }
        $has = fn(string $c): bool => $hasMetrics && in_array($c, $cols, true);
        $result = [];
        foreach ($out as $r) {
            $cost = Js::round2($r['c'] / 1e6);
            $row = $r['base'];
            if ($has('impressions')) {
                $row['impressions'] = $r['i'];
            }
            if ($has('clicks')) {
                $row['clicks'] = $r['cl'];
            }
            if ($has('conversions')) {
                $row['conversions'] = Js::round2($r['cv']);
            }
            if ($has('cost')) {
                $row['cost'] = $cost;
            }
            if ($has('impressions') && $has('clicks')) {
                $row['ctr'] = $r['i'] ? Js::round2($r['cl'] / $r['i'] * 100) : 0;
            }
            if ($has('cost') && $has('conversions')) {
                $row['cpa'] = $r['cv'] > 0 ? Js::round2($cost / $r['cv']) : null;
            }
            $result[] = $row;
        }
        return $result;
    }

    /** Descriptive row key (without metric columns) for joining periods. */
    public static function descKey(array $r): string
    {
        $desc = [];
        foreach ($r as $k => $v) {
            if (!preg_match(self::METRIC_COLS, (string) $k)) {
                $desc[] = [(string) $k, $v];
            }
        }
        return (string) json_encode($desc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
