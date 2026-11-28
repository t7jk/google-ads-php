<?php
declare(strict_types=1);

namespace App\Health;

use App\Ads\Money;
use App\Ads\Status;
use App\Ads\Time;
use App\Google\Ads;
use App\Lp2\UrlHost;
use App\Media\Ratio;
use App\Report\Goals;
use App\Report\Js;
use App\Report\PmaxDescribe;
use App\Report\PmaxSnapshot;
use App\Report\Queries;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Port of src/health/rules.ts: campaign audit (campaign_health_check). Problem = ['severity','code','message','element'?].
 * Assumptions: the campaign end time (endDateTime) is in the Europe/Warsaw time zone (as in Node, regardless of account_time_zone);
 * Google statistics lag by about 3 h, hence the no-impressions thresholds are counted from (enable time + 3 h).
 */
final class Audit
{
    private const ORDER = ['CRITICAL', 'IMPORTANT', 'INFO'];
    private const IMAGE_FIELDS = ['MARKETING_IMAGE', 'SQUARE_MARKETING_IMAGE', 'PORTRAIT_MARKETING_IMAGE', 'LOGO', 'LANDSCAPE_LOGO'];
    private const AUTOMATIONS = ['FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION', 'GENERATE_IMAGE_EXTRACTION'];
    private const LOW_STRENGTH = ['POOR', 'AVERAGE'];
    private const NAV_EVENT = '/\b(page_view|user_engagement|scroll|session_start|first_visit)\b/i';
    private const STATS_DELAY_H = 3;
    private const WARN_H = 12;
    private const CRIT_H = 24;

    /** @var list<array> problems collected by the current audit */
    private array $out = [];

    /** Loads the snapshot (for Search also networks and Search data) and runs the audit. @return array{name:string,problems:list<array>} */
    public static function auditCampaign(string $campaignId, DateTimeInterface $now): array
    {
        $snap = PmaxSnapshot::load($campaignId);
        $search = null;
        if (Js::get($snap['campaign'], 'advertisingChannelType') === 'SEARCH') {
            $q = Queries::searchSnapshot($campaignId);
            $search = [
                'ads' => Ads::search($q['ads']), 'keywords' => Ads::search($q['keywords']), 'negatives' => Ads::search($q['negatives']),
                'networks' => Js::get(Ads::search($q['networks'])[0] ?? [], 'campaign.networkSettings'),
            ];
        }
        $extra = [];
        $tz = (string) config('account_time_zone', 'Europe/Warsaw');
        if (Js::get($snap['campaign'], 'status') === 'ENABLED') {
            $extra['enabledAt'] = null;
            try {
                $extra['enabledAt'] = self::lastEnabledAt($campaignId, $now);
            } catch (\Throwable $e) {
                $extra['enabledError'] = 'error reading change_event (' . mb_substr($e->getMessage(), 0, 120) . ')';
            }
            $from = Time::dateInZone($extra['enabledAt'] ?? DateTimeImmutable::createFromInterface($now)->modify('-1 day'), $tz);
            $to = Time::dateInZone($now, $tz);
            $rows = Ads::search("SELECT campaign.id, metrics.impressions FROM campaign WHERE campaign.id = " . Queries::id($campaignId) . " AND segments.date BETWEEN '" . \App\Report\Range::assertDate($from) . "' AND '" . \App\Report\Range::assertDate($to) . "'");
            $extra['impressions'] = array_sum(array_map(fn($r) => Js::num(Js::get($r, 'metrics.impressions')), $rows));
        }
        return [
            'name' => (string) (Js::get($snap['campaign'], 'name') ?? $campaignId),
            'problems' => (new self())->run($snap, $search, $now, $extra),
        ];
    }

