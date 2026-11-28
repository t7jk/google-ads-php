<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Ads\{Money, Slug};
use App\Plans\{Cfg, Guard, Json, Ops, Plan, RsaDiff, Undo};

/** New Search campaign, always PAUSED (port of build-new.ts): budget, bidding, location, ad groups, keywords, RSA ads. */
final class NewCampaign
{
    private const MATCH = ['EXACT' => 'exact', 'PHRASE' => 'phrase', 'BROAD' => 'broad'];
    private const BID = ['MANUAL_CPC' => 'Manual CPC', 'MAXIMIZE_CLICKS' => 'Maximize clicks', 'MAXIMIZE_CONVERSIONS' => 'Maximize conversions'];

    /** @param list<string> $existingNames names of existing campaigns (uniqueness check, case-insensitive) */
    public static function build(array $req, array $existingNames): array
    {
        $d = new Draft();
        $cid = Cfg::customerId();
        $P = "customers/$cid";
        $budgetRn = "$P/campaignBudgets/-1";
        $campRn = "$P/campaigns/-2";
        $name = trim($req['name']);
        $norm = fn(string $s) => mb_strtolower(trim($s));
        if ($name === '') {
            $d->error('Campaign name cannot be empty');
        } elseif (mb_strlen($name) > 255) {
            $d->error('Campaign name is longer than 255 characters');
        }
        if ($name !== '' && array_filter($existingNames, fn($n) => $norm($n) === $norm($name))) {
            $d->error("A campaign named \"{$name}\" already exists");
        }
        $d->error(...Guard::checkBudget((float) $req['dailyBudgetPln']));

        $camp = [
            'resourceName' => $campRn, 'name' => $name, 'status' => 'PAUSED', 'advertisingChannelType' => 'SEARCH', 'campaignBudget' => $budgetRn,
            'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
            'geoTargetTypeSetting' => ['positiveGeoTargetType' => 'PRESENCE'],
            'networkSettings' => ['targetGoogleSearch' => true, 'targetSearchNetwork' => $req['networks']['searchPartners'] ?? false, 'targetContentNetwork' => $req['networks']['display'] ?? false],
        ] + Ops::biddingFields($req['bidding'])['fields'];
        if ($req['bidding']['type'] === 'MANUAL_CPC' && isset($req['bidding']['maxCpcPln'])) {
            $d->warn('maxCpcPln is ignored for manual CPC at campaign level; use defaultCpcPln on the ad group.');
        }
        if (array_key_exists('campaignParam', $req)) {
            $slug = Slug::toParamSlug((string) $req['campaignParam']);
            if ($slug === '') {
                $d->error("The \"kampania\" parameter is empty after normalization: \"{$req['campaignParam']}\"");
            } else {
                $camp['urlCustomParameters'] = [['key' => 'kampania', 'value' => $slug]];
                $d->add('Campaign', '{_kampania}', null, $slug, 'add'); // {_kampania} is the real custom-parameter name used in landing-page URLs; do not translate
            }
        }
        $d->op(['campaignBudgetOperation' => ['create' => ['resourceName' => $budgetRn, 'name' => "$name – budget", 'amountMicros' => Money::plnToMicros((float) $req['dailyBudgetPln']), 'deliveryMethod' => 'STANDARD', 'explicitlyShared' => false]]]);
        $d->op(['campaignOperation' => ['create' => $camp]]);
        self::summaryRows($req, $name, $camp, $d);

        $geo = $req['location']['proximity'] ?? Cfg::defaultGeo();
        $d->op(Ops::proximity($campRn, (float) $geo['lat'], (float) $geo['lng'], (float) $geo['radiusKm']));
        $d->add('Location', 'Radius', null, "{$geo['radiusKm']} km around {$geo['lat']}, {$geo['lng']}", 'add');
        $d->add('Location', 'Targeting', null, 'people in the location', 'add');
        foreach ($req['campaignNegatives'] ?? [] as $k) {
            $d->op(Ops::campaignNegative($campRn, $k));
            $d->add('Negatives', 'Negative', null, $k['text'] . ' (' . self::MATCH[$k['match']] . ')', 'add');
        }
        self::adGroups($req, $campRn, $P, $d);
        $d->error(...Guard::requireEuDeclaration($d->ops), ...Guard::assertNoEnable($d->ops));
        return Undo::attach(array_merge(Plan::base(), [
            'kind' => 'new_campaign', 'summary' => "New campaign \"{$name}\" (paused)",
            'steps' => [['title' => 'New campaign', 'operations' => $d->ops]],
            'beforeFingerprint' => Json::fingerprint(['newCampaign' => $name]),
        ], $d->common()));
    }

