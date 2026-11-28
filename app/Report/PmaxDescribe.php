<?php
declare(strict_types=1);

namespace App\Report;

use App\Ads\Money;
use App\Ads\Status;
use App\Media\Ratio;

/** Port of src/mcp/details-pmax.ts: describes a campaign from a snapshot (PmaxSnapshot::load) - for campaign_details and the audit. */
final class PmaxDescribe
{
    private const TEXT_TYPES = ['HEADLINE', 'LONG_HEADLINE', 'DESCRIPTION'];
    private const LIMITS = ['HEADLINE' => 15, 'LONG_HEADLINE' => 5, 'DESCRIPTION' => 5];
    private const IMAGE_TYPES = ['MARKETING_IMAGE', 'SQUARE_MARKETING_IMAGE', 'PORTRAIT_MARKETING_IMAGE', 'LOGO', 'LANDSCAPE_LOGO'];

    /** @return array{ratio:?string,rating:string} */
    public static function ratingOf(int|float $w, int|float $h, string $fieldType): array
    {
        $role = $fieldType === 'LOGO' || $fieldType === 'LANDSCAPE_LOGO' ? 'logo' : 'image';
        $c = Ratio::classify($w, $h, $role);
        if (isset($c['error'])) {
            return ['ratio' => null, 'rating' => 'invalid aspect ratio'];
        }
        return ['ratio' => $c['ratio'], 'rating' => $c['belowMin'] ? 'below minimum' : ($c['belowRecommended'] ? 'below recommended' : 'OK')];
    }

    private static function policyOf(array $r): array
    {
        return [
            'reasons' => $r['primaryStatusReasons'] ?? [],
            'reasonsText' => Status::reasonsText($r['primaryStatusReasons'] ?? null),
            'details' => array_map(fn($d) => ['reason' => $d['reason'] ?? null, 'status' => $d['status'] ?? null], $r['primaryStatusDetails'] ?? []),
        ];
    }

    /** Settings shared by Search and PMax. */
    public static function commonSettings(array $s): array
    {
        $c = $s['campaign'] ?? [];
        $crit = array_map(fn($r) => $r['campaignCriterion'] ?? [], $s['criteria']);
        $locations = [];
        foreach ($crit as $x) {
            if (in_array($x['type'] ?? null, ['LOCATION', 'PROXIMITY', 'LOCATION_GROUP'], true)) {
                $radius = Js::get($x, 'proximity.radius');
                $locations[] = [
                    'type' => $x['type'], 'id' => (string) ($x['criterionId'] ?? ''), 'negative' => !empty($x['negative']),
                    'geoTarget' => Js::get($x, 'location.geoTargetConstant'),
                    'radius' => $radius !== null ? trim(Js::str($radius) . ' ' . (Js::get($x, 'proximity.radiusUnits') ?? '')) : null,
                ];
            }
        }
        $biddable = [];
        foreach (Goals::effective($s) as $g) {
            if ($g['biddable']) {
                $biddable[] = ['id' => $g['actionId'], 'name' => $g['name'], 'category' => $g['category'], 'origin' => $g['origin']];
            }
        }
        return [
            'status' => $c['status'] ?? null, 'servingStatus' => $c['servingStatus'] ?? null, 'primaryStatus' => $c['primaryStatus'] ?? null,
            'primaryStatusReasons' => $c['primaryStatusReasons'] ?? [],
            'primaryStatusReasonsText' => Status::reasonsText($c['primaryStatusReasons'] ?? null),
            'startDateTime' => $c['startDateTime'] ?? null, 'endDateTime' => $c['endDateTime'] ?? null,
            'biddingStrategy' => $c['biddingStrategyType'] ?? null,
            'geoTargetType' => ['positive' => Js::get($c, 'geoTargetTypeSetting.positiveGeoTargetType'), 'negative' => Js::get($c, 'geoTargetTypeSetting.negativeGeoTargetType')],
            'goalLevel' => Js::get($s['goalConfig'] ?? null, 'conversionGoalCampaignConfig.goalConfigLevel') === 'CAMPAIGN' ? 'CAMPAIGN' : 'CUSTOMER',
            'biddableActions' => $biddable,
            'locations' => $locations,
        ];
    }