    /** Latest status -> ENABLED change from change_event (about 30 days); the time is converted explicitly to the account time zone. */
    public static function lastEnabledAt(string $campaignId, DateTimeInterface $now): ?DateTimeImmutable
    {
        $tz = (string) config('account_time_zone', 'Europe/Warsaw');
        $range = Time::changeEventRange($now, $tz);
        $rows = Ads::search(Queries::campaignChangeEvents($campaignId, $range['from'], $range['to'], Ads::customerId()));
        foreach ($rows as $r) {
            $e = $r['changeEvent'] ?? [];
            $fields = array_map('trim', explode(',', (string) ($e['changedFields'] ?? '')));
            if (Js::get($e, 'newResource.campaign.status') === 'ENABLED' && in_array('status', $fields, true)) {
                return Time::zonedToDate((string) $e['changeDateTime'], $tz);
            }
        }
        return null;
    }

    /** @param array $extra enabledAt (DateTimeImmutable|null; missing key = not checked), impressions, enabledError */
    public static function check(array $s, ?array $search, DateTimeInterface $now, array $extra = []): array
    {
        return (new self())->run($s, $search, $now, $extra);
    }

    public static function text(string $name, array $problems): string
    {
        $n = fn(string $sev): int => count(array_filter($problems, fn($p) => $p['severity'] === $sev));
        $lines = ["Audit \"{$name}\": {$n('CRITICAL')} critical, {$n('IMPORTANT')} important, {$n('INFO')} info"];
        foreach ($problems as $p) {
            $lines[] = "[{$p['severity']}] {$p['code']}: {$p['message']}";
        }
        return implode("\n", $lines);
    }

    private function add(string $severity, string $code, string $message, ?string $element = null): void
    {
        $this->out[] = ['severity' => $severity, 'code' => $code, 'message' => $message] + ($element !== null && $element !== '' ? ['element' => $element] : []);
    }

    private function run(array $s, ?array $search, DateTimeInterface $now, array $extra): array
    {
        $this->out = [];
        $c = $s['campaign'] ?? [];
        $pmax = ($c['advertisingChannelType'] ?? null) === 'PERFORMANCE_MAX';
        $settings = PmaxDescribe::commonSettings($s);
        $ca = PmaxDescribe::campaignAssetsOf($s);

        $this->deadline($c, $now);
        $this->conversions($s);
        $this->urls($s, $search, $ca);
        if ($pmax) {
            $this->pmaxAssets($s, $c, $ca);
        }
        $this->policies($s);
        if ($pmax && $ca['logos']) {
            $this->logoInfo($ca);
        }
        $this->primaryStatus($c);
        $this->noImpressions($c, $now, $extra);
        $positive = $settings['geoTargetType']['positive'];
        if ($positive && $positive !== 'PRESENCE') {
            $this->add('IMPORTANT', 'GEO_NOT_PRESENCE', "The location target is $positive, not PRESENCE - ads may reach people outside the region", 'geoTargetType');
        }
        if ($ca['leadForm']) {
            $this->add('INFO', 'LEAD_FORM_ACTIVE', 'A lead form is attached to the campaign (' . Js::str($ca['leadForm']['headline'] ?? $ca['leadForm']['assetId']) . ') - leads may bypass the LP2 page', 'lead form');
        }
        if ($search && ($c['advertisingChannelType'] ?? null) === 'SEARCH') {
            $this->searchRules($search);
        }
        $amount = Js::get($s['budget'] ?? null, 'amountMicros');
        $max = (float) config('max_daily_budget_pln', 30);
        if ($amount !== null) {
            $budget = Js::normalize(Money::microsToPln($amount));
            if ($budget > $max) {
                $this->add('IMPORTANT', 'BUDGET_HIGH', "Daily budget $budget PLN exceeds the limit " . Js::str(Js::normalize($max)) . ' PLN', 'budget');
            }
        }
        $sev = fn(array $p): int => (int) array_search($p['severity'], self::ORDER, true);
        usort($this->out, fn($a, $b) => $sev($a) <=> $sev($b)); // usort is stable (PHP 8+), like sort in Node
        return $this->out;
    }

