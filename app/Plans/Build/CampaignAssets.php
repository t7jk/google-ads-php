<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Media\Ratio;
use App\Plans\{BlobOps, Cfg, Guard, Plan, Prepare, Text, Undo};
use App\Plans\Snapshot\PmaxSnapshot;

/**
 * Plan for campaign extension changes (port of build-campaign-assets.ts): sitelinks, callouts, call, lead form removal,
 * and, when brand guidelines are enabled, also the business name and logos. Usage: CampaignAssets::plan($req, $pmaxSnapshot, $logos, $existing, $now).
 *
 * Assumptions and pitfalls:
 * - Google assets cannot be changed in place: "changing the URL" = a new asset + a link + removal of the old link.
 * - The operation order is fixed: assets (negative temporary IDs) -> links (create) -> links (remove) -> removal of the form from asset groups.
 * - Campaign link ($links): ['ft','assetId','link' (the link resourceName),'a' (asset from the snapshot)]. Removed links go to $removedLinks.
 * - Logos are images from BlobOps::ref; reusing an existing asset (ExistingImages) only adds a link (linkOnly).
 */
final class CampaignAssets
{
    private const NO_BRAND = 'This campaign does not use brand guidelines; change the business name and logos on the asset group (prepare_asset_group_changes)';
    private const MAX_LOGO_BYTES = 10_485_760;
    private const SL = 'Sitelinks';
    private const CO = 'Callouts';
    private const CALL = 'Call';
    private const FORM = 'Lead form';
    private const MK = 'Brand';

    private array $errors = [];
    private array $warnings = [];
    private SectionRows $rows;
    private array $base;
    private string $cid;
    private string $campaignRn;
    /** @var list<array> */
    private array $links = [];
    /** @var array<string,true> */
    private array $removedLinks = [];
    /** @var list<array{rn:string,asset:array,ft:string}> */
    private array $creates = [];
    /** @var list<array{rn:string,ft:string}> */
    private array $linkOnly = [];
    private int $tmp = 0;
    private bool $cancelledDup = false;

    private function __construct(private array $req, private array $s, private array $logos, private array $existing, private \DateTimeImmutable $now)
    {
        $this->rows = new SectionRows();
        $this->base = Plan::base($now);
        $this->cid = Cfg::customerId();
        $this->campaignRn = (string) ($s['campaign']['resourceName'] ?? "customers/{$this->cid}/campaigns/{$req['campaignId']}");
    }

    /** Fingerprint of the part of the snapshot the plan changes: the campaign, its assets and the form links in groups. */
    public static function fingerprint(array $s): string
    {
        $s['groupAssets'] = array_values(array_filter($s['groupAssets'], fn($r) => ($r['assetGroupAsset']['fieldType'] ?? null) === 'LEAD_FORM'));
        return PmaxSnapshot::fingerprint($s, ['campaign', 'campaignAssets', 'groupAssets']);
    }

    /** @param array $req tool input + campaignId @param list<array> $logos ResolvedImage (role=logo) @param list<array> $existing ExistingImages::find */
    public static function plan(array $req, array $snapshot, array $logos, array $existing, \DateTimeImmutable $now): array
    {
        return (new self($req, $snapshot, $logos, $existing, $now))->build();
    }

    private function build(): array
    {
        if (!$this->s['campaign']) {
            $this->errors[] = 'Campaign not found';
        }
        foreach ($this->s['campaignAssets'] as $r) {
            $this->links[] = ['ft' => (string) ($r['campaignAsset']['fieldType'] ?? ''), 'assetId' => (string) ($r['asset']['id'] ?? ''), 'link' => (string) ($r['campaignAsset']['resourceName'] ?? ''), 'a' => $r['asset'] ?? []];
        }
        $this->sitelinks();
        $this->callouts();
        $this->call();
        $groupFormRemoves = $this->leadForm();
        $this->brand();
        if (($this->s['campaign']['status'] ?? null) === 'ENABLED') {
            $this->warnings[] = 'The campaign is enabled; the change takes effect immediately';
        }
        $ops = $this->operations($groupFormRemoves);
        if (!$ops && !$this->errors && !$this->cancelledDup) {
            $this->errors[] = 'Nothing to change';
        }
        $camp = $this->s['campaign'];
        return Undo::attach(array_merge($this->base, [
            'kind' => 'campaign_assets', 'campaignId' => $this->req['campaignId'],
            'summary' => 'Campaign extensions "' . ($camp['name'] ?? $this->req['campaignId']) . '"',
            'diff' => $this->rows->flatten([self::SL, self::CO, self::CALL, self::FORM, self::MK]),
            'steps' => $ops ? [['title' => 'Campaign extensions', 'operations' => $ops]] : [],
            'warnings' => $this->warnings, 'validation' => ['ok' => !$this->errors, 'errors' => $this->errors],
            'beforeFingerprint' => self::fingerprint($this->s),
        ], $camp ? ['campaignState' => Text::stateLabel($camp)] : []), $this->s);
    }

