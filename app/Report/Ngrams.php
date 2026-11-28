<?php
declare(strict_types=1);

namespace App\Report;

/** Port src/mcp/ngrams.ts: n-grams of search terms (pure function, no network). */
final class Ngrams
{
    /** Polish (and a few English) stop words skipped in n-grams. Intentionally Polish: they match real search terms of Polish-language campaigns. */
    private const STOPWORDS = [
        'i', 'w', 'na', 'z', 'ze', 'do', 'dla', 'od', 'o', 'a', 'się', 'sie', 'po', 'przy', 'u', 'za', 'to', 'że', 'czy', 'oraz', 'lub', 'albo',
        'jak', 'co', 'nad', 'pod', 'przez', 'bez', 'the', 'of', 'and', 'for', 'in',
    ];

    /** @return list<string> */
    public static function tokenize(string $term): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($term)) ?: [];
        return array_values(array_filter($words, fn($w) => $w !== '' && !in_array($w, self::STOPWORDS, true)));
    }

    /**
     * N-grams (n = 1..3) computed from terms WITHOUT conversions, excluding stop words; sorted by cost descending.
     * Each term counts a given n-gram once (even if it repeats within the term).
     * @param list<array{term:string,impressions,clicks,cost,conversions}> $terms
     * @return list<array{ngram:string,terms:int,impressions,clicks,cost,conversions}>
     */
    public static function compute(array $terms, int $n): array
    {
        if ($n < 1 || $n > 3) {
            return [];
        }
        $acc = [];
        foreach ($terms as $t) {
            if ($t['conversions'] > 0) {
                continue;
            }
            $words = self::tokenize($t['term']);
            $seen = [];
            for ($i = 0; $i + $n <= count($words); $i++) {
                $g = implode(' ', array_slice($words, $i, $n));
                if (isset($seen[$g])) {
                    continue;
                }
                $seen[$g] = true;
                $acc[$g] ??= ['ngram' => (string) $g, 'terms' => 0, 'impressions' => 0, 'clicks' => 0, 'cost' => 0, 'conversions' => 0];
                $acc[$g]['terms']++;
                $acc[$g]['impressions'] += $t['impressions'];
                $acc[$g]['clicks'] += $t['clicks'];
                $acc[$g]['cost'] += $t['cost'];
                $acc[$g]['conversions'] += $t['conversions'];
            }
        }
        $rows = array_map(fn($r) => ['cost' => Js::round2($r['cost'])] + $r, array_values($acc));
        usort($rows, fn($a, $b) => $b['cost'] <=> $a['cost'] ?: $b['clicks'] <=> $a['clicks'] ?: self::compareText($a['ngram'], $b['ngram']));
        return $rows;
    }

    /** localeCompare (alphabetical order, locale-aware when intl is available). */
    private static function compareText(string $a, string $b): int
    {
        static $collator = null;
        $collator ??= class_exists(\Collator::class) ? new \Collator('en_US') : false;
        return $collator ? (int) $collator->compare($a, $b) : strcmp($a, $b);
    }
}
