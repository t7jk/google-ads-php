<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Google\Ads;
use App\Plans\{Cfg, Json, Plan, Undo};
use App\Plans\Snapshot\{CampaignSnapshot, PmaxSnapshot};

/**
 * Small builders and helpers (port of build-misc.ts): test plan (selftest), campaign pause (Search and PMax),
 * conversion tag data, the old "LP2 conversion action" plan, location names for the preview.
 */
final class Misc
{
    private const PROBE_ROWS = [
        'ads' => 'Google Ads (read)', 'ga4data' => 'GA4 Data API', 'ga4admin' => 'GA4 Admin API (read)', 'editScope' => 'analytics.edit scope',
        'uploadClicks' => 'UPLOAD_CLICKS action', 'lp2' => 'Endpoint lp2-admin', 'tools' => 'Connector tools',
    ];
    private const GEO_RN = '#^geoTargetConstants/\d+$#';

    /** @param array<string, array{text: string, note: string, level?: string}> $probes read-probe results (SelftestProbes::run) */
    public static function selftestPlan(array $probes = []): array
    {
        $ga4Row = !Cfg::ga4PropertyId()
            ? ['section' => 'Configuration', 'label' => 'GA4', 'before' => null, 'after' => 'GA4_PROPERTY_ID MISSING – ga4_report does not work', 'change' => 'info', 'level' => 'danger']
            : ['section' => 'Configuration', 'label' => 'GA4', 'before' => null, 'after' => 'ID set', 'change' => 'info'];
        $toolRows = [];
        foreach (self::PROBE_ROWS as $key => $label) {
            if (!isset($probes[$key])) {
                continue;
            }
            $r = $probes[$key];
            $toolRows[] = ['section' => 'Tools', 'label' => $label, 'before' => null, 'after' => $r['text'], 'change' => 'info', 'note' => $r['note']] + (isset($r['level']) ? ['level' => $r['level']] : []);
        }
        $info = fn(string $section, string $label, string $after, array $extra = []) => ['section' => $section, 'label' => $label, 'before' => null, 'after' => $after, 'change' => 'info'] + $extra;
        return array_merge(Plan::base(), [
            'kind' => 'selftest',
            'summary' => 'Preview window test',
            'diff' => [
                $info('Test', 'Preview window', 'works'),
                $ga4Row,
                $info('Configuration', 'Google Ads API version', Cfg::adsApiVersion()),
                $info('Configuration', 'Allowed URL domains', implode(', ', Cfg::allowedUrlHosts())),
                // Rows that exercise the PMax preview look (counter, note, warning, campaign state); no image, because selftest has no blob.
                ...$toolRows,
                $info('Appearance', 'Text with counter', 'Max 12 children per group · 24/30'),
                $info('Appearance', 'Row with a note', 'Sample value', ['note' => 'Sample note under the row']),
                $info('Appearance', 'Warning example', 'This is how a row marked as dangerous looks', ['level' => 'danger']),
            ],
            'campaignState' => 'PAUSED', // NOTE: PlanRenderer compares this to Text::stateLabel() output; keep them in sync
            'steps' => [],
            'warnings' => [],
            'validation' => ['ok' => true, 'errors' => []],
            'beforeFingerprint' => Json::fingerprint(['selftest' => true]),
        ]);
    }

    // ---- conversion tag data ----

    /** The sentence "Enter them at …" or an empty string when the URL is not configured. */
    public static function tagSettingsHint(): string
    {
        $u = Cfg::conversionTagSettingsUrl();
        return $u !== '' ? " Enter them at $u" : '';
    }

    public static function conversionInstruction(string $conversionId, string $label): string
    {
        return "Conversion ID: $conversionId, label: $label." . self::tagSettingsHint();
    }

    /** Whether this is the conversion action named in the config: by ID or by name (shared rule in Lp2\ConversionTag). */
    public static function isConfiguredConversionAction(?array $a): bool
    {
        return \App\Lp2\ConversionTag::isConfigured($a);
    }

    /** Conversion action tag data (shared implementation in Lp2\ConversionTag). */
    public static function findConversionTag(?string $actionId = null): array
    {
        return \App\Lp2\ConversionTag::find($actionId);
    }