    private static function summaryRows(array $req, string $name, array $camp, Draft $d): void
    {
        $yn = fn($v) => $v ? 'yes' : 'no';
        $d->add('Campaign', 'Name', null, $name, 'add');
        $d->add('Campaign', 'Status', null, 'paused (enabling it is a separate decision)', 'add');
        $d->add('Budget', 'Daily budget', null, 'PLN ' . Money::microsToPln(Money::plnToMicros((float) $req['dailyBudgetPln'])) . '/day', 'add');
        $b = $req['bidding'];
        $d->add('Bidding', 'Bidding strategy', null, self::BID[$b['type']] . ($b['type'] === 'MAXIMIZE_CLICKS' && isset($b['maxCpcPln']) ? " (max CPC PLN {$b['maxCpcPln']})" : ''), 'add');
        $ns = $camp['networkSettings'];
        $d->add('Networks', 'Google Search', null, $yn($ns['targetGoogleSearch']), 'add');
        $d->add('Networks', 'Search partners', null, $yn($ns['targetSearchNetwork']), 'add');
        $d->add('Networks', 'Display Network', null, $yn($ns['targetContentNetwork']), 'add');
    }

    /** Ad groups: all groups first, then keywords and negatives, ads last (temporary IDs -3, -4, ...). */
    private static function adGroups(array $req, string $campRn, string $P, Draft $d): void
    {
        $groups = [];
        foreach ($req['adGroups'] as $i => $g) {
            $groups[] = ['g' => $g, 'rn' => "$P/adGroups/" . (-3 - $i), 'section' => "Ad group \"{$g['name']}\""];
        }
        if (!$groups) {
            $d->error('The campaign must have at least one ad group');
        }
        foreach ($groups as ['g' => $g, 'rn' => $rn, 'section' => $section]) {
            if (trim($g['name']) === '') {
                $d->error('Ad group name cannot be empty');
            } elseif (mb_strlen($g['name']) > 255) {
                $d->error("Ad group name \"{$g['name']}\" is longer than 255 characters");
            }
            if (!$g['keywords']) {
                $d->error("Ad group \"{$g['name']}\" must have at least one keyword");
            }
            if (!$g['ads']) {
                $d->error("Ad group \"{$g['name']}\" must have at least one ad");
            }
            $grupaSlug = null;
            if (isset($g['adGroupParam'])) {
                $grupaSlug = Slug::toParamSlug((string) $g['adGroupParam']);
                if ($grupaSlug === '') {
                    $d->error("The \"grupa\" parameter is empty after normalization: \"{$g['adGroupParam']}\"");
                }
            }
            $d->op(Ops::adGroupCreate(['resourceName' => $rn, 'campaignRn' => $campRn, 'name' => $g['name'], 'status' => 'ENABLED', 'defaultCpcPln' => $g['defaultCpcPln'] ?? null, 'grupaSlug' => $grupaSlug ?: null]));
            $d->add($section, 'New ad group', null, $g['name'], 'add');
            if (isset($g['defaultCpcPln'])) {
                $d->add($section, 'Default CPC', null, "PLN {$g['defaultCpcPln']}", 'add');
            }
        }
        foreach ($groups as ['g' => $g, 'rn' => $rn, 'section' => $section]) {
            foreach ($g['keywords'] as $k) {
                $d->op(Ops::keyword($rn, $k));
                $d->add("$section — keywords", 'Keyword', null, $k['text'] . ' (' . self::MATCH[$k['match']] . ')', 'add');
            }
            foreach ($g['negatives'] ?? [] as $k) {
                $d->op(Ops::adGroupNegative($rn, $k));
                $d->add($section, 'Negative', null, $k['text'] . ' (' . self::MATCH[$k['match']] . ')', 'add');
            }
        }
        $noteSuffix = false;
        foreach ($groups as ['g' => $g, 'rn' => $rn, 'section' => $section]) {
            foreach ($g['ads'] as $i => $r) {
                $u = Guard::checkFinalUrl($r['finalUrl']);
                $prefix = "Ad group \"{$g['name']}\", ad " . ($i + 1) . ': ';
                $d->error(...array_map(fn($m) => $prefix . $m, Guard::checkRsa($r)), ...array_map(fn($m) => $prefix . $m, $u['errors']));
                $d->warn(...$u['warnings']);
                $d->op(Ops::rsaAd($rn, $r));
                $d->rows(RsaDiff::rows("$section — ads", $r));
                $noteSuffix = true;
            }
        }
        if ($noteSuffix) {
            $d->diff[] = RsaDiff::suffixNoteRow('Ads');
        }
    }
}
