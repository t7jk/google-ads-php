<?php
// Core plan kinds (port of the snapshots.ts registry + parts of the executor). Definition format: see App\Plans\Kinds.
declare(strict_types=1);

use App\Plans\Build\{Exclusions, GoalsPlan, Negatives};
use App\Plans\ConversionActionMessage;
use App\Plans\Snapshot\{CampaignSnapshot, PmaxSnapshot};

$campaignFp = fn(array $plan): string => CampaignSnapshot::fingerprint(CampaignSnapshot::load($plan['campaignId']));

return [
    'selftest' => ['noSnapshot' => true],
    'new_campaign' => ['noSnapshot' => true],
    // New conversion action: the message after execution contains the tag data of the NEWLY created action (or a note that there is no tag).
    'conversion_action' => ['noSnapshot' => true, 'message' => fn(array $plan, array $planOps, array $results, string $default): string => ConversionActionMessage::build($planOps, $results, $default)],
    'campaign_changes' => ['needsCampaign' => true, 'fingerprint' => $campaignFp],
    // Pause: loader chosen by the campaign type stored in the plan (PMax: campaign and asset groups).
    'pause' => ['needsCampaign' => true, 'fingerprint' => fn(array $plan): string => ($plan['channel'] ?? null) === 'PERFORMANCE_MAX'
        ? PmaxSnapshot::fingerprint(PmaxSnapshot::load($plan['campaignId']), ['campaign', 'assetGroups'])
        : $campaignFp($plan)],
    // Campaign settings (SEARCH and PMax): fingerprint of the campaign and its criteria.
    'campaign_settings' => ['needsCampaign' => true, 'fingerprint' => fn(array $plan): string => PmaxSnapshot::fingerprint(PmaxSnapshot::load($plan['campaignId']), ['campaign', 'criteria'])],
    // Conversion goals: campaign (four snapshot parts) or account (account goals and actions).
    'conversion_goals' => ['fingerprint' => function (array $plan): string {
        if (($plan['goalScope'] ?? null) === 'account') {
            return GoalsPlan::accountFingerprint(PmaxSnapshot::accountGoalsData());
        }
        if (empty($plan['campaignId'])) {
            throw new \RuntimeException('Campaign goals plan does not specify a campaign');
        }
        return PmaxSnapshot::fingerprint(PmaxSnapshot::load($plan['campaignId']), ['goalConfig', 'campaignGoals', 'customerGoals', 'conversionActions']);
    }],
    // Negative keywords: campaign negatives (when the plan has a campaign) and the account's shared lists.
    'negative_keywords' => ['fingerprint' => fn(array $plan): string => Negatives::fingerprint(Negatives::load($plan['campaignId'] ?? null))],
    // Account exclusions: list of customer_negative_criterion criteria.
    'account_exclusions' => ['fingerprint' => fn(array $plan): string => Exclusions::fingerprint(Exclusions::load())],
];
