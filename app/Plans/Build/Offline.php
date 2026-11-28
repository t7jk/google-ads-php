<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Plans\{Cfg, GuardOps, Json, Plan};

/**
 * Plan for uploading offline conversions to Google Ads (uploadClickConversions) from lp2 leads (port of build-offline.ts).
 * Rules: only gclid/wbraid (gbraid is not sent), ad_user_data consent = granted, click younger than 90 days, not yet sent
 * (OfflineLog), at most GuardOps::MAX_CLICK_CONVERSIONS per plan. The preview contains no click identifiers. An upload cannot be undone.
 * Execution and validation are done by Plans\OfflineRun (the 'offline_conversions' kind has its own 'run'/'validate').
 */
final class Offline
{
    public const NOT_UNDOABLE = 'Cannot be undone: uploaded conversions cannot be withdrawn via the API (only adjustments/retractions in a separate upload)';
    public const CLICK_MAX_AGE_DAYS = 90;
    private const MAX_DIFF_ROWS = 20;
    private const SKIP_ROWS = [
        'noConsent' => 'skipped: no consent (ad_user_data other than granted)',
        'noClickId' => 'skipped: no click identifier (gclid/wbraid; gbraid is not sent)',
        'tooOld' => 'skipped: click older than 90 days',
        'badTime' => 'skipped: lead time is not after the click',
        'alreadySent' => 'skipped: already sent (upload log)',
        'overLimit' => 'deferred: over the limit of 200 per plan (the next plan will send the rest)',
    ];

    /** Time in Europe/Warsaw in Google's format: "yyyy-mm-dd hh:mm:ss+01:00" / "+02:00" (with daylight saving time). */
    public static function warsawDateTime(\DateTimeInterface $d, string $tz = 'Europe/Warsaw'): string
    {
        return \DateTimeImmutable::createFromInterface($d)->setTimezone(new \DateTimeZone($tz))->format('Y-m-d H:i:sP');
    }

    /**
     * Picks the UPLOAD_CLICKS action: the one given by ID, or the only active one in the account.
     * @param list<array{id:string,name:string,type:string,status:string}> $actions
     * @return array{action: array}|array{error: string}
     */
    public static function pickUploadAction(array $actions, ?string $wantedId): array
    {
        $hint = 'Create it with prepare_conversion_action (mode: create, type: UPLOAD_CLICKS; it is not a primary conversion by default, so a submission is not counted twice with the tag).';
        if ($wantedId !== null && $wantedId !== '') {
            $a = current(array_filter($actions, fn($x) => $x['id'] === $wantedId)) ?: null;
            if (!$a) {
                return ['error' => "No conversion action with ID $wantedId"];
            }
            if ($a['type'] !== 'UPLOAD_CLICKS') {
                return ['error' => "Action {$a['name']} ({$a['id']}) has type {$a['type']}, but offline conversion upload requires type UPLOAD_CLICKS. $hint"];
            }
            return $a['status'] !== 'ENABLED' ? ['error' => "Action {$a['name']} ({$a['id']}) has status {$a['status']}, ENABLED is required"] : ['action' => $a];
        }
        $ups = array_values(array_filter($actions, fn($a) => $a['type'] === 'UPLOAD_CLICKS' && $a['status'] === 'ENABLED'));
        if (!$ups) {
            return ['error' => "The account has no active conversion action of type UPLOAD_CLICKS. $hint"];
        }
        if (count($ups) > 1) {
            return ['error' => 'There are several UPLOAD_CLICKS actions (' . implode('; ', array_map(fn($a) => "{$a['name']} {$a['id']}", $ups)) . '); give conversionActionId'];
        }
        return ['action' => $ups[0]];
    }

    /**
     * Splits leads into eligible and skipped (with the reason).
     * @param list<array> $leads LeadsClient::forUpload @param array<string,true> $sent OfflineLog::sentIds
     * @return array{eligible: list<array{lead:array,dateTime:string}>, skipped: array<string,int>}
     */
    public static function classify(array $leads, array $sent, \DateTimeImmutable $now): array
    {
        $skipped = array_fill_keys(array_keys(self::SKIP_ROWS), 0);
        $eligible = [];
        foreach ($leads as $lead) {
            $reason = self::skipReason($lead, $sent, $now);
            if ($reason !== null) {
                $skipped[$reason]++;
                continue;
            }
            $eligible[] = ['lead' => $lead, 'dateTime' => self::warsawDateTime(new \DateTimeImmutable($lead['createdAt']))];
        }
        usort($eligible, fn($a, $b) => strcmp($a['lead']['createdAt'], $b['lead']['createdAt']));
        $max = GuardOps::MAX_CLICK_CONVERSIONS;
        if (count($eligible) > $max) {
            $skipped['overLimit'] = count($eligible) - $max;
            $eligible = array_slice($eligible, 0, $max);
        }
        return ['eligible' => $eligible, 'skipped' => $skipped];
    }

