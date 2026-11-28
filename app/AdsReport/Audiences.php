<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Google\Ads;

/** C8: campaign and ad group audiences with metrics; for PMax a list of asset group signals (no metrics). */
final class Audiences
{
    private const TYPE_NAMES = [
        'UNKNOWN' => 'unknown type', 'USER_LIST' => 'audience list', 'USER_INTEREST' => 'interests', 'CUSTOM_AFFINITY' => 'custom segment',
        'CUSTOM_INTENT' => 'custom segment', 'CUSTOM_AUDIENCE' => 'custom segment', 'COMBINED_AUDIENCE' => 'combined segment', 'AUDIENCE' => 'audience',
    ];
    private const PMAX_NOTE = 'PMax campaigns return no campaign_audience_view or ad_group_audience_view rows: asset group audience signals (asset_group_signal) are only a hint for the algorithm and have no metrics.';

    public static function typeName(mixed $t): string
    {
        return self::TYPE_NAMES[(string) $t] ?? (string) ($t ?? '?');
    }

    /** Map id -> name from a separate query; a query error = empty map (names are optional). */
    private static function safeNames(string $gaql, string $idPath, string $namePath): array
    {
        try {
            $names = [];
            foreach (Ads::search($gaql) as $r) {
                $id = Fmt::get($r, $idPath);
                $name = Fmt::get($r, $namePath);
                if ($id !== null && $name) {
                    $names[(string) $id] = (string) $name;
                }
            }
            return $names;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Audience name: display_name from the API, otherwise resolved by ID from the matching list, otherwise type + ID. @return array{name:string,ref:string} */
    public static function audienceLabel(array $crit, array $look): array
    {
        $pairs = [
            ['USER_LIST', Fmt::get($crit, 'userList.userList'), 'userList'],
            ['USER_INTEREST', Fmt::get($crit, 'userInterest.userInterestCategory'), 'userInterest'],
            ['CUSTOM_AUDIENCE', Fmt::get($crit, 'customAudience.customAudience'), 'custom'],
            ['COMBINED_AUDIENCE', Fmt::get($crit, 'combinedAudience.combinedAudience'), 'combined'],
            ['AUDIENCE', Fmt::get($crit, 'audience.audience'), 'audience'],
        ];
        $hit = null;
        foreach ($pairs as $p) {
            if ($p[1]) {
                $hit = $p;
                break;
            }
        }
        $id = $hit ? Fmt::lastId($hit[1]) : (string) ($crit['criterionId'] ?? '');
        $type = $hit ? $hit[0] : (string) ($crit['type'] ?? '');
        $resolved = $hit ? ($look[$hit[2]][$id] ?? null) : null;
        $name = ($crit['displayName'] ?? '') ?: ($resolved ?: trim(self::typeName($type) . " $id"));
        return ['name' => (string) $name, 'ref' => "$type:$id"];
    }

    /** Mode: observation (bidOnly) or targeting, from the ad group settings (target_restrictions for the AUDIENCE dimension). */
    public static function audienceMode(mixed $adGroup): ?string
    {
        foreach (Fmt::list(Fmt::get($adGroup, 'targetingSetting.targetRestrictions')) as $t) {
            if (($t['targetingDimension'] ?? null) === 'AUDIENCE') {
                return !empty($t['bidOnly']) ? 'observation' : 'targeting';
            }
        }
        return null;
    }

    public static function view(array $a): array
    {
        $cr = Ads::search(Queries::audiences('campaign', $a['campaignId'], $a['b']));
        $ar = Ads::search(Queries::audiences('ad_group', $a['campaignId'], $a['b']));
        try {
            $sig = Ads::search(Queries::assetGroupSignals($a['campaignId']));
        } catch (\Throwable) {
            $sig = [];
        }
        $q = Queries::AUDIENCE_NAME_QUERIES;
        $au = self::safeNames($q['audience'], 'audience.id', 'audience.name');
        $look = ['userList' => self::safeNames($q['userList'], 'userList.id', 'userList.name'), 'userInterest' => [],
            'custom' => self::safeNames($q['customAudience'], 'customAudience.id', 'customAudience.name'),
            'combined' => self::safeNames($q['combinedAudience'], 'combinedAudience.id', 'combinedAudience.name'), 'audience' => $au];

        $agg = [];
        foreach ($cr as $r) {
            self::add($agg, 'campaign', $r, $look);
        }
        foreach ($ar as $r) {
            self::add($agg, 'ad group', $r, $look);
        }
        $items = array_values($agg);
        usort($items, fn($x, $y) => ($y['cost'] <=> $x['cost']) ?: ($y['clicks'] <=> $x['clicks']));
        $rows = array_map(fn($x) => [
            'level' => $x['level'], 'campaignId' => $x['campaignId'], 'campaign' => $x['campaign'], 'adGroup' => $x['group'], 'audience' => $x['name'], 'type' => $x['type'], 'mode' => $x['mode'],
            'excluded' => $x['negative'], 'bidModifier' => $x['bid'], 'clicks' => $x['clicks'], 'impressions' => $x['impressions'],
            'ctrPct' => $x['impressions'] ? Fmt::r2($x['clicks'] / $x['impressions'] * 100) : null, 'cost' => Fmt::r2($x['cost']), 'conversions' => Fmt::r2($x['conv']),
            'costPerConversion' => $x['conv'] > 0 ? Fmt::r2($x['cost'] / $x['conv']) : null,
        ], $items);

        $signals = array_map(function ($r) use ($au) {
            $aud = Fmt::get($r, 'assetGroupSignal.audience.audience');
            $theme = Fmt::get($r, 'assetGroupSignal.searchTheme.text');
            return ['campaignId' => (string) Fmt::get($r, 'campaign.id', ''), 'campaign' => Fmt::get($r, 'campaign.name', ''), 'assetGroup' => Fmt::get($r, 'assetGroup.name', ''),
                'signal' => $aud ? 'audience' : ($theme ? 'search theme' : 'other'),
                'value' => $aud ? ($au[Fmt::lastId($aud)] ?? 'audience ' . Fmt::lastId($aud)) : ($theme ?? ''),
                'approval' => Fmt::get($r, 'assetGroupSignal.approvalStatus')];
        }, $sig);
        $sigText = $signals
            ? 'PMax asset group signals (no metrics, informational only): ' . implode('; ', array_map(fn($s) => "{$s['assetGroup']}: {$s['signal']} '{$s['value']}'", $signals)) . '.'
            : '';
        $range = Fmt::rangeLabel($a['b']);
        $scope = $a['campaignId'] ? "campaign {$a['campaignId']}" : 'all campaigns';
        return [
            'header' => "Audiences ($scope, $range): rows " . count($rows) . ', PMax signals ' . count($signals) . '.',
            'rows' => $rows, 'extra' => ['range' => $range, 'signals' => $signals],
            'empty' => "No audiences with metrics in the period $range ($scope). " . self::PMAX_NOTE . ($sigText ? " $sigText" : ' There are no asset group signals either.'),
            'footer' => 'mode: observation = bid adjustment only (bidOnly), targeting = restricts reach; null = not applicable or not set. excluded = audience is excluded. ' . self::PMAX_NOTE . ($sigText ? " $sigText" : ''),
        ];
    }

    /** Adds a row to the aggregate; the same audience in the same place (key = level, campaign, ad group, audience) sums the metrics. */
    private static function add(array &$agg, string $level, array $r, array $look): void
    {
        $crit = $level === 'campaign' ? ($r['campaignCriterion'] ?? []) : ($r['adGroupCriterion'] ?? []);
        $lab = self::audienceLabel($crit, $look);
        $key = implode('|', [$level, Fmt::get($r, 'campaign.id'), Fmt::get($r, 'adGroup.id', ''), $lab['ref'], $crit['criterionId'] ?? '']);
        $m = $r['metrics'] ?? [];
        $agg[$key] ??= [
            'level' => $level, 'campaignId' => (string) Fmt::get($r, 'campaign.id', ''), 'campaign' => Fmt::get($r, 'campaign.name', ''),
            'group' => $level === 'ad group' ? Fmt::get($r, 'adGroup.name', '') : null,
            'name' => $lab['name'], 'type' => self::typeName($crit['type'] ?? explode(':', $lab['ref'])[0]), 'mode' => $level === 'ad group' ? self::audienceMode($r['adGroup'] ?? null) : null,
            'negative' => ($crit['negative'] ?? null) === true, 'bid' => isset($crit['bidModifier']) ? Fmt::num($crit['bidModifier']) : null,
            'clicks' => 0, 'impressions' => 0, 'cost' => 0, 'conv' => 0,
        ];
        $agg[$key]['clicks'] += Fmt::num($m['clicks'] ?? null);
        $agg[$key]['impressions'] += Fmt::num($m['impressions'] ?? null);
        $agg[$key]['cost'] += Fmt::num($m['costMicros'] ?? null) / 1e6;
        $agg[$key]['conv'] += Fmt::num($m['conversions'] ?? null);
    }
}
