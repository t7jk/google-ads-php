<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Google\Ads;
use App\Plans\{Json, Plan, PlanQueries, Text, Undo};

/** Account-level exclusions (CustomerNegativeCriterion; port of build-exclusions.ts): placements, YouTube channels and videos, apps, app categories, content labels. */
final class Exclusions
{
    /** Content labels accepted by API v26 (ContentLabelType), without UNKNOWN/UNSPECIFIED. */
    public const CONTENT_LABELS = [
        'SEXUALLY_SUGGESTIVE', 'BELOW_THE_FOLD', 'PARKED_DOMAIN', 'JUVENILE', 'PROFANITY', 'TRAGEDY', 'VIDEO', 'VIDEO_RATING_DV_G', 'VIDEO_RATING_DV_PG',
        'VIDEO_RATING_DV_T', 'VIDEO_RATING_DV_MA', 'VIDEO_NOT_YET_RATED', 'EMBEDDED_VIDEO', 'LIVE_STREAMING_VIDEO', 'SOCIAL_ISSUES',
        'BRAND_SUITABILITY_CONTENT_FOR_FAMILIES', 'BRAND_SUITABILITY_GAMES_FIGHTING', 'BRAND_SUITABILITY_GAMES_MATURE', 'BRAND_SUITABILITY_HEALTH_SENSITIVE',
        'BRAND_SUITABILITY_HEALTH_SOURCE_UNDETERMINED', 'BRAND_SUITABILITY_NEWS_RECENT', 'BRAND_SUITABILITY_NEWS_SENSITIVE',
        'BRAND_SUITABILITY_NEWS_SOURCE_NOT_FEATURED', 'BRAND_SUITABILITY_POLITICS', 'BRAND_SUITABILITY_RELIGION',
    ];
    /** Presets. App category IDs are mobile_app_category_constant (Google Play 60008 Games, 60040/60056 Family; App Store 60506 Games, 60530 Family, 60531 Kids). */
    private const PRESETS = [
        'kids_games_and_apps' => ['mobileAppCategories' => ['60008', '60506', '60040', '60056', '60530', '60531'], 'contentLabels' => []],
        'sensitive_content' => ['mobileAppCategories' => [], 'contentLabels' => ['SEXUALLY_SUGGESTIVE', 'PROFANITY', 'TRAGEDY', 'SOCIAL_ISSUES']],
    ];
    private const KIND_LABEL = ['contentLabel' => 'Content label', 'mobileApp' => 'App', 'mobileAppCategory' => 'App category', 'placement' => 'Placement', 'youtubeChannel' => 'YouTube channel', 'youtubeVideo' => 'YouTube video'];
    // Placement domain after normalization (no scheme, "www." or trailing "/"): letters/digits/hyphens, at least one dot, no leading hyphen.
    private const DOMAIN = '/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/';

    // Loads all account exclusions (PlanQueries::EXCLUSIONS); the map of present criteria and the fingerprint are built from this input.
    public static function load(): array
    {
        return Ads::search(PlanQueries::EXCLUSIONS);
    }

    /** Fingerprint of the account exclusion list (all criteria, including unsupported ones, which enter as resourceName). */
    public static function fingerprint(array $rows): string
    {
        $rns = array_map(fn($r) => (string) ($r['customerNegativeCriterion']['resourceName'] ?? ''), $rows);
        sort($rns, SORT_STRING);
        return Json::fingerprint(['exclusions' => $rns]);
    }

    /** @return array{key: string, kind: string, value: string, label: string} */
    // Exclusion identity = the "kind:value" key, used for comparisons (already exists, add and remove at once) without depending on resourceName.
    private static function mk(string $kind, string $value, ?string $name = null): array
    {
        return ['key' => "$kind:$value", 'kind' => $kind, 'value' => $value, 'label' => $name ? "$value ($name)" : $value];
    }

    /** Criterion key from a GAQL row (null for types the tool does not handle, e.g. lists and IP addresses). */
    private static function keyOfRow(array $r): ?array
    {
        $c = $r['customerNegativeCriterion'] ?? [];
        if (!empty($c['contentLabel']['type'])) {
            return self::mk('contentLabel', (string) $c['contentLabel']['type']);
        }
        if (!empty($c['mobileApplication']['appId'])) {
            return self::mk('mobileApp', (string) $c['mobileApplication']['appId'], $c['mobileApplication']['name'] ?? null);
        }
        if (!empty($c['mobileAppCategory']['mobileAppCategoryConstant'])) {
            return self::mk('mobileAppCategory', basename((string) $c['mobileAppCategory']['mobileAppCategoryConstant']));
        }
        if (!empty($c['placement']['url'])) {
            $raw = (string) $c['placement']['url'];
            return ['label' => $raw] + self::mk('placement', self::normDomain($raw));
        }
        if (!empty($c['youtubeChannel']['channelId'])) {
            return self::mk('youtubeChannel', (string) $c['youtubeChannel']['channelId']);
        }
        if (!empty($c['youtubeVideo']['videoId'])) {
            return self::mk('youtubeVideo', (string) $c['youtubeVideo']['videoId']);
        }
        return null;
    }

