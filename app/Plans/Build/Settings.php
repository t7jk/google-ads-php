<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Ads\Time;
use App\Plans\{Cfg, Ops, Plan, Undo};
use App\Plans\Snapshot\PmaxSnapshot;

/**
 * Plan for changing the settings of a SEARCH or Performance Max campaign (port of build-settings.ts): dates, location option, locations
 * (add, remove, exclude, radius) and asset automation. Input: the prepare_campaign_settings array + PmaxSnapshot snapshot + geo suggestions.
 */
final class Settings
{
    /** Automation types available in a SEARCH campaign. */
    private const SEARCH_AUTOMATION = ['FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION', 'TEXT_ASSET_AUTOMATION'];
    /**
     * Automation types treated as OPTED_IN in PMax when the campaign has no entry for them.
     * Source: Google Ads API AssetAutomationTypeEnum (v21) https://developers.google.com/google-ads/api/reference/rpc/v21/AssetAutomationTypeEnum.AssetAutomationType
     * - FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION, TEXT_ASSET_AUTOMATION, GENERATE_ENHANCED_YOUTUBE_VIDEOS, GENERATE_IMAGE_ENHANCEMENT: documented as opted in by default for PMax.
     * - GENERATE_IMAGE_EXTRACTION is NOT listed: per the docs it "defaults to the account-level Dynamic Image Extension setting", so we cannot assume on.
     * - AUTOMATED_VIDEO_CRAWL exists in the enum, but the docs state no default, so it is not assumed on either.
     */
    private const PMAX_DEFAULT_ON = ['FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION', 'TEXT_ASSET_AUTOMATION', 'GENERATE_ENHANCED_YOUTUBE_VIDEOS', 'GENERATE_IMAGE_ENHANCEMENT'];
    private const FINAL = 'FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION';
    private const TEXT = 'TEXT_ASSET_AUTOMATION';
    private const GEO_LABEL = ['PRESENCE' => 'Presence: people in the locations', 'PRESENCE_OR_INTEREST' => 'Presence or interest'];
    private const IN = 'OPTED_IN';
    private const OUT = 'OPTED_OUT';

    /** Today's calendar date in Europe/Warsaw (YYYY-MM-DD). */
    public static function warsawToday(\DateTimeInterface $now): string
    {
        return Time::dateInZone($now, 'Europe/Warsaw');
    }

