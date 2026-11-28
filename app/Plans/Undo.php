<?php
declare(strict_types=1);

namespace App\Plans;

use App\Plans\Snapshot\Fp;

/**
 * Undoing plans (prepare_undo; port of src/plans/undo.ts). The builder stores `undo` in the plan: reverse operations computed from the snapshot
 * (old values). References to resources that do not exist yet are ['$result' => i] (index of a plan operation), resolved
 * from the mutate results stored at execution. Rules: never Campaign.status ENABLED, never delete resources (assets),
 * custom segments and conversion actions are not undone.
 * For D2: a new builder finishes the plan with Undo::attach($plan, $snapshot, ...extra objects with resourceName).
 */
final class Undo
{
    public const UNDO_WINDOW_DAYS = 7;
    public const RESUME_MANUALLY = 'You resume the campaign manually in Google Ads';
    public const ACCOUNT_CHANGED = 'The account has changed since this change; undo manually or prepare a new plan';
    public const NO_UNDO_DATA = 'Undo is not available for this plan; it was created before undo existed. Change it manually or prepare a new plan.';
    public const NO_STATE_CHECK = 'The current account state cannot be checked for this plan kind; undo the change manually or prepare a new plan';
    public const KEPT_NOTE = 'Created resources (texts, images, budgets, audiences, lists) stay in the account; unused ones cost nothing';
    private const NOT_UNDOABLE = 'Cannot be undone';
    private const TEMP_RN = '#^customers/\d+/[A-Za-z]+/-\d+$#';
    /** Parent resources: we never delete them. */
    private const PARENTS = ['campaign', 'adGroup', 'assetGroup'];
    /** Fields that, when missing from the snapshot, mean "empty" and may be cleared with a mask without a value. */
    private const CLEARABLE = ['endDateTime', 'path1', 'path2', 'assetAutomationSettings', 'biddable', 'primaryForGoal'];
    /** Created resources that stay (assets are never deleted). */
    private const KEEP = ['asset', 'campaignBudget', 'audience', 'sharedSet'];
    /** Links and criteria: undoing a creation = removing the created link. */
    private const LINKS = ['campaignAsset', 'assetGroupAsset', 'assetGroupSignal', 'campaignCriterion', 'adGroupCriterion', 'adGroupAd',
        'sharedCriterion', 'campaignSharedSet', 'customerNegativeCriterion'];
    private const NOT_UNDOABLE_CREATE = [
        'customAudience' => 'Custom segment creation; segments are not undone (remove it manually in Google Ads if it is not needed)',
        'conversionAction' => 'Conversion action creation; remove it manually in Google Ads if it is not needed',
    ];
    private const ENTITY_LABELS = [
        'campaign' => 'campaign', 'adGroup' => 'ad group', 'assetGroup' => 'asset group', 'campaignCriterion' => 'campaign criterion', 'adGroupCriterion' => 'keyword',
        'adGroupAd' => 'ad', 'assetGroupSignal' => 'group signal', 'sharedCriterion' => 'list negative', 'customerNegativeCriterion' => 'account negative',
        'campaignAsset' => 'asset-to-campaign link', 'assetGroupAsset' => 'asset-to-group link', 'campaignSharedSet' => 'list attachment',
        'campaignBudget' => 'budget', 'conversionAction' => 'conversion action', 'campaignConversionGoal' => 'campaign conversion goal',
        'customerConversionGoal' => 'account conversion goal', 'conversionGoalCampaignConfig' => 'goal level', 'ad' => 'ad',
    ];
    private const BIDDING_ROOTS = ['manualCpc', 'manualCpm', 'manualCpv', 'targetSpend', 'maximizeConversions', 'maximizeConversionValue',
        'targetCpa', 'targetRoas', 'targetImpressionShare', 'percentCpc', 'commission', 'biddingStrategy'];

    /** Attaches undo data computed from the operations and the snapshot to the plan (sources: the snapshot and extra objects with resourceName). */
    public static function attach(array $plan, mixed ...$sources): array
    {
        $plan['undo'] = self::derive(Plan::ops($plan), self::lookupFrom(...$sources));
        return $plan;
    }

