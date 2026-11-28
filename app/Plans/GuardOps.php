<?php
declare(strict_types=1);

namespace App\Plans;

/**
 * Write guard in the mutation layer (port of src/ads/guard-ops.ts). Called by Ads::mutate / uploadClickConversions
 * BEFORE the HTTP request goes out; returns a list of refusal reasons, empty = allowed. Hard rules: no operation enables a campaign,
 * deletes campaigns/groups/budgets/assets/lists/conversion actions, goes beyond known types or exceeds the budget.
 */
final class GuardOps
{
    private const ALLOWED_OP_TYPES = [
        'campaignOperation', 'campaignBudgetOperation', 'campaignCriterionOperation', 'campaignAssetOperation',
        'adGroupOperation', 'adGroupAdOperation', 'adGroupCriterionOperation',
        'adOperation', // change of an ad's final URL
        'assetOperation', 'assetGroupOperation', 'assetGroupAssetOperation', 'assetGroupSignalOperation', 'audienceOperation',
        'conversionActionOperation', 'campaignConversionGoalOperation', 'customerConversionGoalOperation', 'conversionGoalCampaignConfigOperation',
        'sharedSetOperation', 'sharedCriterionOperation', 'campaignSharedSetOperation', 'customerNegativeCriterionOperation',
    ];
    /** Types the connector never removes; only manually in Google Ads. */
    private const NO_REMOVE_OP_TYPES = [
        'campaignOperation', 'assetGroupOperation', 'adGroupOperation', 'campaignBudgetOperation', 'assetOperation', 'sharedSetOperation', 'conversionActionOperation',
    ];
    private const ENABLE_MSG = 'The operation must not enable a campaign (status ENABLED)';
    private const REMOVED_MSG = 'The operation must not remove resources via status REMOVED';
    public const MAX_CLICK_CONVERSIONS = 200;
    private const CLICK_CONVERSION_KEYS = ['gclid', 'wbraid', 'conversionAction', 'conversionDateTime', 'orderId', 'consent'];

    /** @param list<array> $ops @return list<string> */
    public static function checkMutateOps(array $ops, int $maxBudgetMicros, float $maxBudgetPln): array
    {
        $errors = [];
        foreach ($ops as $op) {
            $keys = is_array($op) ? array_keys($op) : [];
            if (count($keys) !== 1) {
                $errors[] = 'The operation must have exactly one key (has ' . count($keys) . ')';
                continue;
            }
            $key = (string) $keys[0];
            if (!in_array($key, self::ALLOWED_OP_TYPES, true)) {
                $errors[] = "Disallowed operation type: $key";
                continue;
            }
            $payload = is_array($op[$key]) ? $op[$key] : [];
            if (in_array($key, self::NO_REMOVE_OP_TYPES, true) && array_key_exists('remove', $payload)) {
                $errors[] = 'The connector does not remove resources of this type (' . preg_replace('/Operation$/', '', $key) . '); remove manually in Google Ads';
                continue;
            }
            if (($payload['create']['status'] ?? null) === 'REMOVED' || ($payload['update']['status'] ?? null) === 'REMOVED') {
                $errors[] = self::REMOVED_MSG;
                continue;
            }
            if ($key === 'campaignBudgetOperation') {
                array_push($errors, ...self::budgetErrors($payload, $maxBudgetMicros, $maxBudgetPln));
            }
            if ($key === 'campaignOperation') {
                array_push($errors, ...self::campaignErrors($payload));
            }
        }
        return $errors;
    }

    private static function budgetErrors(array $payload, int $maxMicros, float $maxPln): array
    {
        $errors = [];
        foreach ([$payload['create'] ?? null, $payload['update'] ?? null] as $body) {
            if (!is_array($body) || !array_key_exists('amountMicros', $body)) {
                continue;
            }
            $raw = $body['amountMicros'];
            if (!(is_int($raw) || (is_string($raw) && preg_match('/^-?\d+$/', $raw)))) {
                $errors[] = 'Invalid budget amount';
                continue;
            }
            if ((int) $raw > $maxMicros) {
                $errors[] = 'Budget PLN ' . ((int) $raw / 1e6) . "/day exceeds the limit of PLN $maxPln/day; change budgets above PLN $maxPln manually in Google Ads";
            }
        }
        return $errors;
    }

    private static function campaignErrors(array $c): array
    {
        $errors = [];
        if (isset($c['create']) && ($c['create']['status'] ?? null) !== 'PAUSED') {
            $errors[] = 'A new campaign must have status PAUSED';
        }
        if (isset($c['update'])) {
            $mask = is_string($c['updateMask'] ?? null) ? array_map('trim', explode(',', $c['updateMask'])) : [];
            $st = $c['update']['status'] ?? null;
            if (in_array('status', $mask, true) && $st !== 'PAUSED') {
                $errors[] = self::ENABLE_MSG;
            } elseif (!in_array('status', $mask, true) && $st === 'ENABLED') {
                $errors[] = self::ENABLE_MSG;
            }
        }
        return $errors;
    }

    /**
     * Offline conversions: only the account's designated action, only gclid/wbraid + GRANTED consent, no values or personal data, max 200.
     * @param list<array> $convs @return list<string>
     */
    public static function checkClickConversions(array $convs, string $customerId): array
    {
        $errors = [];
        if (!$convs) {
            $errors[] = 'No conversions to send';
        }
        if (count($convs) > self::MAX_CLICK_CONVERSIONS) {
            $errors[] = 'At most ' . self::MAX_CLICK_CONVERSIONS . ' conversions per plan (has ' . count($convs) . ')';
        }
        if (count(array_unique(array_map(fn($c) => (string) ($c['conversionAction'] ?? ''), $convs))) > 1) {
            $errors[] = 'All conversions of a plan must belong to a single conversion action';
        }
        $orders = [];
        foreach ($convs as $c) {
            $problem = self::clickConversionProblem(is_array($c) ? $c : [], $customerId, $orders);
            if ($problem !== null) {
                $errors[] = $problem;
                break;
            }
        }
        return $errors;
    }

    /** First problem of one conversion, or null. $orders: collected orderIds (duplicate detection). */
    private static function clickConversionProblem(array $c, string $customerId, array &$orders): ?string
    {
        $extra = array_diff(array_keys($c), self::CLICK_CONVERSION_KEYS);
        if ($extra) {
            return 'Disallowed conversion fields: ' . implode(', ', $extra);
        }
        if (!preg_match('#^customers/' . preg_quote($customerId, '#') . '/conversionActions/\d+$#', (string) ($c['conversionAction'] ?? ''))) {
            return 'The conversion action must belong to the configured account';
        }
        if (!empty($c['gclid']) === !empty($c['wbraid'])) {
            return 'A conversion must have exactly one identifier: gclid or wbraid';
        }
        if (empty($c['orderId'])) {
            return 'A conversion must have an orderId (deduplication)';
        }
        $order = (string) $c['orderId'];
        if (isset($orders[$order])) {
            return "Duplicate orderId: $order";
        }
        $orders[$order] = true;
        $consent = $c['consent'] ?? [];
        if (($consent['adUserData'] ?? null) !== 'GRANTED' || count((array) $consent) !== 1) {
            return 'Only conversions with consent ad_user_data = GRANTED are sent';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string) ($c['conversionDateTime'] ?? ''))) {
            return 'conversionDateTime must have the format yyyy-mm-dd hh:mm:ss+hh:mm';
        }
        return null;
    }
}
