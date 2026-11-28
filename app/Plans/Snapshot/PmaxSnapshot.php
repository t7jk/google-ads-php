<?php
declare(strict_types=1);

namespace App\Plans\Snapshot;

use App\Google\Ads;
use App\Plans\Json;
use App\Plans\PlanQueries;

/**
 * Campaign configuration snapshot (SEARCH and Performance Max; port of ads/pmax-snapshot.ts). Shape: campaign, budget, criteria[],
 * assetGroups[], groupAssets[], signals[], campaignAssets[], goalConfig|null, campaignGoals[], customerGoals[], conversionActions[], audiences[].
 * Also used by D2 plans (asset groups, extensions, signals); the fingerprint is always built from the selected parts.
 */
final class PmaxSnapshot
{
    private const KEYS = ['campaign', 'criteria', 'assetGroups', 'groupAssets', 'signals', 'campaignAssets', 'goalConfig', 'campaignGoals', 'customerGoals', 'conversionActions', 'audiences'];

    public static function load(string $campaignId): array
    {
        $r = [];
        foreach (PlanQueries::pmaxSnapshot($campaignId) as $key => $gaql) {
            $r[$key] = Ads::search($gaql);
        }
        $row = $r['campaign'][0] ?? [];
        return [
            'campaign' => $row['campaign'] ?? null, 'budget' => $row['campaignBudget'] ?? null, 'criteria' => $r['criteria'],
            'assetGroups' => $r['assetGroups'], 'groupAssets' => $r['groupAssets'], 'signals' => $r['signals'], 'campaignAssets' => $r['campaignAssets'],
            'goalConfig' => $r['goalConfig'][0] ?? null, 'campaignGoals' => $r['campaignGoals'], 'customerGoals' => $r['customerGoals'],
            'conversionActions' => $r['conversionActions'], 'audiences' => $r['audiences'],
        ];
    }

    public static function rn(mixed $row): string
    {
        if (!is_array($row)) {
            return Json::encode($row);
        }
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        return implode('|', array_map(fn($k) => is_array($row[$k]) ? (string) ($row[$k]['resourceName'] ?? '') : '', $keys));
    }

    public static function rowKey(array $row): string
    {
        return self::rn($row) . '#' . Json::encode($row);
    }

    /** @param list<string> $parts snapshot part names (keys) */
    public static function fingerprint(array $s, array $parts): string
    {
        $out = [];
        sort($parts, SORT_STRING);
        foreach ($parts as $p) {
            $v = $s[$p] ?? null;
            $out[$p] = is_array($v) && array_is_list($v) ? Fp::sortBy(array_map([Fp::class, 'clean'], $v), [self::class, 'rowKey']) : $v;
        }
        return Fp::finish($out);
    }

    /** Data for the account-level goals plan (scope=account): account goals, conversion actions and campaigns using account-level goals. */
    public static function accountGoalsData(): array
    {
        $q = PlanQueries::pmaxSnapshot('0');
        $customerGoals = Ads::search($q['customerGoals']);
        $actions = Ads::search($q['conversionActions']);
        $cfgRows = Ads::search(PlanQueries::CAMPAIGN_GOAL_CONFIGS);
        $atCustomer = [];
        foreach ($cfgRows as $r) {
            if (($r['conversionGoalCampaignConfig']['goalConfigLevel'] ?? null) === 'CUSTOMER' && ($r['campaign']['status'] ?? null) !== 'REMOVED') {
                $atCustomer[] = ['id' => (string) ($r['campaign']['id'] ?? ''), 'name' => (string) ($r['campaign']['name'] ?? $r['campaign']['id'] ?? '')];
            }
        }
        return ['customerGoals' => $customerGoals, 'conversionActions' => $actions, 'campaignsAtCustomerLevel' => $atCustomer];
    }
}
