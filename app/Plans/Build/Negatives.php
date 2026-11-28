<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Google\Ads;
use App\Plans\{Cfg, Json, OfferCollisions, Plan, Text, Undo};

/**
 * Negative keywords of a campaign or a shared list (port of build-negatives.ts).
 * Input: keywords[], matchType?, target {campaign}|{sharedList, attachTo?}, remove?, checkCollisions?; campaignId (the resolved ID) as an extra field.
 */
final class Negatives
{
    // Google Ads limits for negative keyword text: 80 characters and 10 words. MAX_KEYWORDS is the max number of items in one MCP request (validated in Schemas).
    private const MAX_KEYWORDS = 200;
    private const MAX_CHARS = 80;
    private const MAX_WORDS = 10;
    /** Characters Google does not accept in keyword text (the match type is chosen with the matchType field). */
    private const BAD_CHARS = '/[!@%^()={};~`<>?\\\\|,"\[\]]/';
    private const MATCH_LABEL = ['EXACT' => '[exact]', 'PHRASE' => '"phrase"', 'BROAD' => 'broad'];

    /** Forces digits only in an ID inserted into GAQL (protection against query injection); an invalid ID becomes '0' (the query returns nothing). */
    private static function num(string $id): string
    {
        return preg_match('/^\d+$/', $id) ? $id : '0';
    }

    /**
     * Negatives snapshot: campaign, criteria, activeKeywords, sharedSets, sharedCriteria, campaignSharedSets, attach, offer? (offer texts).
     * @param list<string> $attachIds
     */
    // Note: shared lists and attachments are loaded for the WHOLE account (not just the campaign), because the fingerprint must detect any list change between planning and approval.
    public static function load(?string $campaignId, array $attachIds = []): array
    {
        $cid = $campaignId !== null ? self::num($campaignId) : null;
        $ids = array_map([self::class, 'num'], $attachIds);
        $s = fn(string $q) => Ads::search($q);
        $campaign = $criteria = $activeKeywords = $attach = [];
        if ($cid !== null) {
            $campaign = $s("SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type FROM campaign WHERE campaign.id = $cid");
            $criteria = $s("SELECT campaign_criterion.resource_name, campaign_criterion.negative, campaign_criterion.keyword.text, campaign_criterion.keyword.match_type FROM campaign_criterion WHERE campaign.id = $cid AND campaign_criterion.type = 'KEYWORD' AND campaign_criterion.negative = TRUE AND campaign_criterion.status != 'REMOVED'");
            $activeKeywords = $s("SELECT ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type FROM ad_group_criterion WHERE campaign.id = $cid AND ad_group_criterion.type = 'KEYWORD' AND ad_group_criterion.negative = FALSE AND ad_group_criterion.status = 'ENABLED'");
        }
        $sharedSets = $s("SELECT shared_set.id, shared_set.name, shared_set.resource_name, shared_set.type, shared_set.status FROM shared_set WHERE shared_set.type = 'NEGATIVE_KEYWORDS' AND shared_set.status = 'ENABLED'");
        $sharedCriteria = $s("SELECT shared_set.resource_name, shared_criterion.resource_name, shared_criterion.keyword.text, shared_criterion.keyword.match_type FROM shared_criterion WHERE shared_set.type = 'NEGATIVE_KEYWORDS' AND shared_set.status = 'ENABLED'");
        $campaignSharedSets = $s("SELECT campaign.id, campaign.name, campaign_shared_set.resource_name, campaign_shared_set.shared_set, campaign_shared_set.status FROM campaign_shared_set WHERE shared_set.type = 'NEGATIVE_KEYWORDS' AND campaign_shared_set.status = 'ENABLED'");
        if ($ids) {
            $attach = $s('SELECT campaign.id, campaign.name, campaign.status FROM campaign WHERE campaign.id IN (' . implode(', ', $ids) . ')');
        }
        return [
            'campaign' => $campaign[0]['campaign'] ?? null, 'criteria' => $criteria, 'activeKeywords' => $activeKeywords,
            'sharedSets' => $sharedSets, 'sharedCriteria' => $sharedCriteria, 'campaignSharedSets' => $campaignSharedSets,
            'attach' => array_map(fn($r) => $r['campaign'], $attach),
        ];
    }