    /** End date (endDateTime in the Europe/Warsaw time zone). */
    private function deadline(array $c, DateTimeInterface $now): void
    {
        if (empty($c['endDateTime'])) {
            return;
        }
        $nowW = DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i:s');
        $end = (string) $c['endDateTime'];
        $asUtc = fn(string $t): int => (int) strtotime($t . ' UTC');
        if ($end < $nowW) {
            $this->add('CRITICAL', 'END_PAST', "The campaign end date ($end) has passed - the campaign does not serve ads", 'endDateTime');
        } elseif ($asUtc($end) - $asUtc($nowW) <= 7 * 86400) {
            $this->add('IMPORTANT', 'END_SOON', "The campaign ends $end (in 7 days or less)", 'endDateTime');
        }
    }

    /** Conversions and goals. */
    private function conversions(array $s): void
    {
        $goals = Goals::effective($s);
        if (!array_filter($goals, fn($g) => $g['biddable'])) {
            $this->add('CRITICAL', 'NO_BIDDABLE', 'No conversion actions the campaign can optimize for (no goal is biddable)', 'conversion goals');
        }
        $cfgId = (string) config('conversion_action_id', '');
        $cfgName = (string) config('conversion_action_name', '');
        foreach ($goals as $g) {
            if (($cfgId !== '' && $g['actionId'] === $cfgId) || $g['name'] === $cfgName) {
                if (!$g['biddable']) {
                    $this->add('CRITICAL', 'LP2_NOT_BIDDABLE', "The LP2 conversion action \"{$g['name']}\" ({$g['actionId']}) is not biddable - the campaign does not optimize for leads", $g['name']);
                }
                break;
            }
        }
        $campaignLevel = Js::get($s['goalConfig'] ?? null, 'conversionGoalCampaignConfig.goalConfigLevel') === 'CAMPAIGN';
        $goalRows = array_map(fn($r) => $campaignLevel ? ($r['campaignConversionGoal'] ?? null) : ($r['customerConversionGoal'] ?? null), $campaignLevel ? $s['campaignGoals'] : $s['customerGoals']);
        $goalKeys = [];
        foreach ($goalRows as $g) {
            if (($g['biddable'] ?? null) === true) {
                $goalKeys[Js::str($g['category'] ?? null) . '~' . Js::str($g['origin'] ?? null)] = true;
            }
        }
        // Secondary actions (primary_for_goal=false) are a valid state - skipped without INFO.
        foreach ($goals as $g) {
            if ($g['primary'] && $g['includeInConversions'] === false) {
                $base = "The action \"{$g['name']}\" ({$g['actionId']}) is primary but is not counted in the Conversions column (include_in_conversions_metric=false) - bidding does not see it";
                if (Goals::isNonMutableActionType($g['type'])) {
                    $this->add('INFO', 'CONV_PRIMARY_NOT_COUNTED', "$base; type {$g['type']} cannot be changed through the API - only manually in the UI (Goals → Conversions → {$g['name']} → Settings)", $g['name']);
                } else {
                    $this->add('IMPORTANT', 'CONV_PRIMARY_NOT_COUNTED', $base, $g['name']);
                }
            }
            if ($g['primary'] && preg_match(self::NAV_EVENT, $g['name'], $m)) {
                $this->add('CRITICAL', 'PRIMARY_NAV_EVENT', "The primary action \"{$g['name']}\" ({$g['actionId']}) is a GA4 navigation event ({$m[1]}) - it inflates conversions and spoils bidding", $g['name']);
            }
        }
        foreach (array_keys($goalKeys) as $key) {
            $key = (string) $key;
            if (!array_filter($goals, fn($g) => $g['primary'] && "{$g['category']}~{$g['origin']}" === $key)) {
                $this->add('CRITICAL', 'GOAL_NO_PRIMARY', 'Goal ' . str_replace('~', '/', $key) . ' is biddable but has no enabled primary action', $key);
            }
        }
    }

