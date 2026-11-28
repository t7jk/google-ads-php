<?php
// D2 plan kinds for Performance Max: asset groups, extensions, signals, custom audiences + an image materialization hook.
// Definition format: see App\Plans\Kinds. Fingerprints use the same functions as the builders (Build\AssetGroup/CampaignAssets/Signals).
declare(strict_types=1);

use App\Ads\MutationRefused;
use App\Google\Ads;
use App\Plans\Build\{AssetGroup, CampaignAssets, Signals};
use App\Plans\{BlobOps};
use App\Plans\Snapshot\PmaxSnapshot;

return [
    // Replaces ['$blob' => sha] references in operations with base64 right before sending to Google (throws MissingBlob).
    '*' => ['materialize' => [BlobOps::class, 'materialize']],
    // Asset group: the campaign, the chosen group with its assets, and the campaign's brand assets.
    'asset_group_changes' => ['needsCampaign' => true,
        'fingerprint' => fn(array $plan): string => AssetGroup::fingerprint(PmaxSnapshot::load($plan['campaignId']), $plan['assetGroupId'] ?? null)],
    // Campaign extensions: the campaign, its assets and the lead form links in groups.
    'campaign_assets' => ['needsCampaign' => true,
        'fingerprint' => fn(array $plan): string => CampaignAssets::fingerprint(PmaxSnapshot::load($plan['campaignId']))],
    // Group signals: the campaign, the chosen group, its signals and the account's audiences.
    'asset_group_signals' => ['needsCampaign' => true,
        'fingerprint' => fn(array $plan): string => Signals::fingerprint(PmaxSnapshot::load($plan['campaignId']), $plan['assetGroupId'] ?? null)],
    // Custom audience: separate endpoint (customAudiences:mutate, create only); fingerprint = list of the account's audience names.
    'custom_audience' => [
        'fingerprint' => fn(array $plan): string => Signals::customAudienceFingerprint(
            array_map(fn($r) => (string) ($r['customAudience']['name'] ?? ''), Ads::search(Signals::CUSTOM_AUDIENCE_NAMES_QUERY))),
        'mutate' => function (array $ops, bool $validateOnly): array {
            $bare = array_map(fn($o) => ['create' => $o['customAudienceOperation']['create'] ?? null], $ops);
            if (array_filter($bare, fn($b) => !$b['create'])) {
                throw new MutationRefused('Audience plan contains disallowed operations');
            }
            return Ads::mutateCustomAudiences($bare, $validateOnly);
        },
        'message' => function (array $plan, array $planOps, array $results, string $default): string {
            $id = basename((string) ($results[0]['resourceName'] ?? '')) ?: '?';
            return "Custom audience created (ID $id). Use it in prepare_asset_group_signals (audience.customAudienceIds).";
        },
    ],
];