    /** @return array{key: string, entity: string, body: array}|null */
    private static function entityOf(mixed $op): ?array
    {
        $keys = is_array($op) ? array_keys($op) : [];
        if (count($keys) !== 1 || !str_ends_with((string) $keys[0], 'Operation')) {
            return null;
        }
        $key = (string) $keys[0];
        return ['key' => $key, 'entity' => substr($key, 0, -strlen('Operation')), 'body' => is_array($op[$key]) ? $op[$key] : []];
    }

    private static function stripVolatile(mixed $v): mixed
    {
        return Fp::clean($v);
    }

    /** @return array{bool, mixed} [found, value] for a dotted path */
    private static function getPath(array $o, string $path): array
    {
        $cur = $o;
        foreach (explode('.', $path) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                return [false, null];
            }
            $cur = $cur[$k];
        }
        return [true, $cur];
    }

    private static function setPath(array &$o, string $path, mixed $v): void
    {
        $ref = &$o;
        foreach (explode('.', $path) as $k) {
            if (!isset($ref[$k]) || !is_array($ref[$k])) {
                $ref[$k] = [];
            }
            $ref = &$ref[$k];
        }
        $ref = $v;
    }

    /** @return array{cid: string, parts: list<string>}|null */
    private static function parseRn(string $rn): ?array
    {
        return preg_match('#^customers/(\d+)/([A-Za-z]+)/(.+)$#', $rn, $m) ? ['cid' => $m[1], 'parts' => explode('~', $m[3])] : null;
    }

    /** Index of objects with a resourceName in arbitrarily nested data (snapshot); later sources complete and overwrite fields. */
    public static function lookupFrom(mixed ...$sources): \Closure
    {
        $idx = [];
        $walk = function (mixed $v) use (&$walk, &$idx): void {
            if (!is_array($v)) {
                return;
            }
            if (isset($v['resourceName']) && is_string($v['resourceName'])) {
                $idx[$v['resourceName']] = array_merge($idx[$v['resourceName']] ?? [], $v);
            }
            foreach ($v as $x) {
                if (is_array($x)) {
                    $walk($x);
                }
            }
        };
        foreach ($sources as $s) {
            $walk($s);
        }
        return fn(string $rn): ?array => $idx[$rn] ?? null;
    }

    private static function pick(mixed $o, array $keys): ?array
    {
        if (!is_array($o)) {
            return null;
        }
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $o)) {
                $out[$k] = Json::clone($o[$k]);
            }
        }
        return $out ?: null;
    }

    /** Criterion content (from the snapshot or the create body), only the fields allowed on creation. */
    private static function criterionContent(array $o): ?array
    {
        $prox = $o['proximity'] ?? null;
        $fields = [
            'keyword' => !empty($o['keyword']['text']) && !empty($o['keyword']['matchType']) ? self::pick($o['keyword'], ['text', 'matchType']) : null,
            'location' => self::pick($o['location'] ?? null, ['geoTargetConstant']),
            'proximity' => is_array($prox) && (isset($prox['geoPoint']) || isset($prox['address'])) && isset($prox['radius']) ? self::pick($prox, ['geoPoint', 'address', 'radius', 'radiusUnits']) : null,
            'placement' => self::pick($o['placement'] ?? null, ['url']),
            'youtubeChannel' => self::pick($o['youtubeChannel'] ?? null, ['channelId']),
            'youtubeVideo' => self::pick($o['youtubeVideo'] ?? null, ['videoId']),
            'mobileApplication' => self::pick($o['mobileApplication'] ?? null, ['appId']),
            'mobileAppCategory' => self::pick($o['mobileAppCategory'] ?? null, ['mobileAppCategoryConstant']),
            'contentLabel' => self::pick($o['contentLabel'] ?? null, ['type']),
            'language' => self::pick($o['language'] ?? null, ['languageConstant']),
            'searchTheme' => self::pick($o['searchTheme'] ?? null, ['text']),
            'audience' => self::pick($o['audience'] ?? null, ['audience']),
            'userList' => self::pick($o['userList'] ?? null, ['userList']),
        ];
        foreach ($fields as $name => $v) {
            if ($v !== null) {
                return [$name => $v];
            }
        }
        return null;
    }

    private static function strFields(?array $o, array $keys): ?array
    {
        if ($o === null) {
            return null;
        }
        foreach ($keys as $k) {
            if (!isset($o[$k]) || !is_string($o[$k])) {
                return null;
            }
        }
        return self::pick($o, $keys);
    }

    /** Create body restoring a removed link/criterion; null = no data. */
    public static function recreateBody(string $entity, string $rn, ?array $o): ?array
    {
        $p = self::parseRn($rn);
        if ($p === null) {
            return null;
        }
        $C = "customers/{$p['cid']}";
        [$a, $b, $c] = array_pad($p['parts'], 3, null);
        // Links: fields from the create body (undo of an undo) or from the resource name (parent~asset~type).
        switch ($entity) {
            case 'campaignAsset':
                return self::strFields($o, ['campaign', 'asset', 'fieldType']) ?? ($a && $b && $c ? ['campaign' => "$C/campaigns/$a", 'asset' => "$C/assets/$b", 'fieldType' => $c] : null);
            case 'assetGroupAsset':
                return self::strFields($o, ['assetGroup', 'asset', 'fieldType']) ?? ($a && $b && $c ? ['assetGroup' => "$C/assetGroups/$a", 'asset' => "$C/assets/$b", 'fieldType' => $c] : null);
            case 'campaignSharedSet':
                return self::strFields($o, ['campaign', 'sharedSet']) ?? ($a && $b ? ['campaign' => "$C/campaigns/$a", 'sharedSet' => "$C/sharedSets/$b"] : null);
        }
        if ($o === null) {
            return null;
        }
        $parent = fn(string $field, string $coll) => is_string($o[$field] ?? null) ? $o[$field] : "$C/$coll/$a";
        $content = self::criterionContent($o);
        $negative = ($o['negative'] ?? null) === true;
        switch ($entity) {
            case 'assetGroupSignal':
                return $content && (isset($content['searchTheme']) || isset($content['audience'])) ? ['assetGroup' => $parent('assetGroup', 'assetGroups')] + $content : null;
            case 'campaignCriterion':
                if (!$content) {
                    return null;
                }
                // A campaign-level keyword is restored only as a negative, when the snapshot says so explicitly.
                if (isset($content['keyword']) && !$negative) {
                    return null;
                }
                return ['campaign' => $parent('campaign', 'campaigns')] + ($negative ? ['negative' => true] : []) + $content;
            case 'adGroupCriterion':
                if (!isset($content['keyword'])) {
                    return null;
                }
                return ['adGroup' => $parent('adGroup', 'adGroups')] + ($negative ? ['negative' => true] : ['status' => ($o['status'] ?? null) === 'PAUSED' ? 'PAUSED' : 'ENABLED']) + $content;
            case 'sharedCriterion':
                return isset($content['keyword']) ? ['sharedSet' => $parent('sharedSet', 'sharedSets')] + $content : null;
            case 'customerNegativeCriterion':
                return $content ?: null;
            case 'adGroupAd':
                return self::recreateAd($o, $parent('adGroup', 'adGroups'));
        }
        return null;
    }

    private static function recreateAd(array $o, string $adGroup): ?array
    {
        $rsa = $o['ad']['responsiveSearchAd'] ?? null;
        if (empty($rsa['headlines']) || empty($rsa['descriptions'])) {
            return null;
        }
        $txt = fn(array $xs) => array_map(fn($x) => ['text' => (string) $x['text']] + (!empty($x['pinnedField']) ? ['pinnedField' => $x['pinnedField']] : []), $xs);
        return ['adGroup' => $adGroup, 'status' => ($o['status'] ?? null) === 'ENABLED' ? 'ENABLED' : 'PAUSED', 'ad' => [
            'finalUrls' => Json::clone($o['ad']['finalUrls'] ?? []),
            'responsiveSearchAd' => ['headlines' => $txt($rsa['headlines']), 'descriptions' => $txt($rsa['descriptions'])]
                + (!empty($rsa['path1']) ? ['path1' => $rsa['path1']] : []) + (!empty($rsa['path2']) ? ['path2' => $rsa['path2']] : []),
        ]];
    }

    /** Restoring the bidding strategy from the snapshot (type) or from the body of an earlier update (the current strategy key). */
    private static function restoreBidding(array $old): ?array
    {
        $t = $old['biddingStrategyType'] ?? null;
        if ($t === 'MANUAL_CPC') {
            return ['fields' => ['manualCpc' => ['enhancedCpcEnabled' => ($old['manualCpc']['enhancedCpcEnabled'] ?? null) === true]], 'mask' => ['manualCpc.enhancedCpcEnabled']];
        }
        if ($t === 'TARGET_SPEND') {
            $ceil = $old['targetSpend']['cpcBidCeilingMicros'] ?? null;
            return $ceil !== null
                ? ['fields' => ['targetSpend' => ['cpcBidCeilingMicros' => $ceil]], 'mask' => ['targetSpend.cpcBidCeilingMicros']]
                : ['fields' => ['targetSpend' => new \stdClass()], 'mask' => ['targetSpend']];
        }
        if ($t === 'MAXIMIZE_CONVERSIONS') {
            return ['fields' => ['maximizeConversions' => new \stdClass()], 'mask' => ['maximizeConversions']];
        }
        if ($t !== null) {
            return null;
        }
        $root = null;
        foreach (array_keys($old) as $k) {
            if (in_array($k, self::BIDDING_ROOTS, true)) {
                $root = $k;
                break;
            }
        }
        if ($root === null) {
            return null;
        }
        $v = $old[$root];
        $sub = is_array($v) ? array_keys($v) : [];
        return ['fields' => [$root => Json::clone($v)], 'mask' => $sub ? array_map(fn($k) => "$root.$k", $sub) : [$root]];
    }

    private static function label(string $entity, string $rn): string
    {
        $parts = explode('/', $rn);
        return (self::ENTITY_LABELS[$entity] ?? $entity) . ' ' . end($parts);
    }

    /** References to temporary names (-N) in an operation body (other than its own). */
    private static function tempRefs(mixed $v, ?string $own): array
    {
        $out = [];
        $walk = function (mixed $x) use (&$walk, &$out, $own): void {
            if (is_string($x)) {
                if (preg_match(self::TEMP_RN, $x) && $x !== $own) {
                    $out[] = $x;
                }
            } elseif (is_array($x)) {
                array_map($walk, $x);
            }
        };
        $walk($v);
        return $out;
    }

    /**
     * Reverse operations of $ops (in reverse order), computed from the snapshot ($lookup: old values by resourceName).
     * @return array{ops: list<array>, notUndoable: list<string>, notes: list<string>}
     */
    public static function derive(array $ops, \Closure $lookup): array
    {
        $notUndoable = [];
        $notes = [];
        $inverse = array_fill(0, count($ops), []);
        $covered = [];
        foreach (array_values($ops) as $i => $op) {
            $e = self::entityOf($op);
            if ($e === null) {
                $notUndoable[] = 'Unknown operation';
                continue;
            }
            ['key' => $key, 'entity' => $entity, 'body' => $body] = $e;
            if (!empty($body['create'])) {
                $inverse[$i] = self::undoCreate($key, $entity, $body['create'], $i, $covered, $notUndoable, $notes);
            } elseif (isset($body['remove'])) {
                $inverse[$i] = self::undoRemove($key, $entity, (string) $body['remove'], $lookup, $notUndoable);
            } elseif (isset($body['update'])) {
                $inverse[$i] = self::undoUpdate($key, $entity, $body, $lookup, $notUndoable, $notes);
            } else {
                $notUndoable[] = "Unknown operation: $key";
            }
        }
        $out = array_merge(...array_reverse($inverse));
        // Safety belt: an undo never enables a campaign.
        $safe = array_values(array_filter($out, fn($op) => !Guard::assertNoEnable([$op])));
        if (count($safe) !== count($out)) {
            $notUndoable[] = 'Campaign status: ' . self::RESUME_MANUALLY;
        }
        // Restored final URLs only from allowed domains.
        $safe = array_values(array_filter($safe, function ($op) use (&$notUndoable) {
            foreach (self::finalUrlsIn($op) as $u) {
                $bad = Guard::checkUrlHost($u);
                if ($bad !== null) {
                    $notUndoable[] = "Final URL to restore is outside the allowed domains; restore manually ($bad)";
                    return false;
                }
            }
            return true;
        }));
        return ['ops' => $safe, 'notUndoable' => $notUndoable, 'notes' => array_values(array_unique($notes))];
    }

    private static function undoCreate(string $key, string $entity, array $create, int $i, array &$covered, array &$notUndoable, array &$notes): array
    {
        $own = is_string($create['resourceName'] ?? null) && preg_match(self::TEMP_RN, $create['resourceName']) ? $create['resourceName'] : null;
        foreach (self::tempRefs($create, $own) as $ref) {
            if (isset($covered[$ref])) { // child of a resource created in the same plan; skipped
                if ($own) {
                    $covered[$own] = true;
                }
                return [];
            }
        }
        if (in_array($entity, self::PARENTS, true)) {
            if ($own) {
                $covered[$own] = true;
            }
            $name = !empty($create['name']) ? " „{$create['name']}”" : '';
            if ($entity === 'adGroup') {
                $notes[] = "The created ad group$name will be paused, not removed; remove it manually in Google Ads if it is not needed";
                return [[$key => ['update' => ['resourceName' => ['$result' => $i], 'status' => 'PAUSED'], 'updateMask' => 'status']]];
            }
            $notUndoable[] = 'Created ' . ($entity === 'campaign' ? 'campaign' : 'asset group') . "$name cannot be undone; remove it manually in Google Ads";
            return [];
        }
        if (in_array($entity, self::KEEP, true)) {
            $notes[] = self::KEPT_NOTE;
            return [];
        }
        if (isset(self::NOT_UNDOABLE_CREATE[$entity])) {
            $notUndoable[] = self::NOT_UNDOABLE_CREATE[$entity];
            return [];
        }
        if (in_array($entity, self::LINKS, true)) {
            return [[$key => ['remove' => ['$result' => $i]]]];
        }
        $notUndoable[] = 'Creation: ' . (self::ENTITY_LABELS[$entity] ?? $entity);
        return [];
    }

    private static function undoRemove(string $key, string $entity, string $rn, \Closure $lookup, array &$notUndoable): array
    {
        if (in_array($entity, self::PARENTS, true)) {
            $notUndoable[] = 'Removed ' . (self::ENTITY_LABELS[$entity] ?? $entity) . ' cannot be restored';
            return [];
        }
        $c = self::recreateBody($entity, $rn, $lookup($rn));
        if ($c === null) {
            $notUndoable[] = 'Removed: ' . self::label($entity, $rn) . ' (no data to restore it)';
            return [];
        }
        return [[$key => ['create' => $c]]];
    }

    private static function undoUpdate(string $key, string $entity, array $body, \Closure $lookup, array &$notUndoable, array &$notes): array
    {
        $update = $body['update'];
        $rn = (string) ($update['resourceName'] ?? '');
        $old = $lookup($rn);
        if ($old === null) {
            $notUndoable[] = 'Change: ' . self::label($entity, $rn) . ' (no old values)';
            return [];
        }
        $paths = array_values(array_filter(array_map('trim', explode(',', (string) ($body['updateMask'] ?? '')))));
        if (!$paths) {
            $paths = array_values(array_filter(array_keys($update), fn($k) => $k !== 'resourceName'));
        }
        $upd = ['resourceName' => $rn];
        $mask = [];
        if ($entity === 'campaign') {
            self::undoCampaignFields($old, $paths, $upd, $mask, $notUndoable, $notes);
        }
        if ($entity === 'campaignBudget' && in_array('amountMicros', $paths, true)) {
            $paths = array_values(array_diff($paths, ['amountMicros']));
            $v = isset($old['amountMicros']) ? (float) $old['amountMicros'] / 1e6 : NAN;
            $max = Cfg::maxDailyBudgetPln();
            if (is_nan($v) || $v > $max) {
                $notUndoable[] = !is_nan($v) ? "Budget PLN $v/day exceeds the limit of PLN $max; restore it manually in Google Ads" : 'Budget (no old value)';
            } else {
                $upd['amountMicros'] = (string) $old['amountMicros'];
                $mask[] = 'amountMicros';
            }
        }
        foreach ($paths as $p) {
            [$found, $v] = self::getPath($old, $p);
            if ($found) {
                self::setPath($upd, $p, self::stripVolatile(Json::clone($v)));
            } elseif (!in_array($p, self::CLEARABLE, true)) {
                // A missing value clears the field with a mask without a value; only for fields we know the snapshot selects.
                $notUndoable[] = 'Change: ' . self::label($entity, $rn) . " (no old values: $p)";
                continue;
            }
            $mask[] = $p;
        }
        return $mask ? [[$key => ['update' => $upd, 'updateMask' => implode(',', $mask)]]] : [];
    }

    /** Campaign status and bidding strategy: separate rules (we never resume, the strategy is restored from its type). */
    private static function undoCampaignFields(array $old, array &$paths, array &$upd, array &$mask, array &$notUndoable, array &$notes): void
    {
        if (in_array('status', $paths, true)) {
            $paths = array_values(array_diff($paths, ['status']));
            $st = $old['status'] ?? null;
            if ($st === 'ENABLED' || $st === null) {
                $notUndoable[] = 'Campaign status (was: ' . ($st === 'ENABLED' ? 'enabled' : 'unknown') . '): ' . self::RESUME_MANUALLY;
            } elseif ($st === 'PAUSED') {
                $upd['status'] = 'PAUSED';
                $mask[] = 'status';
            }
        }
        $isBidding = fn($p) => in_array(explode('.', $p)[0], self::BIDDING_ROOTS, true);
        if (array_filter($paths, $isBidding)) {
            $paths = array_values(array_filter($paths, fn($p) => !$isBidding($p)));
            $b = self::restoreBidding($old);
            if ($b === null) {
                $notUndoable[] = 'Bidding strategy (was: ' . ($old['biddingStrategyType'] ?? 'unknown') . '); restore it manually in Google Ads';
                return;
            }
            $upd = array_merge($upd, $b['fields']);
            array_push($mask, ...$b['mask']);
            if (($old['biddingStrategyType'] ?? null) === 'MAXIMIZE_CONVERSIONS') {
                $notes[] = 'Maximize conversions returns without a target CPA; if one was set, set it again in Google Ads';
            }
        }
    }

    /** All URLs from the finalUrls fields of an operation. */
    private static function finalUrlsIn(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $k => $x) {
            if ($k === 'finalUrls' && is_array($x)) {
                array_push($out, ...array_map('strval', $x));
            } else {
                array_push($out, ...self::finalUrlsIn($x));
            }
        }
        return $out;
    }

    /** resourceName from one mutate result (mutateOperationResponses or results). */
    public static function resultRn(mixed $r): ?string
    {
        if (!is_array($r)) {
            return null;
        }
        if (isset($r['resourceName']) && is_string($r['resourceName'])) {
            return $r['resourceName'];
        }
        foreach ($r as $k => $v) {
            if (str_ends_with((string) $k, 'Result') && is_array($v) && is_string($v['resourceName'] ?? null)) {
                return $v['resourceName'];
            }
        }
        return null;
    }

    public static function planResults(array $p): array
    {
        return is_array($p['result']['results'] ?? null) ? $p['result']['results'] : [];
    }

    /** Substitutes ['$result' => i] and temporary resourceNames (-N) with the real names from the execution results. @throws \DomainException when a result is missing */
    private static function resolve(mixed $v, array $results, array $temps): mixed
    {
        if (is_string($v) && isset($temps[$v])) {
            return self::resultRn($results[$temps[$v]] ?? null) ?? throw new \DomainException('unresolved');
        }
        if (!is_array($v)) {
            return $v;
        }
        if (count($v) === 1 && isset($v['$result']) && is_int($v['$result'])) {
            return self::resultRn($results[$v['$result']] ?? null) ?? throw new \DomainException('unresolved');
        }
        return array_map(fn($x) => self::resolve($x, $results, $temps), $v);
    }

    /** @return array<string,int> temporary name -> index of the operation that creates it */
    private static function tempMap(array $ops): array
    {
        $m = [];
        foreach (array_values($ops) as $i => $op) {
            $rn = self::entityOf($op)['body']['create']['resourceName'] ?? null;
            if (is_string($rn) && preg_match(self::TEMP_RN, $rn)) {
                $m[$rn] = $i;
            }
        }
        return $m;
    }

    /** Fingerprint scope compared on undo (after execution): new campaign/group -> real IDs from the results. */
    public static function target(array $p): array
    {
        $ops = Plan::ops($p);
        $results = self::planResults($p);
        $idOf = function (string $entity) use ($ops, $results): ?string {
            foreach (array_values($ops) as $i => $op) {
                if (!empty($op["{$entity}Operation"]['create'])) {
                    $rn = self::resultRn($results[$i] ?? null);
                    return $rn ? basename($rn) : null;
                }
            }
            return null;
        };
        if ($p['kind'] === 'new_campaign') {
            return array_filter(['kind' => 'campaign_changes', 'campaignId' => $idOf('campaign')], fn($x) => $x !== null);
        }
        $t = ['kind' => $p['kind']];
        foreach (['campaignId', 'goalScope', 'channel'] as $k) {
            if (!empty($p[$k])) {
                $t[$k] = $p[$k];
            }
        }
        if ($p['kind'] === 'asset_group_changes') {
            $ag = $p['assetGroupId'] ?? $idOf('assetGroup');
            return $ag ? $t + ['assetGroupId' => $ag] : $t;
        }
        return !empty($p['assetGroupId']) ? $t + ['assetGroupId' => $p['assetGroupId']] : $t;
    }

    /** "After the plan" state objects (create bodies with real names and update bodies); the snapshot for undoing an undo. */
    private static function afterState(array $p): array
    {
        $ops = Plan::ops($p);
        $results = self::planResults($p);
        $temps = self::tempMap($ops);
        $out = [];
        foreach (array_values($ops) as $i => $op) {
            $e = self::entityOf($op);
            if ($e === null) {
                continue;
            }
            try {
                if (!empty($e['body']['create'])) {
                    $rn = self::resultRn($results[$i] ?? null);
                    if ($rn) {
                        $out[] = ['resourceName' => $rn] + self::resolve($e['body']['create'], $results, $temps);
                    }
                } elseif (!empty($e['body']['update'])) {
                    $out[] = Json::clone($e['body']['update']);
                }
            } catch (\DomainException) {
                // no result; skipped
            }
        }
        return $out;
    }

    /** Is the plan executed and undoable? A refusal message or null. */
    public static function refusal(array $p, ?\DateTimeImmutable $now = null): ?string
    {
        $now ??= Clock::now();
        if ($p['kind'] === 'selftest') {
            return 'A test plan changes nothing; there is nothing to undo';
        }
        if ($p['status'] !== 'done') {
            return 'This plan was not executed; there is nothing to undo';
        }
        if (!empty($p['undoneBy'])) {
            return "This plan has already been undone (undo plan {$p['undoneBy']}); I will not undo it a second time";
        }
        if (empty($p['undo']) || empty($p['executedAt'])) {
            return self::NO_UNDO_DATA;
        }
        if ($now->getTimestamp() - strtotime($p['executedAt']) > self::UNDO_WINDOW_DAYS * 86400) {
            return 'Only plans executed within the last ' . self::UNDO_WINDOW_DAYS . ' days can be undone; this one was executed on ' . substr($p['executedAt'], 0, 10) . '. Change it manually or prepare a new plan.';
        }
        return null;
    }

    /** Reversed preview: Before <-> After, add <-> remove; info rows and images from the original plan's preview are skipped. */
    private static function invertDiff(array $diff, string $planImgPrefix): array
    {
        $local = fn($u) => !empty($u) && str_starts_with((string) $u, $planImgPrefix);
        $out = [];
        foreach ($diff as $d) {
            if ($d['change'] === 'info') {
                continue;
            }
            $before = $d['beforeImage'] ?? null;
            $after = $d['afterImage'] ?? null;
            unset($d['beforeImage'], $d['afterImage']);
            $change = $d['change'] === 'add' ? 'remove' : ($d['change'] === 'remove' ? 'add' : $d['change']);
            $row = array_merge($d, ['before' => $d['after'], 'after' => $d['before'], 'change' => $change]);
            if ($after && !$local($after)) {
                $row['beforeImage'] = $after;
            }
            if ($before && !$local($before)) {
                $row['afterImage'] = $before;
            }
            if (empty($row['beforeImage']) && empty($row['afterImage'])) {
                unset($row['imageAlt']);
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Undo plan for plan $p (executed). $currentFingerprint: fingerprint of the plan's scope now (must equal the one stored after execution).
     * @return array{plan: array, notUndoable: list<string>}|array{message: string, notUndoable: list<string>, refused: bool}
     */
    public static function buildPlan(array $p, ?string $currentFingerprint): array
    {
        $refusal = self::refusal($p);
        if ($refusal !== null) {
            return ['message' => $refusal, 'notUndoable' => [], 'refused' => true];
        }
        $undo = $p['undo'];
        $ops = Plan::ops($p);
        $results = self::planResults($p);
        $temps = self::tempMap($ops);
        $notUndoable = $undo['notUndoable'];
        $resolved = [];
        foreach ($undo['ops'] as $op) {
            try {
                $resolved[] = self::resolve($op, $results, $temps);
            } catch (\DomainException) {
                $e = self::entityOf($op);
                $notUndoable[] = (self::ENTITY_LABELS[$e['entity'] ?? ''] ?? ($e['entity'] ?? 'operation')) . ' (no execution result: ID of the created resource)';
            }
        }
        if (!$resolved) {
            return ['message' => 'This plan cannot be undone automatically; there are no operations to reverse.' . ($notUndoable ? ' Cannot be undone: ' . implode('; ', $notUndoable) : ''), 'notUndoable' => $notUndoable, 'refused' => false];
        }
        if (empty($p['afterFingerprint'])) {
            return ['message' => 'The account state after executing this plan was not saved; undo the change manually or prepare a new plan', 'notUndoable' => $notUndoable, 'refused' => true];
        }
        if ($currentFingerprint === null) {
            return ['message' => self::NO_STATE_CHECK, 'notUndoable' => $notUndoable, 'refused' => true];
        }
        if ($currentFingerprint !== $p['afterFingerprint']) {
            return ['message' => self::ACCOUNT_CHANGED, 'notUndoable' => $notUndoable, 'refused' => true];
        }
        $errors = Guard::assertNoEnable($resolved);
        $diff = self::invertDiff($p['diff'], Plan::imagePrefix($p['id']));
        foreach ($notUndoable as $n) {
            $diff[] = ['section' => self::NOT_UNDOABLE, 'label' => $n, 'before' => null, 'after' => null, 'change' => 'info', 'level' => 'danger'];
        }
        $plan = array_merge(Plan::base(), self::target($p), [
            'summary' => "Undo: {$p['summary']}" . ($notUndoable ? ' (cannot be undone: ' . implode('; ', $notUndoable) . ')' : ''),
            'diff' => $diff,
            'steps' => [['title' => 'Undoing changes', 'operations' => $resolved]],
            'warnings' => [...array_map(fn($n) => self::NOT_UNDOABLE . ": $n", $notUndoable), ...$undo['notes']],
            'validation' => ['ok' => !$errors, 'errors' => $errors],
            'beforeFingerprint' => $currentFingerprint,
            'undoOf' => $p['id'],
        ], !empty($p['campaignState']) ? ['campaignState' => $p['campaignState']] : []);
        // Undo of an undo: old values = the state after the original plan.
        $plan['undo'] = self::derive($resolved, self::lookupFrom(self::afterState($p)));
        return ['plan' => $plan, 'notUndoable' => $notUndoable];
    }

    /** Last executed plan (the default to undo): status done, not selftest, not yet undone; newest by execution time. */
    public static function latestExecuted(array $plans): ?array
    {
        $at = fn($p) => $p['executedAt'] ?? $p['createdAt'];
        $c = array_values(array_filter($plans, fn($p) => $p['status'] === 'done' && $p['kind'] !== 'selftest' && empty($p['undoneBy'])));
        usort($c, fn($a, $b) => strcmp($at($b), $at($a)));
        return $c[0] ?? null;
    }
}
