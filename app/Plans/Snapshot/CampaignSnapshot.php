<?php
declare(strict_types=1);

namespace App\Plans\Snapshot;

use App\Google\Ads;
use App\Plans\Json;
use App\Plans\PlanQueries;

/**
 * Search campaign snapshot (port of loadCampaignSnapshot + snapshotFingerprint from build-changes.ts).
 * Shape: campaign, budget, adGroups[], ads[], keywords[], negatives[], locations[] (GAQL rows as returned by Ads::search).
 */
final class CampaignSnapshot
{
    public static function load(string $campaignId): array
    {
        $q = PlanQueries::campaignSnapshot($campaignId);
        $r = [];
        foreach ($q as $key => $gaql) {
            $r[$key] = Ads::search($gaql); // sequential, not parallel: PHP has no threads
        }
        $row = $r['campaign'][0] ?? [];
        return [
            'campaign' => $row['campaign'] ?? null, 'budget' => $row['campaignBudget'] ?? null,
            'adGroups' => $r['adGroups'], 'ads' => $r['ads'], 'keywords' => $r['keywords'], 'negatives' => $r['negatives'], 'locations' => $r['locations'],
        ];
    }

    public static function rn(mixed $row): string
    {
        if (!is_array($row)) {
            return '';
        }
        foreach (['adGroupAd', 'adGroupCriterion', 'campaignCriterion', 'adGroup', 'campaign'] as $k) {
            if (!empty($row[$k]['resourceName'])) {
                return (string) $row[$k]['resourceName'];
            }
        }
        return Json::encode($row);
    }

    /** Fingerprint independent of row order and of fields computed by Google. */
    public static function fingerprint(array $s): string
    {
        foreach (['adGroups', 'ads', 'keywords', 'negatives', 'locations'] as $k) {
            $s[$k] = Fp::sortBy($s[$k] ?? [], [self::class, 'rn']);
        }
        return Fp::finish($s);
    }
}
