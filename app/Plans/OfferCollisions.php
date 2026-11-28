<?php
declare(strict_types=1);

namespace App\Plans;

use App\Google\Ads;

/**
 * Collisions of negatives with a campaign's offer (port of offer-collisions.ts): pure whole-word matching after normalization
 * plus a loader of offer texts. It does not block execution, it only warns.
 * NOTE: the folding and stemming below are intentionally tuned for Polish text (the account's ads are in Polish).
 */
final class OfferCollisions
{
    private const SUFFIXES = ['ami', 'ach', 'om', 'ow', 'ie', 'ia', 'ej', 'a', 'y', 'i', 'e', 'u', 'o'];

    /** Lowercase, without Polish characters (and other diacritics). */
    public static function fold(string $t): string
    {
        $t = strtr(mb_strtolower($t), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
        if (class_exists(\Normalizer::class)) {
            $t = (string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($t, \Normalizer::FORM_D));
        }
        return $t;
    }

    /** @return list<string> */
    public static function wordsOf(string $t): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', self::fold($t)) ?: [], fn($w) => $w !== ''));
    }

    /** Simple Polish stem: "gier"->"gr", "gry"/"gra"/"grach"->"gr", "kursy"->"kurs". A heuristic, not full lemmatization. */
    public static function stem(string $w): string
    {
        $s = $w;
        if (preg_match('/ier$/', $s) && strlen($s) <= 5) {
            return (string) preg_replace('/ier$/', 'r', $s);
        }
        foreach (self::SUFFIXES as $suf) {
            if (strlen($s) - strlen($suf) >= 2 && str_ends_with($s, $suf)) {
                return substr($s, 0, -strlen($suf));
            }
        }
        return $s;
    }

    /** Two words are the same form: equal, same stem, or a common prefix of 4+ characters. */
    public static function sameWord(string $a, string $b): bool
    {
        if ($a === $b || self::stem($a) === self::stem($b)) {
            return true;
        }
        if (strlen($a) >= 4 && strlen($b) >= 4) {
            $i = 0;
            while ($i < strlen($a) && $i < strlen($b) && $a[$i] === $b[$i]) {
                $i++;
            }
            return $i >= 4;
        }
        return false;
    }

    /** Whether the negative matches the text as whole words (PHRASE/EXACT: consecutive; BROAD: any order). @param array{text: string, match: string} $neg */
    public static function negativeHitsText(array $neg, string $text): bool
    {
        $n = array_values(array_filter(self::wordsOf($neg['text']), fn($w) => strlen($w) > 1));
        $t = self::wordsOf($text);
        if (!$n || !$t) {
            return false;
        }
        if ($neg['match'] === 'BROAD') {
            foreach ($n as $w) {
                if (!array_filter($t, fn($x) => self::sameWord($w, $x))) {
                    return false;
                }
            }
            return true;
        }
        for ($i = 0; $i + count($n) <= count($t); $i++) {
            foreach ($n as $j => $w) {
                if (!self::sameWord($w, $t[$i + $j])) {
                    continue 2;
                }
            }
            return true;
        }
        return false;
    }

    /** Up to 2 hits per negative. @return list<array{negative: string, source: string, text: string}> */
    public static function find(array $negs, array $offer): array
    {
        $out = [];
        foreach ($negs as $n) {
            $hits = 0;
            foreach ($offer as $o) {
                if (self::negativeHitsText($n, $o['text'])) {
                    $out[] = ['negative' => $n['text'], 'source' => $o['source'], 'text' => $o['text']];
                    if (++$hits >= 2) {
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Campaign offer texts: RSA, PMax asset group texts, ad group/asset group names, positive keywords.
     * A read error of one source does not break the plan. @param list<string> $campaignIds @return array{texts: list<array>, errors: list<string>}
     */
    public static function loadOfferTexts(array $campaignIds): array
    {
        $ids = array_values(array_unique(array_filter($campaignIds, fn($i) => preg_match('/^\d+$/', (string) $i))));
        if (!$ids) {
            return ['texts' => [], 'errors' => []];
        }
        $in = 'campaign.id IN (' . implode(', ', $ids) . ')';
        $texts = [];
        $errors = [];
        $run = function (string $label, string $q, callable $f) use (&$errors): void {
            try {
                foreach (Ads::search($q) as $r) {
                    $f($r);
                }
            } catch (\Throwable $e) {
                $errors[] = "$label: " . $e->getMessage();
            }
        };
        $run('RSA ads', "SELECT ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions FROM ad_group_ad WHERE $in AND ad_group_ad.status != 'REMOVED'", function ($r) use (&$texts) {
            $rsa = $r['adGroupAd']['ad']['responsiveSearchAd'] ?? [];
            foreach ($rsa['headlines'] ?? [] as $h) {
                if (!empty($h['text'])) {
                    $texts[] = ['source' => 'ad headline', 'text' => (string) $h['text']];
                }
            }
            foreach ($rsa['descriptions'] ?? [] as $d) {
                if (!empty($d['text'])) {
                    $texts[] = ['source' => 'ad description', 'text' => (string) $d['text']];
                }
            }
        });
        $run('asset group texts', "SELECT asset_group_asset.field_type, asset.text_asset.text FROM asset_group_asset WHERE $in AND asset_group_asset.status != 'REMOVED' AND asset.type = 'TEXT'", function ($r) use (&$texts) {
            if (!empty($r['asset']['textAsset']['text'])) {
                $texts[] = ['source' => 'asset group text (' . strtolower((string) ($r['assetGroupAsset']['fieldType'] ?? '')) . ')', 'text' => (string) $r['asset']['textAsset']['text']];
            }
        });
        $run('asset groups', "SELECT asset_group.name FROM asset_group WHERE $in AND asset_group.status != 'REMOVED'", function ($r) use (&$texts) {
            if (!empty($r['assetGroup']['name'])) {
                $texts[] = ['source' => 'asset group name', 'text' => (string) $r['assetGroup']['name']];
            }
        });
        $run('ad groups', "SELECT ad_group.name FROM ad_group WHERE $in AND ad_group.status != 'REMOVED'", function ($r) use (&$texts) {
            if (!empty($r['adGroup']['name'])) {
                $texts[] = ['source' => 'ad group name', 'text' => (string) $r['adGroup']['name']];
            }
        });
        $run('keywords', "SELECT ad_group_criterion.keyword.text FROM ad_group_criterion WHERE $in AND ad_group_criterion.type = 'KEYWORD' AND ad_group_criterion.negative = FALSE AND ad_group_criterion.status = 'ENABLED'", function ($r) use (&$texts) {
            if (!empty($r['adGroupCriterion']['keyword']['text'])) {
                $texts[] = ['source' => 'keyword', 'text' => (string) $r['adGroupCriterion']['keyword']['text']];
            }
        });
        return ['texts' => $texts, 'errors' => $errors];
    }
}
