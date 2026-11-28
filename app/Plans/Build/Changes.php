<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Ads\{Money, Slug};
use App\Plans\{Cfg, Guard, Ops, Plan, RsaDiff, Undo};
use App\Plans\Snapshot\CampaignSnapshot;

/**
 * Plan for changes to an existing Search campaign (port of build-changes.ts): name, bidding, networks, URL parameters, budget, location,
 * campaign negatives, ad groups (keywords, negatives, RSA ads). Input: the array from the prepare_campaign_changes tool.
 */
final class Changes
{
    private const MATCH = ['EXACT' => 'exact', 'PHRASE' => 'phrase', 'BROAD' => 'broad'];
    private const BID = ['MANUAL_CPC' => 'Manual CPC', 'MAXIMIZE_CLICKS' => 'Maximize clicks', 'MAXIMIZE_CONVERSIONS' => 'Maximize conversions'];
    private const ST = ['ENABLED' => 'active', 'PAUSED' => 'paused'];

    private Draft $d;
    private string $cid;
    private string $campRn;
    private array $snap;
    private int $tmp = 0;
    private bool $noteSuffix = false;

    private function __construct(array $snap)
    {
        $this->d = new Draft();
        $this->snap = $snap;
        $this->cid = Cfg::customerId();
    }

    /**
     * Entry point: builds the plan from the MCP request and the campaign snapshot (CampaignSnapshot).
     * Returns the plan array (Plan::base + Draft::common + undo data). Sends nothing to Google Ads;
     * the Executor runs it after approval; validation errors go to $d->errors and block approval.
     */
    public static function build(array $req, array $snapshot): array
    {
        $b = new self($snapshot);
        return $b->plan($req);
    }

    /** Assembles the plan from its parts: campaign fields, budget, location, negatives, ad groups; guards at the end (no ENABLE, no empty plan). */
    private function plan(array $req): array
    {
        $d = $this->d;
        $camp = $this->snap['campaign'] ?? [];
        $this->campRn = $camp['resourceName'] ?? "customers/{$this->cid}/campaigns/{$req['campaignId']}";
        $this->campaignFields($req, $camp);
        $this->budget($req);
        $this->location($req);
        $this->campaignNegatives($req);
        foreach ($req['adGroups'] ?? [] as $g) {
            $this->adGroup($g);
        }
        if ($this->noteSuffix) {
            $d->diff[] = RsaDiff::suffixNoteRow('Ads');
        }
        if (!$d->ops && !$d->errors) {
            $d->error('No changes to apply');
        }
        $d->error(...Guard::assertNoEnable($d->ops));
        if (($camp['status'] ?? null) === 'ENABLED' && $d->ops) {
            $d->warn('The campaign is enabled; the changes take effect immediately after approval.');
        }
        // Undo: an ad (adOperation) has no resourceName in the snapshot, and the campaign negatives query does not select the negative field.
        $extras = [];
        foreach ($this->snap['ads'] as $r) {
            if (!empty($r['adGroupAd']['ad']['id'])) {
                $extras[] = $r['adGroupAd']['ad'] + ['resourceName' => "customers/{$this->cid}/ads/{$r['adGroupAd']['ad']['id']}"];
            }
        }
        foreach ($this->snap['negatives'] as $r) {
            if (!empty($r['campaignCriterion']['resourceName'])) {
                $extras[] = $r['campaignCriterion'] + ['negative' => true];
            }
        }
        $name = $camp['name'] ?? $req['campaignId'];
        return Undo::attach(array_merge(Plan::base(), [
            'kind' => 'campaign_changes', 'campaignId' => $req['campaignId'],
            'summary' => "Changes to campaign \"{$name}\": " . \App\Ads\Plural::withCount(count($d->ops), 'operation', 'operations', 'operations'),
            'steps' => [['title' => 'Campaign changes', 'operations' => $d->ops]],
            'beforeFingerprint' => CampaignSnapshot::fingerprint($this->snap),
        ], $d->common()), $this->snap, $extras);
    }

    /** Returns the URL parameter list with $key replaced/added; the rest stays (an urlCustomParameters updateMask replaces the whole list, so the full list must be sent). */
    private static function mergeParam(?array $existing, string $key, string $value): array
    {
        $kept = array_values(array_filter($existing ?? [], fn($p) => $p['key'] !== $key));
        return [...$kept, ['key' => $key, 'value' => $value]];
    }

