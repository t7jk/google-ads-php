<?php
declare(strict_types=1);

namespace App\Report;

use App\Ads\AdsApiError;
use App\Ads\Errors;
use App\Ads\Params;
use App\Ads\Table;
use App\Google\Ads;
use App\Lp2\UrlHost;
use App\Lib\Mcp;

/** The keyword_ideas tool (port of src/mcp/tools-reports.ts): Keyword Planner, read-only. */
final class KeywordIdeas
{
    private const DEFAULT_GEO = '2616'; // Poland
    private const DEFAULT_LANGUAGE = '1030'; // Polish

    public static function run(array $args): array
    {
        $keywords = isset($args['keywords']) && is_array($args['keywords']) ? array_values($args['keywords']) : null;
        $url = isset($args['url']) && is_string($args['url']) ? $args['url'] : null;
        $locations = isset($args['locations']) && is_array($args['locations']) ? array_values($args['locations']) : null;
        $language = isset($args['language']) && is_string($args['language']) ? $args['language'] : null;
        $limit = Params::int($args, 'limit', 1, 200, 50);
        try {
            if (!$keywords && !$url) {
                return Mcp::error('Provide at least keywords or url.');
            }
            if ($url && !self::urlAllowed($url)) {
                return Mcp::error('The url must be https and from an allowed domain: ' . implode(', ', UrlHost::allowed()) . '.');
            }
            $geo = [];
            foreach ($locations ?: [self::DEFAULT_GEO] as $l) {
                $l = (string) $l;
                if (preg_match('/^(?:geoTargetConstants\/)?(\d+)$/', trim($l), $m)) {
                    $geo[] = "geoTargetConstants/{$m[1]}";
                    continue;
                }
                $c = Ads::suggestGeoTargetConstants([$l])[0] ?? null;
                if (!$c || $c['id'] === '') {
                    return Mcp::error("Location \"{$l}\" not found. Provide a more precise name or an ID.");
                }
                $geo[] = "geoTargetConstants/{$c['id']}";
            }
            if ($language !== null && !preg_match('/^(languageConstants\/)?\d+$/', trim($language))) {
                return Mcp::error('Parameter language must be a numeric language ID (e.g. 1030) or languageConstants/1030.');
            }
            $lang = 'languageConstants/' . preg_replace('/^languageConstants\//', '', trim($language ?? self::DEFAULT_LANGUAGE));
            $res = Ads::generateKeywordIdeas(['keywords' => $keywords, 'url' => $url, 'geoTargetConstants' => $geo, 'language' => $lang, 'pageSize' => $limit]);
            $rows = array_map(self::ideaRow(...), array_slice($res, 0, $limit));
            if (!$rows) {
                return Mcp::json('The planner returned no ideas for the given input.', ['rows' => []]);
            }
            return Mcp::json('Keyword ideas: ' . count($rows) . "\n" . Table::toTsv($rows), ['rows' => $rows]);
        } catch (AdsApiError $e) {
            if (in_array('DEVELOPER_TOKEN_NOT_APPROVED', $e->codes, true) || preg_match('/explorer access/i', $e->getMessage())) {
                return Mcp::error('Keyword Planner is unavailable: the developer token has Explorer access level. Apply for Basic or Standard access in the Google Ads API Center. Error code: ' . implode(', ', $e->codes) . '.');
            }
            return Mcp::error(Errors::describe($e, 'read'));
        }
    }

    /** https and a host from the allowed list (as in Node: the URL must be parseable). */
    private static function urlAllowed(string $url): bool
    {
        return UrlHost::isAllowed($url);
    }

    private static function ideaRow(array $r): array
    {
        $m = $r['keywordIdeaMetrics'] ?? [];
        $monthly = array_map(fn($x) => Js::num($x['monthlySearches'] ?? null), $m['monthlySearchVolumes'] ?? []);
        $bid = fn(string $k) => ($m[$k] ?? null) === null ? null : Js::round2(Js::num($m[$k]) / 1e6);
        return [
            'keyword' => $r['text'] ?? null,
            'avg_monthly_searches' => ($m['avgMonthlySearches'] ?? null) === null ? null : Js::num($m['avgMonthlySearches']),
            'competition' => $m['competition'] ?? null,
            'bid_low_pln' => $bid('lowTopOfPageBidMicros'),
            'bid_high_pln' => $bid('highTopOfPageBidMicros'),
            'trend_12m' => implode(',', array_slice($monthly, -12)),
        ];
    }
}
