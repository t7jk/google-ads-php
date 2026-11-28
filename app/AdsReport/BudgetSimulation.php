<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Google\Ads;

/** C9: campaign simulations (current state); budget points above the connector limit are flagged. */
final class BudgetSimulation
{
    /** key = point-list field in campaign_simulation, param = parameter display name, kind: zl (micros -> PLN) or plain. */
    private const LISTS = [
        ['key' => 'budgetPointList', 'param' => 'daily budget', 'valueKey' => 'budgetAmountMicros', 'kind' => 'zl', 'label' => 'BUDGET'],
        ['key' => 'targetCpaPointList', 'param' => 'target CPA', 'valueKey' => 'targetCpaMicros', 'kind' => 'zl', 'label' => 'TARGET_CPA'],
        ['key' => 'targetRoasPointList', 'param' => 'target ROAS', 'valueKey' => 'targetRoas', 'kind' => 'plain', 'label' => 'TARGET_ROAS'],
        ['key' => 'targetImpressionSharePointList', 'param' => 'target impression share', 'valueKey' => 'targetImpressionShareMicros', 'kind' => 'plain', 'label' => 'TARGET_IMPRESSION_SHARE'],
        ['key' => 'cpcBidPointList', 'param' => 'CPC bid', 'valueKey' => 'cpcBidMicros', 'kind' => 'zl', 'label' => 'CPC_BID'],
    ];
    private const NONE = 'Google does not provide simulations for this campaign yet (usually a few weeks of data are needed).';

    private static function ymd(mixed $d): ?string
    {
        return $d ? str_replace('/', '-', (string) $d) : null;
    }

    public static function view(array $a): array
    {
        $limit = $a['maxBudgetPln'] ?? 30;
        $sims = Ads::search(Queries::campaignSimulation($a['campaignId']));
        $byId = [];
        foreach (Ads::search(Queries::simulationCampaigns($a['campaignId'])) as $c) {
            $byId[(string) Fmt::get($c, 'campaign.id')] = $c;
        }
        $rows = [];
        foreach ($sims as $s) {
            $sim = $s['campaignSimulation'] ?? [];
            $c = $byId[(string) ($sim['campaignId'] ?? '')] ?? null;
            if (!$c) {
                continue; // campaign removed
            }
            foreach (self::LISTS as $l) {
                foreach (Fmt::list(Fmt::get($sim, $l['key'] . '.points')) as $p) {
                    $raw = $p[$l['valueKey']] ?? null;
                    $v = $l['kind'] === 'zl' ? Fmt::zl($raw) : ($raw !== null ? Fmt::r2(Fmt::num($raw)) : null);
                    $above = $l['label'] === 'BUDGET' && $v !== null && $v > $limit;
                    $rows[] = [
                        'campaignId' => (string) $sim['campaignId'], 'campaign' => Fmt::get($c, 'campaign.name', ''), 'simulationType' => $sim['type'] ?? $l['label'], 'modification' => $sim['modificationMethod'] ?? null,
                        'startDate' => self::ymd($sim['startDate'] ?? null), 'endDate' => self::ymd($sim['endDate'] ?? null), 'parameter' => $l['param'], 'value' => $v,
                        'currentBudget' => Fmt::zl(Fmt::get($c, 'campaignBudget.amountMicros')),
                        'clicks' => Fmt::r2(Fmt::num($p['clicks'] ?? null)), 'impressions' => Fmt::r2(Fmt::num($p['impressions'] ?? null)),
                        'cost' => Fmt::zl($p['costMicros'] ?? null) ?? 0, 'conversions' => Fmt::r2(Fmt::num($p['biddableConversions'] ?? null)),
                        'note' => $above ? "above the connector limit ($limit PLN/day)" : null,
                    ];
                }
            }
        }
        $aboveCount = count(array_filter($rows, fn($r) => $r['note']));
        $scope = $a['campaignId'] ? "campaign {$a['campaignId']}" : 'all non-REMOVED campaigns';
        return [
            'header' => "Google simulations ($scope): points " . count($rows) . ($aboveCount ? ", of which above the connector limit ($limit PLN/day): $aboveCount" : '') . '.',
            'rows' => $rows, 'extra' => ['limitPln' => $limit, 'aboveLimit' => $aboveCount],
            'empty' => $a['campaignId'] ? self::NONE . " View: $scope." : "Google does not provide simulations for any campaign yet (usually a few weeks of data are needed). View: $scope.",
            'footer' => "Cost, clicks, impressions and conversions are Google's predictions for the given point (value: PLN or the target value). Budget points above $limit PLN/day are informational only: the connector will not set a higher budget anyway.",
        ];
    }
}