    private static function paramValue(?array $params, string $key): ?string
    {
        foreach ($params ?? [] as $p) {
            if ($p['key'] === $key) {
                return (string) $p['value'];
            }
        }
        return null;
    }

    private static function kwLabel(array $k): string
    {
        return $k['text'] . ' (' . self::MATCH[$k['match']] . ')';
    }

    private static function matchOf(array $c): string
    {
        return self::MATCH[$c['keyword']['matchType'] ?? ''] ?? '';
    }

    /** Turns the API status into a readable label; an unknown status is returned unchanged (so no information is lost in the diff). */
    private static function status(mixed $v): ?string
    {
        return self::ST[(string) $v] ?? ($v ? (string) $v : null);
    }

    /**
     * Fields of the campaign itself (name, bidding strategy, networks, the {_kampania} parameter) in one update operation with an updateMask.
     * The mask contains only the fields actually changed, otherwise the API would reset the others.
     */
    private function campaignFields(array $req, array $camp): void
    {
        $d = $this->d;
        $cu = ['resourceName' => $this->campRn];
        $mask = [];
        if (isset($req['rename'])) {
            $cu['name'] = $req['rename'];
            $mask[] = 'name';
            $d->add('Campaign', 'Name', $camp['name'] ?? null, $req['rename'], 'change');
        }
        if (isset($req['bidding'])) {
            $this->bidding($req['bidding'], $camp, $cu, $mask);
        }
        if (isset($req['networks'])) {
            $n = $camp['networkSettings'] ?? [];
            // targetGoogleSearch is always true: turning off the main Google network is not allowed for a Search campaign.
            $cu['networkSettings'] = ['targetGoogleSearch' => true, 'targetSearchNetwork' => $req['networks']['searchPartners'], 'targetContentNetwork' => $req['networks']['display']];
            array_push($mask, 'networkSettings.targetGoogleSearch', 'networkSettings.targetSearchNetwork', 'networkSettings.targetContentNetwork');
            $yn = fn($v) => $v ? 'yes' : 'no';
            $d->add('Networks', 'Search partners', $yn($n['targetSearchNetwork'] ?? false), $yn($req['networks']['searchPartners']), 'change');
            $d->add('Networks', 'Display Network', $yn($n['targetContentNetwork'] ?? false), $yn($req['networks']['display']), 'change');
        }
        if (array_key_exists('campaignParam', $req)) {
            $slug = Slug::toParamSlug((string) $req['campaignParam']);
            if ($slug === '') {
                $d->error("The \"kampania\" parameter is empty after normalization: \"{$req['campaignParam']}\"");
            } else {
                $cu['urlCustomParameters'] = self::mergeParam($camp['urlCustomParameters'] ?? null, 'kampania', $slug);
                $mask[] = 'urlCustomParameters';
                $d->add('Parameters', '{_kampania}', self::paramValue($camp['urlCustomParameters'] ?? null, 'kampania'), $slug, 'change');
            }
        }
        if ($mask) {
            $d->op(['campaignOperation' => ['update' => $cu, 'updateMask' => implode(',', $mask)]]);
        }
    }

    /** Bidding strategy: fields and mask from Ops::biddingFields; the max CPC ceiling applies only to MAXIMIZE_CLICKS (with manual CPC the bid is on the ad group). */
    private function bidding(array $b, array $camp, array &$cu, array &$mask): void
    {
        $d = $this->d;
        if ($b['type'] === 'MANUAL_CPC' && isset($b['maxCpcPln'])) {
            $d->warn('maxCpcPln is ignored for manual CPC at campaign level; use defaultCpcPln on the ad group.');
        }
        $bf = Ops::biddingFields($b);
        $cu = array_merge($cu, $bf['fields']);
        array_push($mask, ...$bf['mask']);
        $ceil = $camp['targetSpend']['cpcBidCeilingMicros'] ?? null;
        $type = $camp['biddingStrategyType'] ?? null;
        $was = $type ? (self::BID[$type] ?? $type) . ($ceil ? ' (max CPC PLN ' . Money::microsToPln($ceil) . ')' : '') : null;
        $now = self::BID[$b['type']] . ($b['type'] === 'MAXIMIZE_CLICKS' && isset($b['maxCpcPln']) ? " (max CPC PLN {$b['maxCpcPln']})" : '');
        $d->add('Bidding', 'Bidding strategy', $was, $now, 'change');
    }