    private static function validDate(string $s): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && $d !== false && $d->format('Y-m-d') === $s;
    }

    /** Groups the suggest result by searched name (case-insensitive). @return array<string, list<array>> */
    // Matching on searchTerm is case-insensitive: suggest returns candidates in one list, while the plan needs them per entered name.
    public static function groupGeoByName(array $names, array $cands): array
    {
        $out = [];
        foreach ($names as $n) {
            $out[$n] = array_values(array_filter($cands, fn($c) => mb_strtolower($c['searchTerm']) === mb_strtolower($n)));
        }
        return $out;
    }

    /** Names that need location suggestions (for the suggest call). @return list<string> */
    public static function locationNamesToResolve(array $req): array
    {
        $l = $req['locations'] ?? [];
        $names = [];
        foreach (['add', 'exclude'] as $k) {
            foreach ($l[$k] ?? [] as $a) {
                if (empty($a['geoTargetConstantId']) && !empty($a['name'])) {
                    $names[] = $a['name'];
                }
            }
        }
        foreach (['remove', 'unexclude'] as $k) {
            foreach ($l[$k] ?? [] as $x) {
                if (!preg_match('/^\d+$/', (string) $x)) {
                    $names[] = (string) $x;
                }
            }
        }
        return array_values(array_unique($names));
    }

    private static function cand(array $c): string
    {
        return "{$c['canonicalName']} ({$c['targetType']}, {$c['id']})";
    }

    /** @return array{id: string, label: string, canonicalName: string}|null */
    /**
     * Resolves a location from the request to a geoTargetConstant: by explicit id (digits only, which guards against injection into resourceName)
     * or by name using the geo suggestions. Fail-closed: no candidate or ambiguity (several hits without exactly one matching city)
     * = an error listing the candidates; we never guess a location, because a wrong location means spend in the wrong place.
     */
    private static function resolveLocation(array $item, array $geo, Draft $d): ?array
    {
        if (!empty($item['geoTargetConstantId'])) {
            $id = (string) $item['geoTargetConstantId'];
            if (!preg_match('/^\d+$/', $id)) {
                $d->error("Invalid geoTargetConstantId \"{$id}\"");
                return null;
            }
            $known = null;
            foreach ($geo as $list) {
                foreach ($list as $c) {
                    if ($c['id'] === $id) {
                        $known = $c;
                        break 2;
                    }
                }
            }
            $name = $known['canonicalName'] ?? ($item['name'] ?? null);
            return ['id' => $id, 'label' => $name ?? "geoTargetConstants/$id", 'canonicalName' => $name ?? ''];
        }
        if (empty($item['name'])) {
            $d->error('A location requires name or geoTargetConstantId');
            return null;
        }
        $cs = $geo[$item['name']] ?? [];
        if (!$cs) {
            $d->error("Location \"{$item['name']}\" not found");
            return null;
        }
        $pick = count($cs) === 1 ? $cs[0] : null;
        if (!$pick) {
            $exact = array_values(array_filter($cs, fn($c) => mb_strtolower($c['targetType']) === 'city' && mb_strtolower($c['name']) === mb_strtolower($item['name'])));
            $pick = count($exact) === 1 ? $exact[0] : null;
        }
        if (!$pick) {
            $d->error("Location \"{$item['name']}\" is ambiguous: " . implode('; ', array_map([self::class, 'cand'], $cs)) . '; give a geoTargetConstantId');
            return null;
        }
        return ['id' => $pick['id'], 'label' => $pick['canonicalName'], 'canonicalName' => $pick['canonicalName']];
    }

    /** @param array $geo result of groupGeoByName; $s: PmaxSnapshot::load */
    /**
     * Builds the plan for the prepare_campaign_settings tool. Order: campaign type validation (SEARCH/PMax only) -> dates, location option,
     * automation (one campaign update operation with an updateMask) -> location criteria operations (separate create/remove operations).
     * $now is injected for tests (default Clock::now()); "today" is computed in the Europe/Warsaw time zone.
     */
    public static function build(array $req, array $s, array $geo, ?\DateTimeImmutable $now = null): array
    {
        $now ??= \App\Plans\Clock::now();
        $d = new Draft();
        $camp = $s['campaign'] ?? [];
        $campRn = $camp['resourceName'] ?? 'customers/' . Cfg::customerId() . "/campaigns/{$req['campaignId']}";
        $channel = (string) ($camp['advertisingChannelType'] ?? '');
        $isPmax = $channel === 'PERFORMANCE_MAX';
        if ($channel !== 'SEARCH' && !$isPmax) {
            $d->error('Settings can only be changed on a SEARCH or Performance Max campaign; this one has type ' . ($channel ?: 'unknown'));
        }
        if (($camp['status'] ?? null) === 'REMOVED') {
            $d->error('The campaign has been removed');
        }
        $cu = ['resourceName' => $campRn];
        $mask = [];
        $endIsOpenOrFuture = false;
        self::dates($req, $camp, Settings::warsawToday($now), $d, $cu, $mask, $endIsOpenOrFuture);
        self::geoType($req, $camp, $d, $cu, $mask);
        self::automation($req, $camp, $channel, $isPmax, $d, $cu, $mask);

        if (($camp['status'] ?? null) === 'ENABLED' && ($camp['servingStatus'] ?? null) === 'ENDED' && $endIsOpenOrFuture && in_array('endDateTime', $mask, true)) {
            $d->error('The campaign is enabled and has ended; extending the end date would resume serving. Pause it first (prepare_campaign_pause).');
        } elseif (($camp['status'] ?? null) === 'ENABLED') {
            $d->warn('The campaign is enabled; the change takes effect immediately');
        }
        if ($mask) {
            $d->op(['campaignOperation' => ['update' => $cu, 'updateMask' => implode(',', $mask)]]);
        }
        self::locations($req, $s, $campRn, $geo, $d);
        if (!$d->ops && !$d->errors) {
            $d->error('Nothing to change');
        }
        return Undo::attach(array_merge(Plan::base($now), [
            'kind' => 'campaign_settings', 'campaignId' => $req['campaignId'],
            'summary' => 'Campaign settings "' . ($camp['name'] ?? '') . '"',
            'steps' => $d->ops ? [['title' => 'Campaign settings', 'operations' => $d->ops]] : [],
            'beforeFingerprint' => PmaxSnapshot::fingerprint($s, ['campaign', 'criteria']),
        ], $d->common()), $s);
    }

    /**
     * Start date (future only) and end date (YYYY-MM-DD, not in the past, not before the start, or "none").
     * The API uses full date-times: start = 00:00:00, end = 23:59:59 (the whole end day counts as part of the campaign).
     * Sets $endIsOpenOrFuture; build() uses it to block "resurrecting" an ended, enabled campaign.
     * Clearing the end date: per the Google Ads API docs (Campaign.end_date_time) "to set an existing campaign to run indefinitely,
     * clear this field", i.e. the field goes into the update mask with no value. (Older campaigns may carry the 2037-12-30 sentinel
     * that the legacy API used for "no end"; it is treated as "no end date" when reading.)
     */
    private static function dates(array $req, array $camp, string $today, Draft $d, array &$cu, array &$mask, bool &$endIsOpenOrFuture): void
    {
        $curStart = !empty($camp['startDateTime']) ? (string) $camp['startDateTime'] : null;
        $curEndRaw = !empty($camp['endDateTime']) ? (string) $camp['endDateTime'] : null;
        $curEnd = $curEndRaw && str_starts_with($curEndRaw, '2037-12-30') ? null : $curEndRaw;
        $dateOnly = fn(?string $v) => $v ? substr($v, 0, 10) : 'none';
        $newStartDate = null;
        if (isset($req['startDate'])) {
            $sd = (string) $req['startDate'];
            if (!self::validDate($sd)) {
                $d->error('Start date has a wrong format (expected YYYY-MM-DD)');
            } elseif ($sd <= $today) {
                $d->error('Start date must be in the future');
            } else {
                $newStartDate = $sd;
                $val = "$sd 00:00:00";
                if ($val !== $curStart) {
                    $cu['startDateTime'] = $val;
                    $mask[] = 'startDateTime';
                    $d->add('Settings', 'Start date', $dateOnly($curStart), $sd, 'change');
                }
            }
        }
        if (!isset($req['endDate'])) {
            return;
        }
        $e = trim((string) $req['endDate']);
        // 'none' is the only accepted value for "no end date".
        if (in_array(mb_strtolower($e), ['none'], true)) {
            $endIsOpenOrFuture = true;
            if ($curEndRaw !== null) {
                $mask[] = 'endDateTime'; // in the mask, absent from $cu = clear the field
                $d->add('Settings', 'End date', $dateOnly($curEnd), 'none', 'change');
            }
        } elseif (!self::validDate($e)) {
            $d->error('End date has a wrong format (expected YYYY-MM-DD or "none")');
        } elseif ($e < $today) {
            $d->error('End date cannot be in the past');
        } else {
            $endIsOpenOrFuture = true;
            $startRef = $newStartDate ?? ($curStart ? substr($curStart, 0, 10) : null);
            if ($startRef && $e < $startRef) {
                $d->error('End date is earlier than the start date');
            }
            $val = "$e 23:59:59";
            if ($val !== $curEnd) {
                $cu['endDateTime'] = $val;
                $mask[] = 'endDateTime';
                $d->add('Settings', 'End date', $dateOnly($curEnd), $e, 'change');
            }
        }
    }

    /** Location option (positive geo target type): PRESENCE vs PRESENCE_OR_INTEREST; changed only when it differs from the current one. */
    private static function geoType(array $req, array $camp, Draft $d, array &$cu, array &$mask): void
    {
        if (!isset($req['geoTargetType'])) {
            return;
        }
        $cur = $camp['geoTargetTypeSetting']['positiveGeoTargetType'] ?? null;
        if ($req['geoTargetType'] !== $cur) {
            $cu['geoTargetTypeSetting'] = ['positiveGeoTargetType' => $req['geoTargetType']];
            $mask[] = 'geoTargetTypeSetting.positiveGeoTargetType';
            $d->add('Settings', 'Location option', $cur ? (self::GEO_LABEL[$cur] ?? $cur) : null, self::GEO_LABEL[$req['geoTargetType']], 'change');
        }
    }

    /**
     * Asset automation (assetAutomationSettings). The API replaces the whole list, so we send all entries with the changes applied.
     * No entry means the default state: in PMax most automations are on by default (PMAX_DEFAULT_ON), in SEARCH they are off.
     * An API rule enforced here fail-closed: text asset automation cannot be turned off while final URL expansion is on.
     */
    private static function automation(array $req, array $camp, string $channel, bool $isPmax, Draft $d, array &$cu, array &$mask): void
    {
        if (!isset($req['assetAutomation'])) {
            return;
        }
        $entries = array_map(fn($x) => ['assetAutomationType' => $x['assetAutomationType'], 'assetAutomationStatus' => $x['assetAutomationStatus']], $camp['assetAutomationSettings'] ?? []);
        $eff = function (string $t) use (&$entries, $channel) {
            foreach ($entries as $x) {
                if ($x['assetAutomationType'] === $t) {
                    return $x['assetAutomationStatus'];
                }
            }
            return $channel === 'PERFORMANCE_MAX' && in_array($t, self::PMAX_DEFAULT_ON, true) ? self::IN : self::OUT;
        };
        $onOff = fn(bool $v) => $v ? 'on' : 'off';
        $changed = false;
        foreach ($req['assetAutomation'] as $t => $want) {
            if (!$isPmax && !in_array($t, self::SEARCH_AUTOMATION, true)) {
                $d->error("Automation $t is not available on a SEARCH campaign");
                continue;
            }
            $target = $want ? self::IN : self::OUT;
            $before = $eff($t);
            if ($before === $target) {
                continue;
            }
            $found = false;
            foreach ($entries as &$x) {
                if ($x['assetAutomationType'] === $t) {
                    $x['assetAutomationStatus'] = $target;
                    $found = true;
                }
            }
            unset($x);
            if (!$found) {
                $entries[] = ['assetAutomationType' => $t, 'assetAutomationStatus' => $target];
            }
            $changed = true;
            $d->add('Asset automation', $t, $onOff($before === self::IN), $onOff((bool) $want), 'change');
        }
        if ($eff(self::FINAL) === self::IN && $eff(self::TEXT) === self::OUT) {
            $d->error('Text asset automation cannot be turned off while final URL expansion is on; turn off FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION first');
        }
        if ($changed) {
            $cu['assetAutomationSettings'] = $entries;
            $mask[] = 'assetAutomationSettings';
        }
    }

    /**
     * Locations: add (geoTargetConstant or a radius around an address/city), exclude (negative=true), remove and un-exclude
     * (by criterionId or by name). Works on the criteria from the snapshot (types LOCATION, PROXIMITY, LOCATION_GROUP).
     */
    private static function locations(array $req, array $s, string $campRn, array $geo, Draft $d): void
    {
        $locRows = array_values(array_filter($s['criteria'], fn($r) => in_array($r['campaignCriterion']['type'] ?? null, ['LOCATION', 'PROXIMITY', 'LOCATION_GROUP'], true)));
        $loc = $req['locations'] ?? [];
        foreach ($loc['add'] ?? [] as $a) {
            $r = self::resolveLocation($a, $geo, $d);
            if (!$r) {
                continue;
            }
            if (isset($a['radiusKm']) && trim(explode(',', $r['canonicalName'])[0]) === '') {
                $d->error('A radius around a location requires a city name; give name (together with geoTargetConstantId or instead of it)');
                continue;
            }
            if (isset($a['radiusKm'])) {
                $d->op(Ops::proximityAddress($campRn, $r['canonicalName'], (float) $a['radiusKm']));
                $d->add('Locations', $r['label'], null, "radius {$a['radiusKm']} km", 'add');
            } else {
                $d->op(['campaignCriterionOperation' => ['create' => ['campaign' => $campRn, 'location' => ['geoTargetConstant' => "geoTargetConstants/{$r['id']}"]]]]);
                $d->add('Locations', $r['label'], null, 'added', 'add');
            }
        }
        foreach ($loc['exclude'] ?? [] as $a) {
            $r = self::resolveLocation($a, $geo, $d);
            if (!$r) {
                continue;
            }
            $d->op(['campaignCriterionOperation' => ['create' => ['campaign' => $campRn, 'negative' => true, 'location' => ['geoTargetConstant' => "geoTargetConstants/{$r['id']}"]]]]);
            $d->add('Locations', $r['label'], null, 'excluded', 'add');
        }
        self::removeRows($loc['remove'] ?? [], false, 'removed', $locRows, $geo, $d);
        self::removeRows($loc['unexclude'] ?? [], true, 'exclusion removed', $locRows, $geo, $d);
    }

    /**
     * Removes location criteria given by criterion id or by name. $negative distinguishes "remove an added location" from "remove an exclusion":
     * the same location can only exist in one of these forms, and a mismatch is an error (not a silent skip).
     */
    private static function removeRows(array $refs, bool $negative, string $after, array $locRows, array $geo, Draft $d): void
    {
        foreach ($refs as $ref) {
            $ref = (string) $ref;
            if (preg_match('/^\d+$/', $ref)) {
                $rows = array_filter($locRows, fn($r) => (string) $r['campaignCriterion']['criterionId'] === $ref);
            } else {
                $cs = $geo[$ref] ?? [];
                if (!$cs) {
                    $d->error("Location \"{$ref}\" not found");
                    continue;
                }
                $ids = array_map(fn($c) => "geoTargetConstants/{$c['id']}", $cs);
                $rows = array_filter($locRows, fn($r) => in_array((string) ($r['campaignCriterion']['location']['geoTargetConstant'] ?? ''), $ids, true));
            }
            $rows = array_values(array_filter($rows, fn($r) => !empty($r['campaignCriterion']['negative']) === $negative));
            if (!$rows) {
                $d->error('The campaign has no ' . ($negative ? 'excluded' : 'added') . " location \"{$ref}\"");
                continue;
            }
            foreach ($rows as $r) {
                $c = $r['campaignCriterion'];
                $d->op(['campaignCriterionOperation' => ['remove' => $c['resourceName']]]);
                if (preg_match('/^\d+$/', $ref)) {
                    $name = $c['location']['geoTargetConstant'] ?? $c['resourceName'];
                } else {
                    $name = $ref;
                    foreach ($geo[$ref] ?? [] as $g) {
                        if ("geoTargetConstants/{$g['id']}" === ($c['location']['geoTargetConstant'] ?? null)) {
                            $name = $g['canonicalName'];
                            break;
                        }
                    }
                }
                $d->add('Locations', (string) $name, $negative ? 'excluded' : 'added', $after, 'remove');
            }
        }
    }
}