    /** URLs outside the allowed domains. */
    private function urls(array $s, ?array $search, array $ca): void
    {
        $urls = [];
        foreach ($s['assetGroups'] as $row) {
            foreach (Js::get($row, 'assetGroup.finalUrls', []) as $u) {
                $urls[] = [$u, 'asset group "' . Js::str(Js::get($row, 'assetGroup.name')) . '"'];
            }
        }
        foreach ($ca['sitelinks'] as $sl) {
            if ($sl['url']) {
                $urls[] = [$sl['url'], 'sitelink "' . Js::str($sl['text']) . '"'];
            }
        }
        foreach ($s['campaignAssets'] as $r) {
            $pp = Js::get($r, 'asset.leadFormAsset.privacyPolicyUrl');
            if (Js::get($r, 'campaignAsset.fieldType') === 'LEAD_FORM' && $pp) {
                $urls[] = [$pp, 'lead form privacy policy'];
            }
        }
        foreach ($search['ads'] ?? [] as $r) {
            foreach (Js::get($r, 'adGroupAd.ad.finalUrls', []) as $u) {
                $urls[] = [$u, 'ad ' . Js::str(Js::get($r, 'adGroupAd.ad.id', 'undefined'))];
            }
        }
        foreach ($urls as [$url, $element]) {
            $bad = UrlHost::check((string) $url);
            if ($bad) {
                $this->add('CRITICAL', 'URL_DOMAIN', "$element: $bad", $element);
            }
        }
    }

    /** PMax assets: minimum, recommendations, video, ad strength, images, automation. */
    private function pmaxAssets(array $s, array $c, array $ca): void
    {
        $brand = ($c['brandGuidelinesEnabled'] ?? null) === true;
        $kinds = fn(string $t): int => count(array_filter($ca['logos'], fn($l) => $l['fieldType'] === $t));
        $campBusiness = $brand && !empty($ca['businessName']);
        $campLogo = $brand && $kinds('LOGO') > 0;
        $campLandscape = $kinds('LANDSCAPE_LOGO') > 0;
        foreach ($s['assetGroups'] as $row) {
            $g = $row['assetGroup'] ?? [];
            $gid = (string) ($g['id'] ?? '');
            $name = 'asset group "' . Js::str($g['name'] ?? null) . '"';
            $mine = array_values(array_filter($s['groupAssets'], fn($r) => (string) Js::get($r, 'assetGroup.id') === $gid));
            $n = fn(string $t): int => count(array_filter($mine, fn($r) => Js::get($r, 'assetGroupAsset.fieldType') === $t));
            $descs = array_values(array_filter($mine, fn($r) => Js::get($r, 'assetGroupAsset.fieldType') === 'DESCRIPTION'));
            $miss = [];
            if ($n('HEADLINE') < 3) {
                $miss[] = 'headlines ' . $n('HEADLINE') . '/min. 3';
            }
            if ($n('LONG_HEADLINE') < 1) {
                $miss[] = 'no long headline';
            }
            if (count($descs) < 2) {
                $miss[] = 'descriptions ' . count($descs) . '/min. 2';
            } elseif (!array_filter($descs, fn($r) => mb_strlen((string) Js::get($r, 'asset.textAsset.text', '')) <= 60)) {
                $miss[] = 'no description of 60 characters or fewer';
            }
            if ($n('MARKETING_IMAGE') < 1) {
                $miss[] = 'no landscape image (MARKETING_IMAGE)';
            }
            if ($n('SQUARE_MARKETING_IMAGE') < 1) {
                $miss[] = 'no square image (SQUARE_MARKETING_IMAGE)';
            }
            if ($n('LOGO') < 1 && !$campLogo) {
                $miss[] = 'no logo';
            }
            if ($n('BUSINESS_NAME') < 1 && !$campBusiness) {
                $miss[] = 'no business name';
            }
            if ($miss) {
                $this->add('CRITICAL', 'PMAX_MIN', "$name: required assets are missing - " . implode('; ', $miss), $name);
            }
            $rec = [];
            if ($n('HEADLINE') < 5) {
                $rec[] = 'headlines ' . $n('HEADLINE') . '/recommended 5';
            }
            if (count($descs) < 4) {
                $rec[] = 'descriptions ' . count($descs) . '/recommended 4';
            }
            if ($n('PORTRAIT_MARKETING_IMAGE') < 1) {
                $rec[] = 'no portrait 4:5 image (PORTRAIT_MARKETING_IMAGE)';
            }
            if ($n('LANDSCAPE_LOGO') < 1 && !$campLandscape) {
                $rec[] = 'no landscape logo (LANDSCAPE_LOGO)';
            }
            if ($rec) {
                $this->add('IMPORTANT', 'PMAX_RECOMMENDED', "$name: below recommendations - " . implode('; ', $rec), $name);
            }
            if ($n('YOUTUBE_VIDEO') < 1) {
                $this->add('INFO', 'NO_VIDEO', "$name: no video - Google will generate one automatically from images", $name);
            }
            if (in_array((string) ($g['adStrength'] ?? ''), self::LOW_STRENGTH, true)) {
                $this->adStrength($g, $name, $mine, $ca, $n);
            }
            foreach ($mine as $r) {
                $ft = (string) Js::get($r, 'assetGroupAsset.fieldType');
                if (in_array($ft, self::IMAGE_FIELDS, true)) {
                    $this->imageIssue($r['asset'] ?? [], $ft, $name);
                }
            }
        }
        foreach ($ca['logos'] as $l) {
            if ($l['width'] && $l['height']) {
                $this->imageIssue(['name' => $l['name'], 'imageAsset' => ['fullSize' => ['widthPixels' => $l['width'], 'heightPixels' => $l['height']]]], $l['fieldType'], 'campaign');
            }
        }
        $autos = [];
        foreach ($c['assetAutomationSettings'] ?? [] as $a) {
            $autos[$a['assetAutomationType'] ?? ''] = $a['assetAutomationStatus'] ?? null;
        }
        foreach (self::AUTOMATIONS as $t) {
            if (!array_key_exists($t, $autos) || $autos[$t] === 'OPTED_IN') {
                $this->add('IMPORTANT', 'AUTOMATION_ON', "Asset automation $t is on" . (!array_key_exists($t, $autos) ? ' (default)' : '') . ' - Google may change texts/images without approval', $t);
            }
        }
    }