    /**
     * Daily budget change (PLN -> micros via Money::plnToMicros; PLN 1 = 1,000,000 micros).
     * Fail-closed: a shared budget or one missing from the snapshot is an error, because the change would affect other campaigns / there is nothing to change.
     */
    private function budget(array $req): void
    {
        if (!isset($req['dailyBudgetPln'])) {
            return;
        }
        $d = $this->d;
        $budget = $this->snap['budget'] ?? null;
        $d->error(...Guard::checkBudget((float) $req['dailyBudgetPln']));
        $before = isset($budget['amountMicros']) ? 'PLN ' . Money::microsToPln($budget['amountMicros']) . '/day' : null;
        if (!empty($budget['explicitlyShared'])) {
            $d->error('The budget is shared with other campaigns; change it in Google Ads.');
        } elseif (empty($budget['resourceName'])) {
            $d->error('The campaign budget is missing from the snapshot');
        } else {
            $d->op(['campaignBudgetOperation' => ['update' => ['resourceName' => $budget['resourceName'], 'amountMicros' => Money::plnToMicros((float) $req['dailyBudgetPln'])], 'updateMask' => 'amountMicros']]);
        }
        $d->add('Budget', 'Daily budget', $before, "PLN {$req['dailyBudgetPln']}/day", 'change');
    }

    /** Location change: removes all existing positive criteria (radius) and adds a new proximity; negative criteria (excluded places) stay. */
    private function location(array $req): void
    {
        if (!isset($req['location'])) {
            return;
        }
        $d = $this->d;
        ['lat' => $lat, 'lng' => $lng, 'radiusKm' => $km] = $req['location']['proximity'];
        $positive = array_values(array_filter($this->snap['locations'], fn($x) => empty($x['campaignCriterion']['negative'])));
        foreach ($positive as $r) {
            $d->op(['campaignCriterionOperation' => ['remove' => $r['campaignCriterion']['resourceName']]]);
        }
        $d->op(Ops::proximity($this->campRn, (float) $lat, (float) $lng, (float) $km));
        $old = implode(', ', array_map(fn($p) => "{$p['radius']} km", array_filter(array_map(fn($r) => $r['campaignCriterion']['proximity'] ?? null, $positive))));
        $d->add('Location', 'Radius', $old ?: null, "$km km around $lat, $lng", 'change');
    }

    /** Campaign-level negatives: additions from the request, removal only by a criterionId belonging to this campaign (checked in the snapshot). */
    private function campaignNegatives(array $req): void
    {
        $d = $this->d;
        foreach ($req['campaignNegatives']['add'] ?? [] as $k) {
            $d->op(Ops::campaignNegative($this->campRn, $k));
            $d->add('Negatives', 'Negative', null, self::kwLabel($k), 'add');
        }
        foreach ($req['campaignNegatives']['removeCriterionIds'] ?? [] as $id) {
            $row = $this->find($this->snap['negatives'], fn($n) => (string) $n['campaignCriterion']['criterionId'] === (string) $id);
            if (!$row) {
                $d->error("Negative $id does not belong to this campaign");
                continue;
            }
            $d->op(['campaignCriterionOperation' => ['remove' => $row['campaignCriterion']['resourceName']]]);
            $d->add('Negatives', 'Negative', $row['campaignCriterion']['keyword']['text'] . ' (' . self::matchOf($row['campaignCriterion']) . ')', null, 'remove');
        }
    }

    private function find(array $rows, callable $pred): ?array
    {
        foreach ($rows as $r) {
            if ($pred($r)) {
                return $r;
            }
        }
        return null;
    }

    private static function lastId(string $rn): string
    {
        $p = explode('/', $rn);
        return end($p);
    }