    private static function assetPolicy(array $ca): array
    {
        return [
            'primaryStatus' => $ca['primaryStatus'] ?? null,
            'reasons' => $ca['primaryStatusReasons'] ?? [],
            'reasonsText' => Status::reasonsText($ca['primaryStatusReasons'] ?? null),
            'details' => array_map(fn($d) => ['reason' => $d['reason'] ?? null, 'status' => $d['status'] ?? null], $ca['primaryStatusDetails'] ?? []),
        ];
    }

    /** Campaign assets (sitelinks, callouts, call, lead form, business name, logo). */
    public static function campaignAssetsOf(array $s): array
    {
        $rows = array_map(fn($r) => ['a' => $r['asset'] ?? [], 'ca' => $r['campaignAsset'] ?? []], $s['campaignAssets']);
        $by = fn(string $t): array => array_values(array_filter($rows, fn($x) => ($x['ca']['fieldType'] ?? null) === $t));
        $lead = $by('LEAD_FORM')[0] ?? null;
        $call = $by('CALL')[0] ?? null;
        $biz = $by('BUSINESS_NAME')[0] ?? null;
        return [
            'sitelinks' => array_map(fn($x) => [
                'assetId' => (string) ($x['a']['id'] ?? ''), 'text' => Js::get($x['a'], 'sitelinkAsset.linkText'),
                'd1' => Js::get($x['a'], 'sitelinkAsset.description1'), 'd2' => Js::get($x['a'], 'sitelinkAsset.description2'),
                'url' => $x['a']['finalUrls'][0] ?? null, 'status' => $x['ca']['status'] ?? null,
            ] + self::assetPolicy($x['ca']), $by('SITELINK')),
            'callouts' => array_map(fn($x) => ['assetId' => (string) ($x['a']['id'] ?? ''), 'text' => Js::get($x['a'], 'calloutAsset.calloutText'), 'status' => $x['ca']['status'] ?? null], $by('CALLOUT')),
            'call' => $call ? ['assetId' => (string) ($call['a']['id'] ?? ''), 'phone' => Js::get($call['a'], 'callAsset.phoneNumber'), 'country' => Js::get($call['a'], 'callAsset.countryCode')] : null,
            'leadForm' => $lead ? ['assetId' => (string) ($lead['a']['id'] ?? ''), 'headline' => Js::get($lead['a'], 'leadFormAsset.headline')] : null,
            'businessName' => $biz ? ['assetId' => (string) ($biz['a']['id'] ?? ''), 'text' => Js::get($biz['a'], 'textAsset.text'), 'status' => $biz['ca']['status'] ?? null] + self::assetPolicy($biz['ca']) : null,
            'logos' => array_map(fn($x) => [
                'assetId' => (string) ($x['a']['id'] ?? ''), 'fieldType' => $x['ca']['fieldType'], 'name' => $x['a']['name'] ?? null,
                'width' => Js::num(Js::get($x['a'], 'imageAsset.fullSize.widthPixels')) ?: null,
                'height' => Js::num(Js::get($x['a'], 'imageAsset.fullSize.heightPixels')) ?: null,
                'status' => $x['ca']['status'] ?? null,
            ] + self::assetPolicy($x['ca']), [...$by('LOGO'), ...$by('LANDSCAPE_LOGO')]),
        ];
    }

    public static function campaignAssetsLine(array $ca): string
    {
        return 'Campaign assets: sitelinks ' . count($ca['sitelinks']) . ', callouts ' . count($ca['callouts'])
            . ', call ' . ($ca['call'] ? 'yes' : 'no') . ', lead form ' . ($ca['leadForm'] ? 'yes' : 'no')
            . ', business name ' . ($ca['businessName']['text'] ?? 'none') . ', logos ' . count($ca['logos']) . '.';
    }

