<?php
declare(strict_types=1);

namespace App\Report;

use App\Google\Ads;

/** Port src/ads/pmax-snapshot.ts (loadPmaxSnapshot): full campaign configuration (PMax and Search) without metrics. */
final class PmaxSnapshot
{
    /**
     * @return array{campaign:?array,budget:?array,criteria:list,assetGroups:list,groupAssets:list,signals:list,campaignAssets:list,goalConfig:?array,campaignGoals:list,customerGoals:list,conversionActions:list,audiences:list}
     */
    public static function load(string $campaignId): array
    {
        $q = Queries::pmaxSnapshot($campaignId);
        $r = [];
        foreach (Queries::PMAX_KEYS as $key) {
            $r[$key] = Ads::search($q[$key]);
        }
        $row = $r['campaign'][0] ?? [];
        return [
            'campaign' => $row['campaign'] ?? null, 'budget' => $row['campaignBudget'] ?? null,
            'criteria' => $r['criteria'], 'assetGroups' => $r['assetGroups'], 'groupAssets' => $r['groupAssets'],
            'signals' => $r['signals'], 'campaignAssets' => $r['campaignAssets'],
            'goalConfig' => $r['goalConfig'][0] ?? null, 'campaignGoals' => $r['campaignGoals'],
            'customerGoals' => $r['customerGoals'], 'conversionActions' => $r['conversionActions'], 'audiences' => $r['audiences'],
        ];
    }
}