    /**
     * Ad group: an existing one (id from the snapshot, otherwise an error) or a new one. A new group gets a temporary resourceName with a negative id
     * (--$this->tmp), so that later operations (keywords, ads) can refer to it in the same mutate batch.
     */
    private function adGroup(array $g): void
    {
        $d = $this->d;
        if (isset($g['id'])) {
            $existing = $this->find($this->snap['adGroups'], fn($a) => (string) $a['adGroup']['id'] === (string) $g['id'])['adGroup'] ?? null;
            if (!$existing) {
                $d->error("Ad group {$g['id']} does not belong to this campaign");
                return;
            }
            $agRn = $existing['resourceName'];
            $section = "Ad group \"{$existing['name']}\"";
            $this->updateAdGroup($g, $existing, $section);
        } else {
            $agRn = "customers/{$this->cid}/adGroups/" . (--$this->tmp);
            $section = "Ad group \"{$g['createName']}\"";
            $grupaSlug = null;
            if (isset($g['adGroupParam'])) {
                $grupaSlug = Slug::toParamSlug((string) $g['adGroupParam']);
                if ($grupaSlug === '') {
                    $d->error("The \"grupa\" parameter is empty after normalization: \"{$g['adGroupParam']}\"");
                }
            }
            $d->op(Ops::adGroupCreate(['resourceName' => $agRn, 'campaignRn' => $this->campRn, 'name' => (string) $g['createName'], 'status' => $g['status'] ?? 'ENABLED', 'defaultCpcPln' => $g['defaultCpcPln'] ?? null, 'grupaSlug' => $grupaSlug ?: null]));
            $d->add($section, 'New ad group', null, (string) $g['createName'], 'add');
        }
        $agId = self::lastId($agRn);
        $this->keywords($g, $agRn, $agId, $section);
        $this->ads($g, $agRn, $agId, $section);
    }

    /** Update of an existing group: name, status, default CPC (micros), the {_grupa} parameter; only fields given in the request go into the updateMask. */
    private function updateAdGroup(array $g, array $existing, string $section): void
    {
        $d = $this->d;
        $u = ['resourceName' => $existing['resourceName']];
        $m = [];
        if (isset($g['rename'])) {
            $u['name'] = $g['rename'];
            $m[] = 'name';
            $d->add($section, 'Name', $existing['name'], $g['rename'], 'change');
        }
        if (isset($g['status'])) {
            $u['status'] = $g['status'];
            $m[] = 'status';
            $d->add($section, 'Status', $existing['status'], $g['status'], 'change');
        }
        if (isset($g['defaultCpcPln'])) {
            $u['cpcBidMicros'] = Money::plnToMicros((float) $g['defaultCpcPln']);
            $m[] = 'cpcBidMicros';
            $d->add($section, 'Default CPC', !empty($existing['cpcBidMicros']) ? 'PLN ' . Money::microsToPln($existing['cpcBidMicros']) : null, "PLN {$g['defaultCpcPln']}", 'change');
        }
        if (isset($g['adGroupParam'])) {
            $slug = Slug::toParamSlug((string) $g['adGroupParam']);
            if ($slug === '') {
                $d->error("The \"grupa\" parameter is empty after normalization: \"{$g['adGroupParam']}\"");
            } else {
                $u['urlCustomParameters'] = self::mergeParam($existing['urlCustomParameters'] ?? null, 'grupa', $slug);
                $m[] = 'urlCustomParameters';
                $d->add($section, '{_grupa}', self::paramValue($existing['urlCustomParameters'] ?? null, 'grupa'), $slug, 'change');
            }
        }
        if ($m) {
            $d->op(['adGroupOperation' => ['update' => $u, 'updateMask' => implode(',', $m)]]);
        }
    }