    // Normalization must be identical for values from the request and from the account, otherwise "www.Example.com/" and "example.com" would be treated as different placements.
    private static function normDomain(string $t): string
    {
        $d = mb_strtolower(Text::norm($t));
        $d = (string) preg_replace('#^https?://#', '', $d);
        $d = (string) preg_replace('#^www\.#', '', $d);
        return (string) preg_replace('#/+$#', '', $d);
    }

    /** Expands a group (add/remove) into keys; format errors go to $d. */
    // ID formats (validated before sending to the API, because one bad ID gets the whole batch rejected): YouTube channel = "UC" + 22 characters, video = 11 characters,
    // app = "1-<iOS id>" or "2-<Android package>", app category = the number of a mobileAppCategoryConstants constant.
    private static function expand(?array $g, Draft $d, string $what): array
    {
        $out = [];
        if (!$g) {
            return $out;
        }
        foreach ($g['placements'] ?? [] as $t) {
            $v = self::normDomain((string) $t);
            preg_match(self::DOMAIN, $v) ? $out[] = self::mk('placement', $v) : $d->error("$what: \"{$t}\" does not look like a domain (e.g. example.com)");
        }
        foreach ($g['youtubeChannels'] ?? [] as $t) {
            $v = Text::norm((string) $t);
            preg_match('/^UC[\w-]{22}$/', $v) ? $out[] = self::mk('youtubeChannel', $v) : $d->error("$what: \"{$t}\" is not a YouTube channel ID (UC + 22 characters)");
        }
        foreach ($g['youtubeVideos'] ?? [] as $t) {
            $v = Text::norm((string) $t);
            preg_match('/^[\w-]{11}$/', $v) ? $out[] = self::mk('youtubeVideo', $v) : $d->error("$what: \"{$t}\" is not a YouTube video ID (11 characters)");
        }
        foreach ($g['mobileApps'] ?? [] as $t) {
            $v = Text::norm((string) $t);
            preg_match('/^[12]-\S+$/', $v) ? $out[] = self::mk('mobileApp', $v) : $d->error("$what: \"{$t}\": an app ID must look like 1-<number> (iOS) or 2-<package> (Android)");
        }
        foreach ($g['mobileAppCategories'] ?? [] as $t) {
            $v = Text::norm((string) $t);
            preg_match('/^\d+$/', $v) ? $out[] = self::mk('mobileAppCategory', $v) : $d->error("$what: \"{$t}\" is not an app category ID (digits only)");
        }
        foreach ($g['contentLabels'] ?? [] as $l) {
            $out[] = self::mk('contentLabel', (string) $l);
        }
        return $out;
    }

    // Removes duplicates by key (e.g. the same category from a preset and from the add list), keeping the order.
    private static function dedupe(array $xs): array
    {
        $seen = [];
        $out = [];
        foreach ($xs as $k) {
            if (!isset($seen[$k['key']])) {
                $seen[$k['key']] = true;
                $out[] = $k;
            }
        }
        return $out;
    }