    // ---- helpers ----

    /** @return list<int> indexes of links of the given type */
    private function ofType(string ...$types): array
    {
        return array_keys(array_filter($this->links, fn($l) => in_array($l['ft'], $types, true)));
    }

    /** Indexes of links of the given type that are not removed. @return list<int> */
    private function liveOfType(string ...$types): array
    {
        return array_values(array_filter($this->ofType(...$types), fn($i) => !isset($this->removedLinks[$this->links[$i]['link']])));
    }

    private function removeLink(int $i): void
    {
        $this->removedLinks[$this->links[$i]['link']] = true;
    }

    private function addAsset(string $ft, array $asset): void
    {
        $rn = "customers/{$this->cid}/assets/" . --$this->tmp;
        $this->creates[] = ['rn' => $rn, 'asset' => ['resourceName' => $rn] + $asset, 'ft' => $ft];
    }

    private function urlOk(string $u): bool
    {
        $e = Guard::checkUrlHost($u);
        if ($e !== null) {
            $this->errors[] = $e;
        }
        return $e === null;
    }

    private function present(array $list): string
    {
        return implode('; ', $list) ?: 'none';
    }

    /** Links matching the text or the assetId (digits only = assetId, if such an asset exists). @param callable(int):string $textOf */
    private function pickLink(array $pool, string $ref, callable $textOf): array
    {
        $r = trim($ref);
        $byId = preg_match('/^\d+$/', $r) ? array_values(array_filter($pool, fn($i) => $this->links[$i]['assetId'] === $r)) : [];
        return $byId ?: array_values(array_filter($pool, fn($i) => Text::same($textOf($i), $r)));
    }

    // ---- sitelinks ----

    private function slText(int $i): string
    {
        return (string) ($this->links[$i]['a']['sitelinkAsset']['linkText'] ?? '');
    }

    private function slUrl(int $i): string
    {
        return (string) (($this->links[$i]['a']['finalUrls'] ?? [])[0] ?? '');
    }

    private function slLabel(int $i): string
    {
        return $this->slText($i) . ' — ' . $this->slUrl($i);
    }

    private function findSitelink(string $ref, string $what): ?int
    {
        $pool = $this->liveOfType('SITELINK');
        $hits = $this->pickLink($pool, $ref, fn($i) => $this->slText($i));
        if (count($hits) === 1) {
            return $hits[0];
        }
        $this->errors[] = $hits ? "Sitelink \"{$ref}\" matches several assets; give the assetId"
            : "Sitelink \"{$ref}\" not found ($what). Current: " . $this->present(array_map(fn($i) => $this->slText($i), $pool));
        return null;
    }

    private function sitelinks(): void
    {
        $sl = $this->req['sitelinks'] ?? [];
        foreach ($sl['add'] ?? [] as $x) {
            $this->addSitelink($x);
        }
        foreach ($sl['remove'] ?? [] as $ref) {
            $i = $this->findSitelink($ref, 'removal');
            if ($i !== null) {
                $this->removeLink($i);
                $this->rows->add(self::SL, ['label' => 'Sitelink', 'before' => $this->slLabel($i), 'after' => null, 'change' => 'remove']);
            }
        }
        foreach ($sl['replaceUrl'] ?? [] as $x) {
            $this->replaceSitelinkUrl($x);
        }
    }