    /** Adds the offer texts (collision check); returns partial errors for the log. */
    // Collects the IDs of campaigns whose offer texts must be fetched for the collision check: the target, the ones being attached, and campaigns already using this list.
    public static function withOffer(array $snapshot, array $target, array $attachIds, ?string $campaignId): array
    {
        $ids = [];
        if ($campaignId !== null) {
            $ids[] = $campaignId;
        }
        array_push($ids, ...$attachIds);
        foreach ($snapshot['campaignSharedSets'] as $r) {
            $isList = isset($target['sharedList']);
            foreach ($snapshot['sharedSets'] as $x) {
                if ($isList && ($x['sharedSet']['name'] ?? null) === $target['sharedList'] && ($x['sharedSet']['resourceName'] ?? null) === ($r['campaignSharedSet']['sharedSet'] ?? null)) {
                    $ids[] = (string) ($r['campaign']['id'] ?? '');
                    break;
                }
            }
        }
        $res = OfferCollisions::loadOfferTexts($ids);
        $snapshot['offer'] = $res['texts'];
        return [$snapshot, $res['errors']];
    }

    /** Fingerprint: campaign negatives and all shared negative lists of the account, including attachments. */
    public static function fingerprint(array $s): string
    {
        $sorted = function (array $xs): array {
            sort($xs, SORT_STRING);
            return $xs;
        };
        $kw = fn($c) => ($c['keyword']['text'] ?? '') . '|' . ($c['keyword']['matchType'] ?? '');
        return Json::fingerprint([
            'campaign' => $s['campaign'] ? (string) $s['campaign']['id'] : null,
            'criteria' => $sorted(array_map(fn($r) => ($r['campaignCriterion']['resourceName'] ?? '') . '|' . $kw($r['campaignCriterion'] ?? []), $s['criteria'])),
            'sharedSets' => $sorted(array_map(fn($r) => ($r['sharedSet']['resourceName'] ?? '') . '|' . ($r['sharedSet']['name'] ?? ''), $s['sharedSets'])),
            'sharedCriteria' => $sorted(array_map(fn($r) => ($r['sharedCriterion']['resourceName'] ?? '') . '|' . $kw($r['sharedCriterion'] ?? []), $s['sharedCriteria'])),
            'attachments' => $sorted(array_map(fn($r) => (string) ($r['campaignSharedSet']['resourceName'] ?? ''), $s['campaignSharedSets'])),
        ]);
    }

    /** Splits text into words after normalization (Text::norm); empty items are dropped. */
    private static function words(string $t): array
    {
        return array_values(array_filter(preg_split('/\s+/', Text::norm($t)) ?: [], fn($w) => $w !== ''));
    }

    /** Whether a negative (text + match type) blocks the given keyword from serving. */
    // Simplification compared to Google: close variants/inflections are not considered (Google does not block them for BROAD negatives either; for EXACT/PHRASE it works exactly as below).
    private static function blocks(array $neg, array $kw): bool
    {
        $n = self::words(mb_strtolower($neg['text']));
        $k = self::words(mb_strtolower($kw['text']));
        if (!$n) {
            return false;
        }
        if ($neg['match'] === 'EXACT') {
            return implode(' ', $n) === implode(' ', $k) && $kw['match'] === 'EXACT';
        }
        if ($neg['match'] === 'PHRASE') { // whole words in sequence (not a character substring: "cat" does not block "cattle")
            for ($i = 0; $i < count($k); $i++) {
                foreach ($n as $j => $w) {
                    if (($k[$i + $j] ?? null) !== $w) {
                        continue 2;
                    }
                }
                return true;
            }
            return false;
        }
        return !array_diff($n, $k);
    }