    /**
     * Builds the plan for the prepare_account_exclusions tool. Account exclusions (customerNegativeCriterion) apply to ALL campaigns in the account.
     * Order: removals, then additions; items that already exist are skipped (warn); removing a non-existent one and an add+remove pair are errors (fail-closed).
     * Presets (PRESETS) are appended to add. Unsupported criterion types in the account (lists, IPs) are ignored in comparisons but enter the fingerprint.
     */
    public static function build(array $req, array $rows): array
    {
        $d = new Draft();
        $present = [];
        foreach ($rows as $r) {
            $k = self::keyOfRow($r);
            if ($k) {
                $present[$k['key']] = ['k' => $k, 'rn' => (string) $r['customerNegativeCriterion']['resourceName']];
            }
        }
        $presetKeys = [];
        foreach ($req['presets'] ?? [] as $p) {
            foreach (self::PRESETS[$p]['mobileAppCategories'] as $v) {
                $presetKeys[] = self::mk('mobileAppCategory', $v);
            }
            foreach (self::PRESETS[$p]['contentLabels'] as $v) {
                $presetKeys[] = self::mk('contentLabel', $v);
            }
        }
        $adds = self::dedupe([...self::expand($req['add'] ?? null, $d, 'add'), ...$presetKeys]);
        $removes = self::dedupe(self::expand($req['remove'] ?? null, $d, 'remove'));
        foreach ($adds as $a) {
            if (array_filter($removes, fn($r) => $r['key'] === $a['key'])) {
                $d->error(self::KIND_LABEL[$a['kind']] . " \"{$a['label']}\" is in both add and remove");
            }
        }
        foreach ($removes as $r) {
            if (!isset($present[$r['key']])) {
                $d->error('No such exclusion: ' . self::KIND_LABEL[$r['kind']] . " \"{$r['label']}\"");
                continue;
            }
            $d->op(['customerNegativeCriterionOperation' => ['remove' => $present[$r['key']]['rn']]]);
            $d->add('Account exclusions', self::KIND_LABEL[$r['kind']], $present[$r['key']]['k']['label'], null, 'remove');
        }
        $skipped = 0;
        $created = 0;
        foreach ($adds as $a) {
            if (isset($present[$a['key']])) {
                $skipped++;
                continue;
            }
            $d->op(['customerNegativeCriterionOperation' => ['create' => self::body($a)]]);
            $created++;
            $d->add('Account exclusions', self::KIND_LABEL[$a['kind']], null, $a['label'], 'add');
        }
        if ($skipped) {
            $d->warn("Skipped $skipped exclusions that already exist in the account");
        }
        self::summaryRow($present, $adds, $removes, $d);
        $d->warn('Account exclusions apply to all campaigns in the account and take effect immediately (placements, YouTube channels and videos, apps and content labels; Search is not affected)');
        if (!$d->ops && !$d->errors) {
            $d->error('Nothing to change');
        }
        $removedN = count(array_filter($removes, fn($r) => isset($present[$r['key']])));
        return Undo::attach(array_merge(Plan::base(), [
            'kind' => 'account_exclusions',
            'summary' => "Account exclusions: +$created −$removedN",
            'steps' => $d->ops ? [['title' => 'Account exclusions', 'operations' => $d->ops]] : [],
            'beforeFingerprint' => self::fingerprint($rows),
        ], $d->common()), $rows);
    }

    // Maps the kind to the create-operation body field in API v26; an app category needs the full resourceName of the constant (mobileAppCategoryConstants/ID).
    private static function body(array $a): array
    {
        return match ($a['kind']) {
            'placement' => ['placement' => ['url' => $a['value']]],
            'youtubeChannel' => ['youtubeChannel' => ['channelId' => $a['value']]],
            'youtubeVideo' => ['youtubeVideo' => ['videoId' => $a['value']]],
            'mobileApp' => ['mobileApplication' => ['appId' => $a['value']]],
            'mobileAppCategory' => ['mobileAppCategory' => ['mobileAppCategoryConstant' => "mobileAppCategoryConstants/{$a['value']}"]],
            default => ['contentLabel' => ['type' => $a['value']]],
        };
    }

    /** The "Current exclusions" row: number of exclusions per type before and after the change (at the top of the preview). */
    private static function summaryRow(array $present, array $adds, array $removes, Draft $d): void
    {
        $count = function (array $kinds): string {
            $parts = [];
            foreach ($kinds as $k => $n) {
                $parts[] = mb_strtolower(self::KIND_LABEL[$k]) . ": $n";
            }
            return implode(', ', $parts) ?: 'none';
        };
        $before = [];
        foreach ($present as $p) {
            $before[$p['k']['kind']] = ($before[$p['k']['kind']] ?? 0) + 1;
        }
        $after = $before;
        foreach ($removes as $r) {
            if (isset($present[$r['key']])) {
                $after[$r['kind']] = ($after[$r['kind']] ?? 1) - 1;
            }
        }
        foreach ($adds as $a) {
            if (!isset($present[$a['key']])) {
                $after[$a['kind']] = ($after[$a['kind']] ?? 0) + 1;
            }
        }
        array_unshift($d->diff, ['section' => 'Account exclusions', 'label' => 'Current exclusions', 'before' => $count($before), 'after' => $count(array_filter($after, fn($n) => $n > 0)), 'change' => 'info']);
    }
}