    /** @return array{data: array, text: string} */
    public static function describe(array $s): array
    {
        $c = $s['campaign'] ?? [];
        $negatives = [];
        foreach ($s['criteria'] as $r) {
            $x = $r['campaignCriterion'] ?? [];
            if (($x['type'] ?? null) === 'KEYWORD' && !empty($x['negative'])) {
                $negatives[] = ['criterionId' => (string) ($x['criterionId'] ?? ''), 'text' => Js::get($x, 'keyword.text'), 'matchType' => Js::get($x, 'keyword.matchType')];
            }
        }
        $automation = [];
        foreach ($c['assetAutomationSettings'] ?? [] as $a) {
            $automation[$a['assetAutomationType'] ?? ''] = $a['assetAutomationStatus'] ?? null;
        }
        $settings = self::commonSettings($s) + [
            'dailyBudget' => Js::get($s['budget'] ?? null, 'amountMicros') === null ? null : Js::round2(Money::microsToPln(Js::get($s['budget'], 'amountMicros'))),
            'negativeKeywords' => $negatives,
            'assetAutomation' => $automation,
            'brandGuidelinesEnabled' => $c['brandGuidelinesEnabled'] ?? null,
        ];

        $assetGroups = array_map(fn($row) => self::assetGroup($s, $row['assetGroup'] ?? []), $s['assetGroups']);
        $signals = self::signals($s);
        $campaignAssets = self::campaignAssetsOf($s);

        $text = self::text($c, $settings, $assetGroups, $signals, $campaignAssets);
        return ['data' => ['settings' => $settings, 'assetGroups' => $assetGroups, 'signals' => $signals, 'campaignAssets' => $campaignAssets], 'text' => $text];
    }

    private static function assetGroup(array $s, array $g): array
    {
        $gid = (string) ($g['id'] ?? '');
        $assets = [];
        foreach ($s['groupAssets'] as $r) {
            if ((string) Js::get($r, 'assetGroup.id') !== $gid) {
                continue;
            }
            $ga = $r['assetGroupAsset'] ?? [];
            $a = $r['asset'] ?? [];
            $ft = (string) ($ga['fieldType'] ?? '');
            $base = ['assetId' => (string) ($a['id'] ?? ''), 'status' => $ga['status'] ?? null, 'primaryStatus' => $ga['primaryStatus'] ?? null, 'policy' => self::policyOf($ga)];
            if (in_array($ft, self::TEXT_TYPES, true) || isset($a['textAsset'])) {
                $t = (string) Js::get($a, 'textAsset.text', '');
                $item = $base + ['text' => $t, 'chars' => mb_strlen($t)];
            } elseif (in_array($ft, self::IMAGE_TYPES, true)) {
                $w = Js::num(Js::get($a, 'imageAsset.fullSize.widthPixels'));
                $h = Js::num(Js::get($a, 'imageAsset.fullSize.heightPixels'));
                $item = $base + ['width' => $w, 'height' => $h] + self::ratingOf($w, $h, $ft) + ['url' => Js::get($a, 'imageAsset.fullSize.url')];
            } elseif ($ft === 'YOUTUBE_VIDEO') {
                $item = $base + ['videoId' => Js::get($a, 'youtubeVideoAsset.youtubeVideoId'), 'title' => Js::get($a, 'youtubeVideoAsset.youtubeVideoTitle')];
            } else {
                $item = $base + ['name' => $a['name'] ?? null];
            }
            $assets[$ft][] = $item;
        }
        return [
            'id' => $gid, 'name' => $g['name'] ?? null, 'status' => $g['status'] ?? null, 'primaryStatus' => $g['primaryStatus'] ?? null,
            'reasons' => $g['primaryStatusReasons'] ?? [], 'finalUrls' => $g['finalUrls'] ?? [], 'path1' => $g['path1'] ?? null, 'path2' => $g['path2'] ?? null,
            'adStrength' => $g['adStrength'] ?? null, 'assets' => $assets,
        ];
    }

    private static function signals(array $s): array
    {
        $aud = [];
        foreach ($s['audiences'] as $r) {
            $aud[(string) Js::get($r, 'audience.resourceName')] = $r['audience'] ?? [];
        }
        $themes = [];
        $audiences = [];
        foreach ($s['signals'] as $r) {
            $sig = $r['assetGroupSignal'] ?? [];
            if (!empty($sig['searchTheme'])) {
                $themes[] = ['text' => $sig['searchTheme']['text'] ?? null, 'approval' => $sig['approvalStatus'] ?? null, 'assetGroupId' => (string) Js::get($r, 'assetGroup.id')];
            }
            if (!empty($sig['audience'])) {
                $rn = (string) ($sig['audience']['audience'] ?? '');
                $a = $aud[$rn] ?? null;
                $parts = explode('/', $rn);
                $audiences[] = ['id' => end($parts), 'name' => $a['name'] ?? null, 'dimensions' => $a['dimensions'] ?? [], 'assetGroupId' => (string) Js::get($r, 'assetGroup.id')];
            }
        }
        return ['searchThemes' => $themes, 'audiences' => $audiences];
    }