    /**
     * Builds the plan for the prepare_negative_keywords tool (campaign negatives or a shared list).
     * The order of operations within one mutate batch matters: [create the list (preOps)] -> removals -> additions -> [attach the list to the campaign (postOps)].
     * A new list gets the temporary resourceName sharedSets/-1 (placeholder), which later operations refer to.
     * API limits: 10000 negatives per campaign, 5000 per shared list; exceeding them is an error (fail-closed).
     * Collisions with active keywords and the offer are only warnings (they do not block), because the user may do this on purpose.
     */
    public static function build(array $req, array $s): array
    {
        $d = new Draft();
        $cid = Cfg::customerId();
        $match = $req['matchType'] ?? 'PHRASE';
        $target = $req['target'];
        $isList = isset($target['sharedList']);
        $sec = $isList ? 'Negative keyword list' : 'Campaign negatives';
        $listName = '';
        $listRn = null;
        $current = [];
        if ($isList) {
            $listName = Text::norm($target['sharedList']);
            if ($listName === '') {
                $d->error('List name cannot be empty');
            }
            $hits = array_values(array_filter($s['sharedSets'], fn($r) => Text::same((string) ($r['sharedSet']['name'] ?? ''), $listName)));
            if (count($hits) > 1) {
                $d->error("List name \"{$listName}\" matches several lists; rename one manually in Google Ads");
            }
            if (count($hits) === 1) {
                $listRn = (string) $hits[0]['sharedSet']['resourceName'];
                foreach ($s['sharedCriteria'] as $r) {
                    if (($r['sharedSet']['resourceName'] ?? null) === $listRn) {
                        $current[] = ['text' => (string) ($r['sharedCriterion']['keyword']['text'] ?? ''), 'match' => (string) ($r['sharedCriterion']['keyword']['matchType'] ?? ''), 'rn' => (string) $r['sharedCriterion']['resourceName']];
                    }
                }
            }
        } else {
            if (!$s['campaign']) {
                $d->error('Campaign not found');
            }
            foreach ($s['criteria'] as $r) {
                $c = $r['campaignCriterion'] ?? [];
                $current[] = ['text' => (string) ($c['keyword']['text'] ?? ''), 'match' => (string) ($c['keyword']['matchType'] ?? ''), 'rn' => (string) ($c['resourceName'] ?? '')];
            }
        }
        $campaignRn = "customers/$cid/campaigns/" . ($req['campaignId'] ?? $s['campaign']['id'] ?? 0);
        $placeholder = "customers/$cid/sharedSets/-1";
        $setRn = $listRn ?? $placeholder;
        $createOp = fn(string $text) => $isList
            ? ['sharedCriterionOperation' => ['create' => ['sharedSet' => $setRn, 'keyword' => ['text' => $text, 'matchType' => $match]]]]
            : ['campaignCriterionOperation' => ['create' => ['campaign' => $campaignRn, 'negative' => true, 'keyword' => ['text' => $text, 'matchType' => $match]]]];

        [$removeOps, $removedIdx] = self::removals($req, $current, $match, $isList, $sec, $d);
        $kept = array_values(array_filter($current, fn($c, $i) => !isset($removedIdx[$i]), ARRAY_FILTER_USE_BOTH));
        [$added, $createOps] = self::additions($req, $kept, $match, $sec, $createOp, $d);
        $total = count($kept) + count($added);
        if (!$isList && $total > 10000) {
            $d->error("Too many negatives in the campaign after the change: $total (max 10000)");
        }
        if ($isList && $total > 5000) {
            $d->error("Too many negatives in the list after the change: $total (max 5000)");
        }
        self::collisions($s, $added, $isList, $d);

        $preOps = [];
        $postOps = [];
        if ($isList && !$listRn && !$d->errors) {
            $preOps[] = ['sharedSetOperation' => ['create' => ['resourceName' => $placeholder, 'name' => $listName, 'type' => 'NEGATIVE_KEYWORDS']]];
            array_unshift($d->diff, ['section' => $sec, 'label' => 'New list', 'before' => null, 'after' => $listName, 'change' => 'add']);
        }
        if ($isList) {
            foreach ($s['attach'] as $c) {
                $already = $listRn && array_filter($s['campaignSharedSets'], fn($r) => (string) ($r['campaign']['id'] ?? '') === (string) $c['id'] && ($r['campaignSharedSet']['sharedSet'] ?? null) === $listRn);
                if ($already) {
                    $d->warn("List \"{$listName}\" is already attached to campaign \"{$c['name']}\"");
                    continue;
                }
                $postOps[] = ['campaignSharedSetOperation' => ['create' => ['campaign' => "customers/$cid/campaigns/{$c['id']}", 'sharedSet' => $setRn]]];
                $d->add('List attachment', 'Campaign', null, (string) $c['name'], 'add');
                if (($c['status'] ?? null) === 'ENABLED') {
                    $d->warn("Campaign \"{$c['name']}\" is enabled; the negatives take effect immediately");
                }
            }
        } elseif (($s['campaign']['status'] ?? null) === 'ENABLED') {
            $d->warn('The campaign is enabled; the negatives take effect immediately');
        }
        if (!$isList && ($s['campaign']['advertisingChannelType'] ?? null) === 'PERFORMANCE_MAX') {
            $d->warn('In Performance Max, negative keywords only apply to Search and Shopping traffic (not YouTube or Display)');
        }
        $all = [...$preOps, ...$removeOps, ...$createOps, ...$postOps];
        if (!$all && !$d->errors) {
            $d->error('Nothing to change');
        }
        $nAdded = count($added);
        $nRemoved = count($removedIdx);
        $plan = array_merge(Plan::base(), ['kind' => 'negative_keywords'], !$isList && !empty($req['campaignId']) ? ['campaignId' => $req['campaignId']] : [], [
            'summary' => $isList ? "Negative keyword list \"{$listName}\": +$nAdded −$nRemoved" : 'Campaign negatives "' . ($s['campaign']['name'] ?? $req['campaignId'] ?? '') . "\": +$nAdded −$nRemoved",
            'steps' => $all ? [['title' => $isList ? 'Negative keyword list' : 'Campaign negatives', 'operations' => $all]] : [],
            'beforeFingerprint' => self::fingerprint($s),
        ], $d->common(), $s['campaign'] ? ['campaignState' => Text::stateLabel($s['campaign'])] : []);
        return Undo::attach($plan, $s);
    }