    private function adStrength(array $g, string $name, array $mine, array $ca, callable $n): void
    {
        $why = [];
        if ($n('YOUTUBE_VIDEO') < 1) {
            $why[] = 'no video';
        }
        $logos = [];
        foreach ($mine as $r) {
            $ft = (string) Js::get($r, 'assetGroupAsset.fieldType');
            if ($ft === 'LOGO' || $ft === 'LANDSCAPE_LOGO') {
                $logos[] = ['ft' => $ft, 'a' => $r['asset'] ?? []];
            }
        }
        foreach ($ca['logos'] as $l) {
            $logos[] = ['ft' => $l['fieldType'], 'a' => ['imageAsset' => ['fullSize' => ['widthPixels' => $l['width'], 'heightPixels' => $l['height']]]]];
        }
        foreach ($logos as $l) {
            $w = Js::num(Js::get($l['a'], 'imageAsset.fullSize.widthPixels'));
            $h = Js::num(Js::get($l['a'], 'imageAsset.fullSize.heightPixels'));
            $cl = $w && $h ? Ratio::classify($w, $h, 'logo') : null;
            if ($cl && !isset($cl['error']) && ($cl['belowMin'] || $cl['belowRecommended'])) {
                $why[] = "logo below recommendations ({$l['ft']} {$w}×{$h})";
                break;
            }
        }
        foreach ([['MARKETING_IMAGE', 'landscape'], ['SQUARE_MARKETING_IMAGE', 'square'], ['PORTRAIT_MARKETING_IMAGE', 'portrait']] as [$ft, $label]) {
            if ($n($ft) < 3) {
                $why[] = "few $label images (" . $n($ft) . '/recommended min. 3)';
            }
        }
        $this->add('IMPORTANT', 'AD_STRENGTH_LOW', "$name: ad strength {$g['adStrength']}" . ($why ? ' - visible: ' . implode('; ', $why) : ''), $name);
    }