    /** A key from SKIP_ROWS, or null when the lead is eligible. */
    private static function skipReason(array $lead, array $sent, \DateTimeImmutable $now): ?string
    {
        if (isset($sent[$lead['id']])) {
            return 'alreadySent';
        }
        if (!in_array($lead['clickType'], ['gclid', 'wbraid'], true) || $lead['clickId'] === '') {
            return 'noClickId';
        }
        if ($lead['adUserData'] !== 'granted') {
            return 'noConsent';
        }
        $created = strtotime($lead['createdAt']);
        $click = $lead['clickTs'] !== '' ? strtotime($lead['clickTs']) : $created;
        if ($created === false || $click === false || $created <= $click || $created > $now->getTimestamp()) {
            return 'badTime';
        }
        return $now->getTimestamp() - $click > self::CLICK_MAX_AGE_DAYS * 86400 ? 'tooOld' : null;
    }

    /** @param array{id:string,name:string,type:string,status:string} $action @param list<array> $leads @param array<string,true> $sent */
    public static function plan(array $action, array $leads, array $sent, \DateTimeImmutable $now): array
    {
        ['eligible' => $eligible, 'skipped' => $skipped] = self::classify($leads, $sent, $now);
        $resource = 'customers/' . Cfg::customerId() . "/conversionActions/{$action['id']}";
        $operations = array_map(fn($e) => ['clickConversionOperation' => [
            $e['lead']['clickType'] => $e['lead']['clickId'], 'conversionAction' => $resource, 'conversionDateTime' => $e['dateTime'],
            'orderId' => $e['lead']['id'], 'consent' => ['adUserData' => 'GRANTED'],
        ]], $eligible);
        $warnings = [self::NOT_UNDOABLE];
        if ($skipped['noConsent']) {
            $warnings[] = "{$skipped['noConsent']} leads were skipped because ad_user_data consent is not granted (without a consent banner lp2 stores \"unknown\").";
        }
        if (!$eligible) {
            $warnings[] = 'Nothing to upload: no lead meets the conditions (consent granted, gclid/wbraid, click younger than 90 days, not yet sent).';
        }
        return array_merge(Plan::base($now), [
            'kind' => 'offline_conversions',
            'summary' => $eligible ? 'Offline conversions: upload ' . count($eligible) . " to action \"{$action['name']}\"" : 'Offline conversions: nothing to upload',
            'diff' => self::diff($action, $eligible, $skipped), 'steps' => [['title' => 'Offline conversion upload (uploadClickConversions)', 'operations' => $operations]],
            'warnings' => $warnings, 'validation' => ['ok' => true, 'errors' => []],
            'beforeFingerprint' => self::fingerprint($action['id'], array_map(fn($e) => $e['lead']['id'], $eligible)),
            'undo' => ['ops' => [], 'notUndoable' => [self::NOT_UNDOABLE], 'notes' => []],
        ]);
    }

    /** @param list<string> $orderIds */
    public static function fingerprint(string $actionId, array $orderIds): string
    {
        sort($orderIds, SORT_STRING);
        return Json::fingerprint(['action' => $actionId, 'orders' => $orderIds]);
    }

    private static function diff(array $action, array $eligible, array $skipped): array
    {
        $info = fn(string $section, string $label, ?string $before, ?string $after, string $change, array $extra = []) => ['section' => $section, 'label' => $label, 'before' => $before, 'after' => $after, 'change' => $change] + $extra;
        $diff = [
            $info('Upload', 'Conversion action', null, "{$action['name']} ({$action['id']}, {$action['type']})", 'info'),
            $info('Upload', 'To upload', '0', (string) count($eligible), $eligible ? 'add' : 'info'),
        ];
        foreach (self::SKIP_ROWS as $k => $label) {
            if ($skipped[$k]) {
                $diff[] = $info('Skipped', $label, null, (string) $skipped[$k], 'info', $k === 'noConsent' || $k === 'overLimit' ? ['level' => 'warn'] : []);
            }
        }
        foreach (array_slice($eligible, 0, self::MAX_DIFF_ROWS) as $e) {
            $diff[] = $info('Conversions', "lead {$e['lead']['id']}", null, "{$e['lead']['clickType']}, {$e['dateTime']}, consent: granted", 'add');
        }
        if (count($eligible) > self::MAX_DIFF_ROWS) {
            $diff[] = $info('Conversions', '… and ' . (count($eligible) - self::MAX_DIFF_ROWS) . ' more', null, null, 'info');
        }
        return $diff;
    }
}
