<?php
declare(strict_types=1);

namespace App\Plans;

/** Conversion goals: read-only action types and "effective" bidding goals (port of ads/goals.ts). Shared with the D2 plans (conversion actions). */
final class Goals
{
    /**
     * Read-only conversion action types in the Google Ads API (mutate fails with "Mutates are not allowed").
     * Source: ConversionActionTypeEnum ("Read only" fields), checked 2026-11-09 (v26); WEBPAGE_CODELESS confirmed on a real account.
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
     * Rule found by research: primary_for_goal AND the goal (category, origin) is biddable at campaign or account level.
     * @param array $s snapshot with keys goalConfig, campaignGoals, customerGoals, conversionActions
     * @return list<array{type: string, actionId: string, name: string, category: string, origin: string, primary: bool, includeInConversions: ?bool, biddable: bool}>
     */
    public static function effective(array $s): array
    {
        $level = ($s['goalConfig']['conversionGoalCampaignConfig']['goalConfigLevel'] ?? null) === 'CAMPAIGN' ? 'CAMPAIGN' : 'CUSTOMER';
        $map = [];
        if ($level === 'CAMPAIGN') {
            foreach ($s['campaignGoals'] ?? [] as $r) {
                $g = $r['campaignConversionGoal'] ?? [];
                $map[($g['category'] ?? '') . '~' . ($g['origin'] ?? '')] = ($g['biddable'] ?? null) === true;
            }
        } else {
            foreach ($s['customerGoals'] ?? [] as $r) {
                $g = $r['customerConversionGoal'] ?? [];
                $map[($g['category'] ?? '') . '~' . ($g['origin'] ?? '')] = ($g['biddable'] ?? null) === true;
            }
        }
        $out = [];
        foreach ($s['conversionActions'] ?? [] as $r) {
            $a = $r['conversionAction'] ?? [];
            if (($a['status'] ?? null) !== 'ENABLED') {
                continue;
            }
            $primary = ($a['primaryForGoal'] ?? null) !== false; // defaults to true
            $out[] = [
                'type' => (string) ($a['type'] ?? ''), 'actionId' => (string) ($a['id'] ?? ''), 'name' => (string) ($a['name'] ?? ''),
                'category' => (string) ($a['category'] ?? ''), 'origin' => (string) ($a['origin'] ?? ''),
                'primary' => $primary, 'includeInConversions' => is_bool($a['includeInConversionsMetric'] ?? null) ? $a['includeInConversionsMetric'] : null,
                'biddable' => $primary && ($map[($a['category'] ?? '') . '~' . ($a['origin'] ?? '')] ?? false),
            ];
        }
        return $out;
    }
}