    private function imageIssue(array $asset, string $ft, string $where): void
    {
        $w = Js::num(Js::get($asset, 'imageAsset.fullSize.widthPixels'));
        $h = Js::num(Js::get($asset, 'imageAsset.fullSize.heightPixels'));
        if (!$w || !$h) {
            return;
        }
        $label = trim((string) preg_replace('/\s+/', ' ', "$where: $ft " . ($asset['name'] ?? '') . " {$w}×{$h}"));
        $cl = Ratio::classify($w, $h, $ft === 'LOGO' || $ft === 'LANDSCAPE_LOGO' ? 'logo' : 'image');
        if (isset($cl['error'])) {
            $this->add('IMPORTANT', 'IMAGE_SIZE', "$label: {$cl['error']}", $label);
        } elseif ($cl['fieldType'] !== $ft) {
            $this->add('IMPORTANT', 'IMAGE_SIZE', "$label: aspect ratio {$cl['ratio']} fits {$cl['fieldType']}, not $ft", $label);
        } elseif ($cl['belowMin']) {
            $this->add('IMPORTANT', 'IMAGE_SIZE', "$label: below the minimum size", $label);
        } elseif ($cl['belowRecommended']) {
            $this->add('IMPORTANT', 'IMAGE_SIZE', "$label: below the recommended size", $label);
        }
    }

    /** Asset status reasons: primaryStatusReasons + primaryStatusDetails[].reason. */
    private static function reasonsOf(array $r): array
    {
        $out = array_map('strval', $r['primaryStatusReasons'] ?? []);
        foreach ($r['primaryStatusDetails'] ?? [] as $d) {
            $out[] = Js::str($d['reason'] ?? 'undefined');
        }
        return $out;
    }

    /** Asset policies: disapproved, under review, limited. */
    private function policies(array $s): void
    {
        $policy = function (array $r, string $label): void {
            $reasons = self::reasonsOf($r);
            $limited = ($r['primaryStatus'] ?? null) === 'LIMITED' || array_filter($reasons, fn($x) => str_contains($x, 'LIMITED'));
            if (in_array('ASSET_DISAPPROVED', $reasons, true)) {
                $this->add('CRITICAL', 'ASSET_DISAPPROVED', "$label: asset disapproved by Google", $label);
            } elseif (($r['primaryStatus'] ?? null) === 'PENDING' || in_array('ASSET_UNDER_REVIEW', $reasons, true)) {
                $this->add('IMPORTANT', 'ASSET_PENDING', "$label: asset under review - ads may not serve until it is approved", $label);
            } elseif ($limited) {
                $this->add('IMPORTANT', 'ASSET_LIMITED', "$label: asset with serving limitations", $label);
            }
        };
        foreach ($s['groupAssets'] as $r) {
            $a = $r['asset'] ?? [];
            $text = Js::get($a, 'textAsset.text');
            $policy($r['assetGroupAsset'] ?? [], Js::str(Js::get($r, 'assetGroupAsset.fieldType', 'undefined')) . ' ' . ($text ? "\"{$text}\"" : Js::str($a['name'] ?? $a['id'] ?? 'undefined')));
        }
        foreach ($s['campaignAssets'] as $r) {
            $a = $r['asset'] ?? [];
            $policy($r['campaignAsset'] ?? [], Js::str(Js::get($r, 'campaignAsset.fieldType', 'undefined')) . ' of the campaign ' . Js::str(Js::get($a, 'sitelinkAsset.linkText') ?? Js::get($a, 'textAsset.text') ?? $a['name'] ?? $a['id'] ?? 'undefined'));
        }
    }