    private function addSitelink(array $x): void
    {
        $t = Text::norm($x['text']);
        $d1 = isset($x['description1']) ? Text::norm($x['description1']) : '';
        $d2 = isset($x['description2']) ? Text::norm($x['description2']) : '';
        $ok = true;
        if (Text::len($t) === 0) {
            $this->errors[] = 'Sitelink text cannot be empty';
            $ok = false;
        } elseif (Text::len($t) > 25) {
            $this->errors[] = 'Sitelink text has ' . Text::len($t) . " characters (max 25): \"{$t}\"";
            $ok = false;
        }
        foreach ([$d1, $d2] as $d) {
            if (Text::len($d) > 35) {
                $this->errors[] = 'Sitelink description has ' . Text::len($d) . " characters (max 35): \"{$d}\"";
                $ok = false;
            }
        }
        if (($d1 === '') !== ($d2 === '')) {
            $this->errors[] = "Sitelink \"{$t}\": give both descriptions or neither";
            $ok = false;
        }
        if (!$this->urlOk($x['url'])) {
            $ok = false;
        }
        if (!$ok) {
            return;
        }
        $this->addAsset('SITELINK', ['finalUrls' => [$x['url']], 'sitelinkAsset' => ['linkText' => $t] + ($d1 !== '' ? ['description1' => $d1, 'description2' => $d2] : [])]);
        $this->rows->add(self::SL, ['label' => 'New sitelink', 'before' => null, 'after' => "$t — {$x['url']}", 'change' => 'add'] + ($d1 !== '' ? ['note' => "$d1 · $d2"] : []));
    }

    private function replaceSitelinkUrl(array $x): void
    {
        $i = $this->findSitelink($x['text'], 'URL change');
        $ok = $this->urlOk($x['url']);
        if ($i === null || !$ok) {
            return;
        }
        if ($this->slUrl($i) === $x['url']) {
            $this->errors[] = "Sitelink \"{$this->slText($i)}\" already has the URL {$x['url']}";
            return;
        }
        $sa = $this->links[$i]['a']['sitelinkAsset'] ?? [];
        $this->removeLink($i);
        $this->addAsset('SITELINK', ['finalUrls' => [$x['url']], 'sitelinkAsset' => ['linkText' => $sa['linkText'] ?? null]
            + (!empty($sa['description1']) ? ['description1' => $sa['description1']] : []) + (!empty($sa['description2']) ? ['description2' => $sa['description2']] : [])]);
        $this->rows->add(self::SL, ['label' => 'Sitelink URL', 'before' => $this->slLabel($i), 'after' => $this->slText($i) . " — {$x['url']}", 'change' => 'change']);
    }

    // ---- callouts ----

    private function coText(int $i): string
    {
        return (string) ($this->links[$i]['a']['calloutAsset']['calloutText'] ?? '');
    }

    private function callouts(): void
    {
        $co = $this->req['callouts'] ?? [];
        foreach ($co['add'] ?? [] as $t) {
            $e = Text::len($t) === 0 ? 'Callout cannot be empty' : (Text::len($t) > 25 ? 'Callout has ' . Text::len($t) . ' characters (max 25): "' . Text::norm($t) . '"' : null);
            if ($e) {
                $this->errors[] = $e;
                continue;
            }
            $this->addAsset('CALLOUT', ['calloutAsset' => ['calloutText' => Text::norm($t)]]);
            $this->rows->add(self::CO, ['label' => 'New callout', 'before' => null, 'after' => Text::norm($t), 'change' => 'add']);
        }
        foreach ($co['remove'] ?? [] as $ref) {
            $pool = $this->liveOfType('CALLOUT');
            $hits = $this->pickLink($pool, $ref, fn($i) => $this->coText($i));
            if (count($hits) !== 1) {
                $this->errors[] = $hits ? "Callout \"{$ref}\" matches several assets; give the assetId"
                    : "Callout \"{$ref}\" not found. Current: " . $this->present(array_map(fn($i) => $this->coText($i), $pool));
                continue;
            }
            $this->removeLink($hits[0]);
            $this->rows->add(self::CO, ['label' => 'Callout', 'before' => $this->coText($hits[0]), 'after' => null, 'change' => 'remove']);
        }
    }

