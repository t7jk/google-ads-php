<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Google\Ads;

/** A1a: conversions by action (and optionally by day) + non-REMOVED actions without conversions in the period. */
final class Conversions
{
    public static function view(array $a): array
    {
        $raw = Ads::search(Queries::conversions($a['campaignId'], $a['b'], $a['byDay']));
        $actions = Ads::search(Queries::conversionActionsState());
        $info = [];
        foreach ($actions as $r) {
            $info[(string) Fmt::get($r, 'conversionAction.id')] = $r['conversionAction'] ?? null;
        }
        $agg = [];
        foreach ($raw as $r) {
            $aid = Fmt::lastId(Fmt::get($r, 'segments.conversionAction'));
            $ca = $info[$aid] ?? null;
            if (!$ca) {
                continue; // skip REMOVED (or unknown) actions
            }
            $key = implode('|', [Fmt::get($r, 'campaign.id', ''), $aid, $a['byDay'] ? Fmt::get($r, 'segments.date', '') : '']);
            $agg[$key] ??= ['campaignId' => (string) Fmt::get($r, 'campaign.id', ''), 'campaign' => Fmt::get($r, 'campaign.name', ''), 'actionId' => $aid,
                'action' => $ca['name'] ?? Fmt::get($r, 'segments.conversionActionName', '')]
                + ($a['byDay'] ? ['date' => Fmt::get($r, 'segments.date', '')] : []) + ['_c' => 0, '_a' => 0, '_v' => 0];
            $agg[$key]['_c'] += Fmt::num(Fmt::get($r, 'metrics.conversions'));
            $agg[$key]['_a'] += Fmt::num(Fmt::get($r, 'metrics.allConversions'));
            $agg[$key]['_v'] += Fmt::num(Fmt::get($r, 'metrics.conversionsValue'));
        }
        $withData = [];
        foreach ($agg as $x) {
            [$c, $al, $v] = [$x['_c'], $x['_a'], $x['_v']];
            unset($x['_c'], $x['_a'], $x['_v']);
            $m = self::meta($info[$x['actionId']] ?? null);
            $withData[] = $x + ['conversions' => Fmt::r2($c), 'allConversions' => Fmt::r2($al), 'conversionsValue' => Fmt::r2($v)] + $m
                + ['countsTowardConversions' => ($m['includeInConversionsMetric'] === true) ? 'yes' : 'no'];
        }
        usort($withData, fn($x, $y) => ($y['conversions'] <=> $x['conversions']) ?: ($y['allConversions'] <=> $x['allConversions']));
        $seen = array_flip(array_map(fn($r) => (string) $r['actionId'], $withData));
        $zero = [];
        foreach ($info as $id => $ca) {
            if (isset($seen[(string) $id])) {
                continue;
            }
            $zero[] = ['campaignId' => '', 'campaign' => '', 'actionId' => (string) $id, 'action' => $ca['name'] ?? ''] + ($a['byDay'] ? ['date' => ''] : [])
                + ['conversions' => 0, 'allConversions' => 0, 'conversionsValue' => 0] + self::meta($ca)
                + ['countsTowardConversions' => (($ca['includeInConversionsMetric'] ?? null) === true) ? 'yes' : 'no'];
        }
        $label = Fmt::rangeLabel($a['b']);
        $scope = $a['campaignId'] ? "campaign {$a['campaignId']}" : 'whole account';
        return [
            'header' => "Conversions by action, $scope, $label" . ($a['byDay'] ? ', daily breakdown' : '') . '; actions without conversions in the period: ' . count($zero) . '.',
            'rows' => [...$withData, ...$zero],
            'extra' => ['range' => $label, 'actionsWithoutConversions' => count($zero)],
            'footer' => 'The conversions column counts only actions with includeInConversionsMetric=true (see the countsTowardConversions column); allConversions also includes the other actions.',
        ];
    }

    private static function meta(?array $ca): array
    {
        return ['status' => $ca['status'] ?? null, 'type' => $ca['type'] ?? null, 'primaryForGoal' => $ca['primaryForGoal'] ?? null, 'includeInConversionsMetric' => $ca['includeInConversionsMetric'] ?? null];
    }
}