    /** Old plan: create a conversion action from the config (name = conversion_action_name). */
    public static function conversionActionPlan(array $existing): array
    {
        $name = Cfg::conversionActionName();
        $errors = [];
        foreach ($existing as $a) {
            if (trim((string) ($a['name'] ?? '')) === $name && ($a['status'] ?? null) !== 'REMOVED') {
                $errors[] = 'A conversion action with this name already exists';
                break;
            }
        }
        $create = [
            'name' => $name, 'type' => 'WEBPAGE', 'category' => 'SUBMIT_LEAD_FORM', 'status' => 'ENABLED', 'countingType' => 'ONE_PER_CLICK',
            'primaryForGoal' => true, 'clickThroughLookbackWindowDays' => 30,
            'valueSettings' => ['defaultValue' => 0, 'alwaysUseDefaultValue' => true],
        ];
        $row = fn(string $label, string $after) => ['section' => 'Conversion', 'label' => $label, 'before' => null, 'after' => $after, 'change' => 'add'];
        return Undo::attach(array_merge(Plan::base(), [
            'kind' => 'conversion_action',
            'summary' => "New conversion action \"{$name}\"",
            'diff' => [$row('Name', $name), $row('Type', 'Website (form lead submission)'), $row('Counting', 'One per click, 30-day window'), $row('Value', 'PLN 0 (fixed)')],
            'steps' => [['title' => 'New conversion action', 'operations' => [['conversionActionOperation' => ['create' => $create]]]]],
            'warnings' => [],
            'validation' => ['ok' => !$errors, 'errors' => $errors],
            'beforeFingerprint' => Json::fingerprint(['conversionAction' => $name, 'existing' => array_map(fn($a) => $a['name'] ?? null, $existing)]),
        ]));
    }

    // ---- campaign locations (preview) ----

    /** Readable campaign location names (resource_name -> canonical name), fetched while preparing the plan. @return array<string,string> */
    public static function resolveGeoNames(array $snapshot): array
    {
        $rns = [];
        foreach ($snapshot['locations'] ?? [] as $l) {
            $g = $l['campaignCriterion']['location']['geoTargetConstant'] ?? null;
            if (is_string($g) && preg_match(self::GEO_RN, $g)) {
                $rns[$g] = true;
            }
        }
        if (!$rns) {
            return [];
        }
        $in = implode(', ', array_map(fn($r) => "'$r'", array_keys($rns)));
        $out = [];
        foreach (Ads::search("SELECT geo_target_constant.resource_name, geo_target_constant.canonical_name FROM geo_target_constant WHERE geo_target_constant.resource_name IN ($in)") as $r) {
            if (!empty($r['geoTargetConstant']['resourceName']) && !empty($r['geoTargetConstant']['canonicalName'])) {
                $out[$r['geoTargetConstant']['resourceName']] = $r['geoTargetConstant']['canonicalName'];
            }
        }
        return $out;
    }

    // ---- pause ----

    private static function pauseBase(array $camp, string $fingerprint): array
    {
        $rn = $camp['resourceName'] ?? 'customers/' . Cfg::customerId() . '/campaigns/' . ($camp['id'] ?? '');
        return array_merge(Plan::base(), [
            'kind' => 'pause', 'campaignId' => (string) ($camp['id'] ?? ''),
            'steps' => [['title' => 'Pause campaign', 'operations' => [['campaignOperation' => ['update' => ['resourceName' => $rn, 'status' => 'PAUSED'], 'updateMask' => 'status']]]]],
            'beforeFingerprint' => $fingerprint,
        ]);
    }

    private static function pauseCommon(array $camp): array
    {
        $errors = [];
        if (($camp['status'] ?? null) === 'PAUSED') {
            $errors[] = 'The campaign is already paused';
        }
        if (($camp['status'] ?? null) === 'REMOVED') {
            $errors[] = 'The campaign has been removed';
        }
        return [
            'summary' => 'Pause campaign "' . ($camp['name'] ?? '') . '"',
            'diff' => [['section' => 'Campaign', 'label' => 'Status', 'before' => $camp['status'] ?? null, 'after' => 'PAUSED', 'change' => 'change']],
            'warnings' => [], 'validation' => ['ok' => !$errors, 'errors' => $errors],
        ];
    }

    /** Pause of a Search campaign (snapshot from CampaignSnapshot::load). */
    public static function pausePlan(array $snapshot): array
    {
        $camp = $snapshot['campaign'] ?? [];
        return Undo::attach(self::pauseBase($camp, CampaignSnapshot::fingerprint($snapshot)) + self::pauseCommon($camp), $snapshot);
    }

    /** Pause of a Performance Max campaign: fingerprint of the campaign and asset groups; the loader picks the channel type from the plan on approval. */
    public static function pmaxPausePlan(array $snapshot): array
    {
        $camp = $snapshot['campaign'] ?? [];
        $base = self::pauseBase($camp, PmaxSnapshot::fingerprint($snapshot, ['campaign', 'assetGroups']));
        return Undo::attach(array_merge($base, ['channel' => 'PERFORMANCE_MAX'], self::pauseCommon($camp)), $snapshot);
    }
}