    // ---- call ----

    private function callLabel(int $i): string
    {
        $c = $this->links[$i]['a']['callAsset'] ?? [];
        return trim(($c['countryCode'] ?? '') . ' ' . ($c['phoneNumber'] ?? ''));
    }

    private function call(): void
    {
        if (!array_key_exists('call', $this->req)) {
            return;
        }
        $call = $this->req['call'];
        $old = $this->ofType('CALL');
        if ($call === 'remove') {
            if (!$old) {
                $this->errors[] = 'The campaign has no call extension';
            }
        } else {
            $raw = preg_replace('/[\s-]/', '', $call['phone']);
            $phone = preg_match('/^\+48\d{9}$/', $raw) ? $raw : (preg_match('/^\d{9}$/', $raw) ? "+48$raw" : null);
            if ($phone === null) {
                $this->errors[] = "Invalid phone number \"{$call['phone']}\": give +48 and 9 digits, or 9 digits";
            } else {
                $country = $call['countryCode'] ?? 'PL';
                $this->addAsset('CALL', ['callAsset' => ['countryCode' => $country, 'phoneNumber' => $phone]]);
                $this->rows->add(self::CALL, ['label' => 'Number', 'before' => $old ? $this->callLabel($old[0]) : null, 'after' => "$country $phone", 'change' => $old ? 'change' : 'add']);
            }
        }
        foreach ($old as $i) {
            $this->removeLink($i);
            if ($call === 'remove') {
                $this->rows->add(self::CALL, ['label' => 'Number', 'before' => $this->callLabel($i), 'after' => null, 'change' => 'remove']);
            }
        }
    }

    // ---- lead form ----

    /** @return list<string> form links in asset groups to remove */
    private function leadForm(): array
    {
        if (($this->req['leadForm'] ?? null) !== 'remove') {
            return [];
        }
        $groupForms = array_values(array_filter($this->s['groupAssets'], fn($r) => ($r['assetGroupAsset']['fieldType'] ?? null) === 'LEAD_FORM'));
        $forms = $this->ofType('LEAD_FORM');
        if (!$forms && !$groupForms) {
            $this->errors[] = 'The campaign has no lead form';
        }
        foreach ($forms as $i) {
            $this->removeLink($i);
            $this->rows->add(self::FORM, ['label' => 'Form', 'before' => "Lead form {$this->links[$i]['assetId']}", 'after' => null, 'change' => 'remove', 'note' => 'Link to the campaign']);
        }
        $out = [];
        foreach ($groupForms as $r) {
            $out[] = (string) $r['assetGroupAsset']['resourceName'];
            $this->rows->add(self::FORM, ['label' => 'Form in group', 'before' => "Form {$r['asset']['id']} in group {$r['assetGroup']['id']}", 'after' => null, 'change' => 'remove', 'note' => 'Link to the asset group']);
        }
        return $out;
    }

    // ---- brand (business name and logos) ----

    private function brand(): void
    {
        $req = $this->req;
        $wantsBrand = isset($req['businessName']) || !empty($req['logos']['add']) || !empty($req['logos']['remove']) || $this->logos;
        if (!$wantsBrand) {
            return;
        }
        if (($this->s['campaign']['brandGuidelinesEnabled'] ?? false) !== true) {
            $this->errors[] = self::NO_BRAND;
            return;
        }
        if (isset($req['businessName'])) {
            $this->businessName($req['businessName']);
        }
        $brandAdds = [];
        $dupLogos = [];
        $this->addLogos($brandAdds, $dupLogos);
        $removedLogos = [];
        $logoRows = [];
        foreach ($req['logos']['remove'] ?? [] as $x) {
            $this->removeLogo($x, $removedLogos, $logoRows);
        }
        $this->cancelDuplicateLogos($dupLogos, $removedLogos, $logoRows);
        if ($removedLogos) {
            $left = count($this->liveOfType('LOGO')) + count(array_filter($brandAdds, fn($b) => $b === 'LOGO'));
            if ($left < 1) {
                $this->errors[] = 'The campaign must have at least one 1:1 logo';
            }
        }
        foreach (['LOGO', 'LANDSCAPE_LOGO'] as $ft) {
            $n = count($this->liveOfType($ft)) + count(array_filter($brandAdds, fn($b) => $b === $ft));
            if ($n > 5) {
                $this->errors[] = 'Too many ' . ($ft === 'LOGO' ? '1:1' : '4:1') . " logos after the change: $n (max 5)";
            }
        }
    }