    /** @return array{list<array>, array<int,true>} removal operations and the indexes of removed items from $current */
    // A removal matches the text case-insensitively AND the match type ($match): the same word with a different match type is a different negative.
    private static function removals(array $req, array $current, string $match, bool $isList, string $sec, Draft $d): array
    {
        $removed = [];
        $ops = [];
        foreach ($req['remove'] ?? [] as $t) {
            $hit = null;
            foreach ($current as $i => $c) {
                if (!isset($removed[$i]) && Text::same($c['text'], $t) && $c['match'] === $match) {
                    $hit = $i;
                    break;
                }
            }
            if ($hit === null) {
                $cur = implode('; ', array_map(fn($c) => "{$c['text']} [{$c['match']}]", array_slice($current, 0, 20))) ?: 'none';
                $d->error('No such negative "' . Text::norm($t) . "\" ($match). Current: $cur");
                continue;
            }
            $removed[$hit] = true;
            $ops[] = [$isList ? 'sharedCriterionOperation' : 'campaignCriterionOperation' => ['remove' => $current[$hit]['rn']]];
            $d->add($sec, 'Negative (' . self::MATCH_LABEL[$match] . ')', $current[$hit]['text'], null, 'remove');
        }
        return [$ops, $removed];
    }

    /** @return array{list<array{text: string, match: string}>, list<array>} added negatives and create operations */
    // Validation of each keyword: non-empty, <=80 characters, <=10 words, no special characters (BAD_CHARS), no duplicates (against existing ones and within this request).
    private static function additions(array $req, array $kept, string $match, string $sec, callable $createOp, Draft $d): array
    {
        $added = [];
        $ops = [];
        foreach ($req['keywords'] as $raw) {
            $t = Text::norm($raw);
            if ($t === '') {
                $d->error('A negative cannot be empty');
                continue;
            }
            if (Text::len($t) > self::MAX_CHARS) {
                $d->error('Negative has ' . Text::len($t) . ' characters (max ' . self::MAX_CHARS . "): \"{$t}\"");
                continue;
            }
            if (count(self::words($t)) > self::MAX_WORDS) {
                $d->error('Negative has ' . count(self::words($t)) . ' words (max ' . self::MAX_WORDS . "): \"{$t}\"");
                continue;
            }
            if (preg_match(self::BAD_CHARS, $t, $m)) {
                $d->error("Character \"{$m[0]}\" is not allowed in negative \"{$t}\"; choose the match type with the matchType field, not with quotes or brackets");
                continue;
            }
            $dup = array_filter($kept, fn($c) => Text::same($c['text'], $t) && $c['match'] === $match) || array_filter($added, fn($a) => Text::same($a['text'], $t));
            if ($dup) {
                $d->error("Negative \"{$t}\" ($match) already exists or is repeated in the request (case does not matter)");
                continue;
            }
            $added[] = ['text' => $t, 'match' => $match];
            $ops[] = $createOp($t);
            $d->add($sec, 'New negative (' . self::MATCH_LABEL[$match] . ')', null, $t, 'add');
        }
        return [$added, $ops];
    }

    /** Warnings: overlap with active keywords (Search) and collisions with the offer (non-blocking). */
    // Collisions never block the plan (warn only); a BROAD negative can cut off your own traffic, so we show them in the diff before approval.
    private static function collisions(array $s, array $added, bool $isList, Draft $d): void
    {
        if (!$isList && $s['activeKeywords']) {
            $kws = array_map(fn($r) => ['text' => (string) ($r['adGroupCriterion']['keyword']['text'] ?? ''), 'match' => (string) ($r['adGroupCriterion']['keyword']['matchType'] ?? '')], $s['activeKeywords']);
            foreach ($added as $a) {
                foreach ($kws as $k) {
                    if (self::blocks($a, $k)) {
                        $d->warn("Negative \"{$a['text']}\" will block the active keyword \"{$k['text']}\"");
                        break;
                    }
                }
            }
        }
        if (!empty($s['offer']) && $added) {
            $cols = OfferCollisions::find($added, $s['offer']);
            foreach ($cols as $c) {
                $d->diff[] = ['section' => 'Offer collisions', 'label' => "Negative \"{$c['negative']}\"", 'before' => null, 'after' => "collides with: {$c['source']} \"{$c['text']}\"", 'change' => 'info', 'level' => 'warn'];
            }
            $n = count(array_unique(array_column($cols, 'negative')));
            if ($n) {
                $d->warn("$n of " . count($added) . ' negatives match whole words in the campaign offer texts (see the "Offer collisions" section); check that you are not cutting off your own traffic');
            }
        }
    }
}
