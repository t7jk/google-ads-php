<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Google\Ads;

/** A3: Google recommendations (list only) + optimization score. */
final class Recommendations
{
    public static function view(array $a): array
    {
        $score = Ads::search(Queries::optimizationScore());
        $active = Ads::search(Queries::recommendations($a['campaignId'], false));
        $dismissed = $a['includeDismissed'] ? Ads::search(Queries::recommendations($a['campaignId'], true)) : [];
        $cu = $score[0]['customer'] ?? [];
        $pct = isset($cu['optimizationScore']) ? Fmt::r2(Fmt::num($cu['optimizationScore']) * 100) : null;
        $rows = array_map([self::class, 'row'], [...$active, ...$dismissed]);
        $scoreText = 'Optimization score: ' . ($pct === null ? 'no data' : "$pct%")
            . (isset($cu['optimizationScoreWeight']) ? ', weight ' . Fmt::r2(Fmt::num($cu['optimizationScoreWeight'])) : '');
        $out = [
            'header' => "$scoreText; recommendations: " . count($rows) . ($a['includeDismissed'] ? ' (including dismissed)' : '') . '.',
            'rows' => $rows,
            'extra' => ['optimizationScorePct' => $pct, 'optimizationScoreWeight' => $cu['optimizationScoreWeight'] ?? null],
            'footer' => 'List only: the tool neither applies nor dismisses anything. The *Potential and *Delta columns are the impact predicted by Google (if provided).',
        ];
        if (!$rows) {
            $out['empty'] = "$scoreText. No recommendations" . ($a['campaignId'] ? " for campaign {$a['campaignId']}" : '') . '.';
        }
        return $out;
    }

    private static function metricsOf(mixed $m): array
    {
        $m = is_array($m) ? $m : [];
        return ['impressions' => Fmt::num($m['impressions'] ?? null), 'clicks' => Fmt::num($m['clicks'] ?? null),
            'cost' => Fmt::r2(Fmt::num($m['costMicros'] ?? null) / 1e6), 'conversions' => Fmt::r2(Fmt::num($m['conversions'] ?? null))];
    }

    private static function row(array $r): array
    {
        $rec = $r['recommendation'] ?? [];
        $base = self::metricsOf(Fmt::get($rec, 'impact.baseMetrics'));
        $pot = self::metricsOf(Fmt::get($rec, 'impact.potentialMetrics'));
        $campaignId = isset($r['campaign']['id']) ? (string) $r['campaign']['id'] : (Fmt::lastId($rec['campaign'] ?? null) ?: null);
        $row = ['type' => $rec['type'] ?? null, 'campaignId' => $campaignId, 'campaign' => Fmt::get($r, 'campaign.name'), 'dismissed' => ($rec['dismissed'] ?? null) === true];
        if (isset($rec['impact'])) {
            $row += [
                'impressions' => $base['impressions'], 'impressionsPotential' => $pot['impressions'], 'impressionsDelta' => $pot['impressions'] - $base['impressions'],
                'clicks' => $base['clicks'], 'clicksPotential' => $pot['clicks'], 'clicksDelta' => $pot['clicks'] - $base['clicks'],
                'cost' => $base['cost'], 'costPotential' => $pot['cost'], 'costDelta' => Fmt::r2($pot['cost'] - $base['cost']),
                'conversions' => $base['conversions'], 'conversionsPotential' => $pot['conversions'], 'conversionsDelta' => Fmt::r2($pot['conversions'] - $base['conversions']),
            ];
        }
        return $row;
    }
}