    private function businessName(string $name): void
    {
        $e = PmaxRules::checkText('BUSINESS_NAME', $name);
        if ($e) {
            $this->errors[] = $e;
            return;
        }
        $old = $this->ofType('BUSINESS_NAME');
        $nm = fn(int $i) => (string) ($this->links[$i]['a']['textAsset']['text'] ?? '');
        if (array_filter($old, fn($i) => Text::same($nm($i), $name))) {
            $this->errors[] = 'Business name "' . Text::norm($name) . '" is already set';
            return;
        }
        $this->addAsset('BUSINESS_NAME', ['textAsset' => ['text' => Text::norm($name)]]);
        foreach ($old as $i) {
            $this->removeLink($i);
        }
        $this->rows->add(self::MK, ['label' => 'Business name', 'before' => $old ? $nm($old[0]) : null, 'after' => Text::norm($name), 'change' => $old ? 'change' : 'add']);
    }

    /** @param list<string> $brandAdds field types of the logos being added @param list<array> $dupLogos */
    private function addLogos(array &$brandAdds, array &$dupLogos): void
    {
        $bytes = 0;
        $seen = [];
        foreach ($this->logos as $im) {
            if (isset($seen[$im['sha']])) {
                $this->errors[] = 'The same image was given more than once';
                continue;
            }
            $seen[$im['sha']] = true;
            $bytes += $im['bytes'];
            $this->addLogo($im, $brandAdds, $dupLogos);
        }
        if ($bytes > self::MAX_LOGO_BYTES) {
            $this->errors[] = 'Images in one plan may total at most 10 MB; split the change into several plans';
        }
    }

    private function addLogo(array $im, array &$brandAdds, array &$dupLogos): void
    {
        $cls = Ratio::classify($im['width'], $im['height'], 'logo');
        if (isset($cls['error'])) {
            $this->errors[] = $cls['error'];
            return;
        }
        if ($cls['belowMin']) {
            $this->errors[] = "Logo {$im['width']}×{$im['height']} is smaller than the minimum for {$cls['fieldType']}";
            return;
        }
        if ($cls['belowRecommended']) {
            $this->warnings[] = "Logo {$im['width']}×{$im['height']} is smaller than recommended";
        }
        $ft = $cls['fieldType'];
        $nm = ImageSources::assetName($im, $this->now);
        $m = ExistingImages::match($nm, $im['width'], $im['height'], $this->existing, $im['sha']);
        if ($m !== null && isset($m['error'])) {
            $this->errors[] = $m['error'];
            return;
        }
        if ($m !== null) {
            $dup = array_values(array_filter($this->liveOfType($ft), fn($i) => $this->links[$i]['assetId'] === $m['reuse']['id']));
            if ($dup) {
                $dupLogos[] = ['l' => $dup[0], 'name' => $m['reuse']['name']]; // resolved after the removals are processed
                return;
            }
            $this->linkOnly[] = ['rn' => $m['reuse']['resourceName'] ?: "customers/{$this->cid}/assets/{$m['reuse']['id']}", 'ft' => $ft];
        } else {
            $this->addAsset($ft, ['name' => $nm, 'imageAsset' => ['data' => BlobOps::ref($im['sha'])]]);
        }
        $brandAdds[] = $ft;
        $this->rows->add(self::MK, [
            'label' => $im['name'] ?? 'New logo', 'before' => null, 'after' => "{$im['width']}×{$im['height']}", 'change' => 'add',
            'afterImage' => Plan::imagePrefix($this->base['id']) . $im['sha'], 'imageAlt' => "{$im['width']}x{$im['height']}", 'note' => "{$im['width']}×{$im['height']} · {$cls['ratio']} · $ft",
        ]);
    }