    private static function text(array $c, array $settings, array $assetGroups, array $signals, array $campaignAssets): string
    {
        $end = $settings['endDateTime'] ?? 'none';
        $loc = implode(', ', array_map(fn($l) => ($l['negative'] ? '-' : '') . Js::str($l['geoTarget'] ?? $l['type']) . ($l['radius'] ? " {$l['radius']}" : ''), $settings['locations']));
        $auto = implode(', ', array_map(fn($k, $v) => "$k=" . Js::str($v), array_keys($settings['assetAutomation']), $settings['assetAutomation']));
        $goals = implode(', ', array_map(fn($g) => $g['name'], $settings['biddableActions']));
        $lines = [
            'PMax campaign "' . Js::str($c['name'] ?? 'undefined') . '" [' . Js::str($c['id'] ?? 'undefined') . '] ' . Js::str($c['status'] ?? 'undefined') . ' (' . ($c['servingStatus'] ?? '?') . "), ends: $end",
            'Start: ' . ($settings['startDateTime'] ?? 'none') . '. Daily budget: ' . Js::str($settings['dailyBudget'] ?? '?') . ' PLN, strategy ' . Js::str($settings['biddingStrategy'])
                . '. Locations: ' . Js::str($settings['geoTargetType']['positive']) . '/' . Js::str($settings['geoTargetType']['negative']) . '.',
            'Primary status: ' . ($settings['primaryStatus'] ?? '?') . ($settings['primaryStatusReasonsText'] ? '; reasons: ' . implode(', ', $settings['primaryStatusReasonsText']) : '') . '.',
            'Locations (' . count($settings['locations']) . '): ' . ($loc ?: 'none') . '. Negative keywords: ' . count($settings['negativeKeywords']) . '.',
            'Automation: ' . ($auto ?: 'none') . '. Brand guidelines: ' . Js::str($settings['brandGuidelinesEnabled']) . ". Goals ({$settings['goalLevel']}): " . ($goals ?: 'none') . '.',
        ];
        foreach ($assetGroups as $g) {
            $n = fn(string $t): int => count($g['assets'][$t] ?? []);
            $imgs = 0;
            foreach (self::IMAGE_TYPES as $t) {
                $imgs += $t === 'LOGO' || $t === 'LANDSCAPE_LOGO' ? 0 : $n($t);
            }
            $low = 0;
            foreach ($g['assets'] as $items) {
                foreach ($items as $x) {
                    $low += isset($x['rating']) && $x['rating'] !== '' && $x['rating'] !== 'OK' ? 1 : 0;
                }
            }
            $lines[] = 'Asset group "' . Js::str($g['name']) . "\" [{$g['id']}] " . Js::str($g['status']) . '/' . Js::str($g['primaryStatus']) . ', strength ' . Js::str($g['adStrength'])
                . ': headlines ' . $n('HEADLINE') . '/' . self::LIMITS['HEADLINE'] . ', long ' . $n('LONG_HEADLINE') . '/' . self::LIMITS['LONG_HEADLINE']
                . ', descriptions ' . $n('DESCRIPTION') . '/' . self::LIMITS['DESCRIPTION'] . ", images $imgs/20 (warnings: $low), logos " . ($n('LOGO') + $n('LANDSCAPE_LOGO'))
                . ', videos ' . $n('YOUTUBE_VIDEO') . '/15. URL: ' . implode(', ', $g['finalUrls']);
            foreach (self::TEXT_TYPES as $t) {
                if ($n($t)) {
                    $lines[] = "  $t: " . implode(' | ', array_map(fn($x) => $x['text'], $g['assets'][$t]));
                }
            }
        }
        $lines[] = 'Signals: themes ' . count($signals['searchThemes']) . ', audiences ' . count($signals['audiences']) . '.';
        $lines[] = self::campaignAssetsLine($campaignAssets);
        $lines[] = 'PMax asset performance labels are not available in the API - showing status and policy.';
        $text = implode("\n", $lines);
        if (mb_strlen($text) > 4000) {
            $cut = mb_substr($text, 0, 3990);
            $nl = mb_strrpos($cut, "\n");
            $text = mb_substr($cut, 0, max($nl === false ? -1 : $nl, 0) ?: 3990) . "\n...(truncated)";
        }
        return $text;
    }
}
