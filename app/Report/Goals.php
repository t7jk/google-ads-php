<?php
declare(strict_types=1);

namespace App\Report;

/** Port of src/ads/goals.ts (read part): manual-only action types and the effective conversion goals of a campaign. */
final class Goals
{
    /**
     * Conversion action types that are read-only in the Google Ads API (a mutate ends with "Mutates are not allowed").
     * Source: ConversionActionTypeEnum documentation ("Read only" fields) + WEBPAGE_CODELESS confirmed on a real account.
     * All SMART_CAMPAIGN_* types are matched by prefix. If validateOnly returns MUTATE_NOT_ALLOWED for another type, add it here.
     */
    private const NON_MUTABLE = [
        'WEBPAGE_CODELESS', 'GOOGLE_HOSTED', 'STORE_VISITS', 'STORE_SALES', 'LEAD_FORM_SUBMIT', 'SEARCH_ADS_360',
        'FLOODLIGHT_ACTION', 'FLOODLIGHT_TRANSACTION', 'ANDROID_APP_PRE_REGISTRATION', 'ANDROID_INSTALLS_ALL_OTHER_APPS',
    ];

    public static function isNonMutableActionType(mixed $type): bool
    {
        $t = (string) ($type ?? '');
        return in_array($t, self::NON_MUTABLE, true) || str_starts_with($t, 'SMART_CAMPAIGN_');
    }

    /**
     * Rule: primary_for_goal AND the goal (category, origin) is biddable at campaign or account level.
     * @param array $s PMax snapshot (PmaxSnapshot::load)
     * @return list<array{type,actionId,name,category,origin,primary:bool,includeInConversions:?bool,biddable:bool}>
     */
    public static function effective(array $s): array
    {
        $level = Js::get($s['goalConfig'] ?? null, 'conversionGoalCampaignConfig.goalConfigLevel') === 'CAMPAIGN' ? 'CAMPAIGN' : 'CUSTOMER';
        $map = [];
        $rows = $level === 'CAMPAIGN' ? $s['campaignGoals'] : $s['customerGoals'];
        $field = $level === 'CAMPAIGN' ? 'campaignConversionGoal' : 'customerConversionGoal';
        foreach ($rows as $r) {
            $map[Js::str(Js::get($r, "$field.category")) . '~' . Js::str(Js::get($r, "$field.origin"))] = Js::get($r, "$field.biddable") === true;
        }
        $out = [];
        foreach ($s['conversionActions'] as $r) {
            $a = $r['conversionAction'] ?? [];
            if (($a['status'] ?? null) !== 'ENABLED') {
                continue;
            }
            $primary = ($a['primaryForGoal'] ?? null) !== false; // defaults to true
            $key = Js::str($a['category'] ?? null) . '~' . Js::str($a['origin'] ?? null);
            $out[] = [
                'type' => (string) ($a['type'] ?? ''), 'actionId' => (string) ($a['id'] ?? ''), 'name' => (string) ($a['name'] ?? ''),
                'category' => (string) ($a['category'] ?? ''), 'origin' => (string) ($a['origin'] ?? ''),
                'primary' => $primary,
                'includeInConversions' => is_bool($a['includeInConversionsMetric'] ?? null) ? $a['includeInConversionsMetric'] : null,
                'biddable' => $primary && ($map[$key] ?? false),
            ];
        }
        return $out;
    }
}