    /**
     * Keywords and negatives of the group. Pause/removal only for keywords of THIS group: the criterionId is checked in the snapshot,
     * and the criterion resourceName has the form adGroupId~criterionId, hence the comparison of the prefix before "~".
     */
    private function keywords(array $g, string $agRn, string $agId, string $section): void
    {
        $d = $this->d;
        $kwSec = "$section — keywords";
        $inGroup = fn(string $rn) => explode('~', self::lastId($rn))[0] === $agId;
        foreach ($g['keywords']['add'] ?? [] as $k) {
            $d->op(Ops::keyword($agRn, $k));
            $d->add($kwSec, 'Keyword', null, self::kwLabel($k), 'add');
        }
        foreach ($g['negatives']['add'] ?? [] as $k) {
            $d->op(Ops::adGroupNegative($agRn, $k));
            $d->add('Negatives', 'Negative', null, self::kwLabel($k), 'add');
        }
        $kwRow = function (string $id) use ($d, $agId, $inGroup) {
            $r = $this->find($this->snap['keywords'], fn($x) => (string) $x['adGroupCriterion']['criterionId'] === $id && $inGroup($x['adGroupCriterion']['resourceName']));
            if (!$r) {
                $d->error("Keyword $id does not belong to ad group $agId of this campaign");
            }
            return $r['adGroupCriterion'] ?? null;
        };
        foreach ($g['keywords']['pauseIds'] ?? [] as $id) {
            $r = $kwRow((string) $id);
            if (!$r) {
                continue;
            }
            $d->op(['adGroupCriterionOperation' => ['update' => ['resourceName' => $r['resourceName'], 'status' => 'PAUSED'], 'updateMask' => 'status']]);
            $d->add($kwSec, $r['keyword']['text'] . ' (' . self::matchOf($r) . ')', self::status($r['status'] ?? null), 'paused', 'change');
        }
        foreach ($g['keywords']['removeIds'] ?? [] as $id) {
            $r = $kwRow((string) $id);
            if (!$r) {
                continue;
            }
            $d->op(['adGroupCriterionOperation' => ['remove' => $r['resourceName']]]);
            $d->add($kwSec, 'Keyword', $r['keyword']['text'] . ' (' . self::matchOf($r) . ')', null, 'remove');
        }
    }

    private static function adLabel(array $r, string $id): string
    {
        $h = $r['ad']['responsiveSearchAd']['headlines'][0]['text'] ?? null;
        $u = ($r['ad']['finalUrls'] ?? [])[0] ?? null;
        return "Ad $id" . ($h ? " \"{$h}\"" : '') . ($u ? " → $u" : '');
    }

    /**
     * RSA ads of the group: add (validation with Guard::checkRsa: headline/description length limits, and the final URL),
     * pause, remove, change finalUrl. The content of an RSA ad cannot be edited: new content = a new ad (hence noteSuffix in the diff).
     */
    private function ads(array $g, string $agRn, string $agId, string $section): void
    {
        $d = $this->d;
        $adSec = "$section — ads";
        $adRow = function (string $id) use ($d, $agId) {
            $r = $this->find($this->snap['ads'], fn($x) => (string) $x['adGroupAd']['ad']['id'] === $id && explode('~', self::lastId($x['adGroupAd']['resourceName']))[0] === $agId);
            if (!$r) {
                $d->error("Ad $id does not belong to ad group $agId of this campaign");
            }
            return $r['adGroupAd'] ?? null;
        };
        foreach ($g['ads']['add'] ?? [] as $r) {
            $u = Guard::checkFinalUrl($r['finalUrl']);
            $d->error(...Guard::checkRsa($r), ...$u['errors']);
            $d->warn(...$u['warnings']);
            $d->op(Ops::rsaAd($agRn, $r));
            $d->rows(RsaDiff::rows($adSec, $r));
            $this->noteSuffix = true;
        }
        foreach ($g['ads']['pauseIds'] ?? [] as $id) {
            $r = $adRow((string) $id);
            if ($r) {
                $d->op(['adGroupAdOperation' => ['update' => ['resourceName' => $r['resourceName'], 'status' => 'PAUSED'], 'updateMask' => 'status']]);
                $d->add($adSec, self::adLabel($r, (string) $id), self::status($r['status'] ?? null), 'paused', 'change');
            }
        }
        foreach ($g['ads']['removeIds'] ?? [] as $id) {
            $r = $adRow((string) $id);
            if ($r) {
                $d->op(['adGroupAdOperation' => ['remove' => $r['resourceName']]]);
                $d->add($adSec, self::adLabel($r, (string) $id), self::status($r['status'] ?? null), null, 'remove');
            }
        }
        foreach ($g['ads']['setFinalUrl'] ?? [] as $s) {
            $r = $adRow((string) $s['adId']);
            if (!$r) {
                continue;
            }
            $u = Guard::checkFinalUrl($s['finalUrl']);
            $d->error(...$u['errors']);
            $d->warn(...$u['warnings']);
            $d->op(['adOperation' => ['update' => ['resourceName' => "customers/{$this->cid}/ads/{$s['adId']}", 'finalUrls' => [$s['finalUrl']]], 'updateMask' => 'finalUrls']]);
            $d->add($adSec, 'Final URL: ' . self::adLabel($r, (string) $s['adId']), implode(', ', $r['ad']['finalUrls'] ?? []) ?: null, $s['finalUrl'], 'change');
        }
    }
}