    private function logoInfo(array $ca): void
    {
        $desc = array_map(function ($l) {
            $dims = $l['width'] && $l['height'] ? "{$l['width']}×{$l['height']}" : '?';
            $cl = $l['width'] && $l['height'] ? Ratio::classify($l['width'], $l['height'], 'logo') : null;
            if ($cl && !isset($cl['error'])) {
                $ratio = $cl['ratio'];
            } elseif ($l['width'] && $l['height']) {
                $g = self::gcd((int) $l['width'], (int) $l['height']);
                $ratio = ($l['width'] / $g) . ':' . ($l['height'] / $g);
            } else {
                $ratio = '?';
            }
            return "{$l['fieldType']} " . Js::str($l['name'] ?? $l['assetId']) . " $dims ($ratio)";
        }, $ca['logos']);
        $this->add('INFO', 'LOGO_INFO', 'Campaign logos: ' . implode('; ', $desc), 'logo');
    }

    private static function gcd(int $a, int $b): int
    {
        return $b ? self::gcd($b, $a % $b) : $a;
    }

    /** Primary campaign status with reasons. */
    private function primaryStatus(array $c): void
    {
        $ps = $c['primaryStatus'] ?? null;
        if (!$ps || $ps === 'ELIGIBLE') {
            return;
        }
        $rs = Status::reasonsText($c['primaryStatusReasons'] ?? null);
        $sev = ($c['status'] ?? null) !== 'ENABLED' ? 'INFO' : ($ps === 'NOT_ELIGIBLE' ? 'CRITICAL' : 'IMPORTANT');
        $this->add($sev, 'PRIMARY_STATUS', "Campaign primary status: $ps" . ($rs ? '; reasons: ' . implode(', ', $rs) : ''), 'primary_status');
    }

    /** No impressions after enabling (enable time from change_event, converted using the account time zone). */
    private function noImpressions(array $c, DateTimeInterface $now, array $extra): void
    {
        if (($c['status'] ?? null) !== 'ENABLED' || !array_key_exists('enabledAt', $extra) || ($extra['impressions'] ?? null) !== 0) {
            return;
        }
        if ($extra['enabledAt'] === null) {
            $this->add('INFO', 'NO_IMPRESSIONS', 'The campaign has 0 impressions, but the enable time could not be determined: ' . ($extra['enabledError'] ?? 'no change_event entry in the last 30 days'), 'impressions');
            return;
        }
        $hours = ($now->getTimestamp() - $extra['enabledAt']->getTimestamp()) / 3600 - self::STATS_DELAY_H;
        $local = Time::dateToZoned($extra['enabledAt'], (string) config('account_time_zone', 'Europe/Warsaw'));
        $when = substr($local, 0, 10) . ' ' . substr($local, 11, 5);
        if ($hours >= self::WARN_H) {
            $this->add($hours >= self::CRIT_H ? 'CRITICAL' : 'IMPORTANT', 'NO_IMPRESSIONS',
                "Campaign enabled $when (account time) and still 0 impressions - " . (int) floor($hours + self::STATS_DELAY_H) . ' h since enabling (allowing for ' . self::STATS_DELAY_H . ' h of statistics delay)', 'impressions');
        }
    }

    /** Search campaign rules: networks, negatives, keyword match types. */
    private function searchRules(array $search): void
    {
        if (Js::get($search['networks'] ?? null, 'targetContentNetwork') === true) {
            $this->add('IMPORTANT', 'SEARCH_DISPLAY_ON', 'The Search campaign has the Display Network enabled (targetContentNetwork)', 'networks');
        }
        if (count($search['negatives'] ?? []) === 0) {
            $this->add('IMPORTANT', 'SEARCH_NO_NEGATIVES', 'The Search campaign has no negative keywords', 'negative keywords');
        }
        $kws = [];
        foreach ($search['keywords'] ?? [] as $r) {
            $k = $r['adGroupCriterion'] ?? null;
            if ($k && empty($k['negative']) && ($k['status'] ?? null) !== 'REMOVED') {
                $kws[] = $k;
            }
        }
        if ($kws && !array_filter($kws, fn($k) => Js::get($k, 'keyword.matchType') !== 'BROAD')) {
            $this->add('INFO', 'SEARCH_ONLY_BROAD', 'All keywords use broad match (BROAD)', 'keywords');
        }
    }
}