    private function logoSize(int $i): string
    {
        $f = $this->links[$i]['a']['imageAsset']['fullSize'] ?? null;
        return !empty($f['widthPixels']) && !empty($f['heightPixels']) ? "{$f['widthPixels']}×{$f['heightPixels']}" : '';
    }

    private function logoLabel(int $i): string
    {
        return trim(($this->links[$i]['a']['name'] ?? "Logo {$this->links[$i]['assetId']}") . ' ' . $this->logoSize($i));
    }

    /** @param list<int> $removedLogos @param array<int,int> $logoRows link index => preview row id */
    private function removeLogo(array $x, array &$removedLogos, array &$logoRows): void
    {
        $pool = $this->liveOfType('LOGO', 'LANDSCAPE_LOGO');
        $id = isset($x['assetId']) ? trim($x['assetId']) : null;
        $nm = isset($x['name']) ? trim($x['name']) : null;
        if (($id === null || $id === '') && ($nm === null || $nm === '')) {
            $this->errors[] = 'Logo to remove: give name or assetId';
            return;
        }
        $hits = $id !== null && $id !== ''
            ? array_values(array_filter($pool, fn($i) => $this->links[$i]['assetId'] === $id))
            : array_values(array_filter($pool, fn($i) => Text::same((string) ($this->links[$i]['a']['name'] ?? ''), $nm)));
        if (count($hits) > 1) {
            $this->errors[] = "Logo \"{$nm}\" matches several assets; give the assetId";
            return;
        }
        if (!$hits) {
            $this->errors[] = 'Logo "' . ($id ?: $nm) . '" not found. Current: ' . $this->present(array_map(fn($i) => $this->logoLabel($i), $pool));
            return;
        }
        $i = $hits[0];
        $this->removeLink($i);
        $removedLogos[] = $i;
        $size = $this->logoSize($i);
        $url = $this->links[$i]['a']['imageAsset']['fullSize']['url'] ?? null;
        $logoRows[$i] = $this->rows->add(self::MK, ['label' => 'Logo', 'before' => $this->logoLabel($i), 'after' => 'removed', 'change' => 'remove']
            + ($url ? ['beforeImage' => (string) $url] : []) + ($size !== '' ? ['imageAlt' => str_replace('×', 'x', $size), 'note' => "$size · {$this->links[$i]['ft']}"] : []));
    }

    /** Adding and removing the same file in one plan cancel each other; in other cases it is an error. */
    private function cancelDuplicateLogos(array $dupLogos, array &$removedLogos, array &$logoRows): void
    {
        foreach ($dupLogos as $d) {
            $link = $this->links[$d['l']]['link'];
            if (!isset($this->removedLinks[$link])) {
                $this->errors[] = "This logo/image is already in the campaign ({$d['name']})";
                continue;
            }
            unset($this->removedLinks[$link]);
            $removedLogos = array_values(array_diff($removedLogos, [$d['l']]));
            if (isset($logoRows[$d['l']])) {
                $this->rows->drop(self::MK, $logoRows[$d['l']]);
            }
            $this->cancelledDup = true;
            if (!in_array(Prepare::SAME_IMAGE_NOOP, $this->warnings, true)) {
                $this->warnings[] = Prepare::SAME_IMAGE_NOOP;
            }
        }
    }

    // ---- operations ----

    /** Order: assets -> links (create) -> links (remove) -> removal of the form from groups. @param list<string> $groupFormRemoves */
    private function operations(array $groupFormRemoves): array
    {
        $campaignAsset = fn(array $c) => ['campaignAssetOperation' => ['create' => ['campaign' => $this->campaignRn, 'asset' => $c['rn'], 'fieldType' => $c['ft']]]];
        return [
            ...array_map(fn($c) => ['assetOperation' => ['create' => $c['asset']]], $this->creates),
            ...array_map($campaignAsset, [...$this->creates, ...$this->linkOnly]),
            ...array_map(fn($l) => ['campaignAssetOperation' => ['remove' => $l]], array_keys($this->removedLinks)),
            ...array_map(fn($l) => ['assetGroupAssetOperation' => ['remove' => $l]], $groupFormRemoves),
        ];
    }
}
