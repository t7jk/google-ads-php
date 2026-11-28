<?php
// Read tools (port of src/mcp/tools-read.ts): run_gaql, account overview, campaigns, audit, search terms, conversions, change history.
// Logic lives in App\Report\*; here only the definitions (name, description, schema - mirroring Node). Pattern:
// the handler receives the arguments array and returns Mcp::text/json/error; exceptions (AdsApiError, UserError, ReauthRequired...)
// are caught by McpServer and turned into isError via Errors::describe - no own guard() needed.
declare(strict_types=1);

use App\Ads\{Params, Table, UserError};
use App\Google\Ads;
use App\Lib\Mcp;
use App\Report\{CampaignTools, ChangeHistory, ConversionTools, Range};

return [
    [
        'name' => 'run_gaql',
        'title' => 'GAQL query',
        'description' => 'Runs any read-only GAQL query (must start with SELECT). By default returns a compact table (tab-separated columns, no resourceName, and for *Micros fields an extra *Micros_zl column in PLN); format=json returns raw JSON (only then does structuredContent.rows hold the raw rows with resourceName; in table mode it holds the same flattened rows as the text). Returns up to maxRows rows (default 200, max 1000) starting from offset. When the text exceeds maxChars, it cuts at a row boundary and writes "showing X of Y rows - narrow the query or use maxRows/offset".',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 1],
                'maxRows' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 200],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000, 'default' => 0, 'description' => 'How many initial rows to skip (pagination)'],
                'format' => ['type' => 'string', 'enum' => ['table', 'json'], 'default' => 'table'],
                'maxChars' => ['type' => 'integer', 'minimum' => 1000, 'maximum' => 60000, 'default' => 20000, 'description' => 'Maximum length of the response text'],
            ],
            'required' => ['query'],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => function (array $args): array {
            $q = trim((string) $args['query']);
            if (!preg_match('/^select\b/i', $q)) {
                return Mcp::error('Only queries starting with SELECT are allowed.');
            }
            $maxRows = Params::int($args, 'maxRows', 1, 1000, 200);
            $offset = Params::int($args, 'offset', 0, 1_000_000, 0);
            $limitChars = Params::int($args, 'maxChars', 1000, 60000, 20000);
            $format = ($args['format'] ?? 'table');
            if (!in_array($format, ['table', 'json'], true)) {
                throw new UserError('Parameter format must be table or json');
            }

            $need = $offset + $maxRows + 1;
            $limited = preg_match('/\blimit\s+\d+/i', $q) ? $q : preg_replace('/;\s*$/', '', $q) . " LIMIT $need";
            $all = Ads::search($limited, $need);
            $rows = array_slice($all, $offset, $maxRows);
            $moreRows = count($all) > $offset + $maxRows;
            $total = count($all);

            if ($format === 'json') {
                $n = count($rows);
                while ($n > 1 && strlen(json_encode(array_slice($rows, 0, $n), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > $limitChars) {
                    $n = max(1, (int) floor($n * 0.8));
                }
                $shown = $n;
                $body = json_encode(array_slice($rows, 0, $n), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $flat = array_map([Table::class, 'flattenRow'], $rows);
                $shown = Table::rowsThatFit($flat, $limitChars);
                $part = array_slice($flat, 0, $shown);
                $cols = Table::columnsOf($part);
                $body = $shown ? implode("\n", [Table::tsvHeader($cols), ...array_map(fn($r) => Table::tsvLine($cols, $r), $part)]) : '';
            }

            $cut = $shown < count($rows);
            $nextOffset = $cut || $moreRows ? $offset + $shown : null;
            $atLeast = $moreRows ? 'at least ' : '';
            $head = "The query returned {$atLeast}{$total} rows" . ($offset ? " (from position $offset)" : '') . ", showing $shown.";
            $note = '';
            if ($cut) {
                $note = "\nshowing $shown of {$atLeast}" . ($total - $offset) . " rows - narrow the query or use maxRows/offset (next offset: $nextOffset).";
            } elseif ($moreRows) {
                $note = "\nThe result is longer than maxRows - next chunk: offset=$nextOffset.";
            }
            $shownRows = array_slice($rows, 0, $shown);
            return Mcp::json("$head\n$body$note", [
                'rows' => $format === 'json' ? $shownRows : array_map([Table::class, 'flattenRow'], $shownRows),
                'totalRows' => $total, 'shown' => $shown, 'truncated' => $cut || $moreRows,
                'totalKnown' => !$moreRows, 'nextOffset' => $nextOffset,
            ]);
        },
    ],
    [
        'name' => 'account_overview',
        'title' => 'Account overview',
        'description' => 'Basic Google Ads account data (name, currency, time zone, auto-tagging, final URL suffix, tracking template), the number of campaigns by status and today\'s usage of the daily API operations limit.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => fn(array $args): array => CampaignTools::accountOverview(),
    ],
    [
        'name' => 'list_campaigns',
        'title' => 'Campaign list',
        'description' => 'List of campaigns with daily budget, strategy and metrics (impressions, clicks, cost in PLN, conversions, CTR, average CPC) for the chosen date range.',
        'inputSchema' => [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [
                'range' => Range::schema(),
            ],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => CampaignTools::listCampaigns(...),
    ],
    [
        'name' => 'campaign_details',
        'title' => 'Campaign details',
        'description' => 'Campaign details (ID or exact name): ad groups, RSA ads, keywords, negative keywords, locations, networks and custom parameters. For Performance Max: settings, asset groups with assets, signals and campaign assets. PMax asset performance labels are not available in the API - showing status and policy.',
        'inputSchema' => [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [
                'campaign' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Campaign ID (digits) or its exact name',
                ],
                'range' => Range::schema(),
                'daily' => [
                    'default' => false,
                    'description' => 'Daily breakdown of metrics, with zero days (account time zone); zero padding does not apply to ALL_TIME',
                    'type' => 'boolean',
                ],
            ],
            'required' => ['campaign'],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => CampaignTools::campaignDetails(...),
    ],
    [
        'name' => 'campaign_health_check',
        'title' => 'Campaign audit',
        'description' => 'Audits a campaign (ID or exact name) for problems: end date, conversions and goals (including the LP2 action; secondary actions are not reported, but these are: a primary action not counted in conversions, a goal without a primary action, GA4 navigation events as primary actions), the campaign primary status with reasons, assets under review, ad strength POOR/AVERAGE, no impressions after enabling (12 h IMPORTANT, 24 h CRITICAL, allowing for 3 h of statistics delay), campaign logos, URLs outside the allowed domains, minimum and recommended Performance Max assets, image sizes and aspect ratios, video, disapproved and limited assets, location target, asset automation, lead form, networks and negative keywords in Search, and the budget. The result is sorted: CRITICAL, IMPORTANT, INFO.',
        'inputSchema' => [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [
                'campaign' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Campaign ID (digits) or its exact name',
                ],
            ],
            'required' => ['campaign'],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => CampaignTools::healthCheck(...),
    ],
    [
        'name' => 'search_terms',
        'title' => 'Search terms',
        'description' => 'Search terms of a campaign (ID or exact name), with cost, conversions and cost per conversion. The source depends on the campaign type: Search and Shopping - search_term_view, sorted by clicks; Performance Max - campaign_search_term_view, sorted by impressions (only terms above the Google privacy threshold; the coverage relative to campaign impressions is reported). For types without such data (e.g. Display, Video) it says so plainly.',
        'inputSchema' => [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [
                'campaign' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Campaign ID (digits) or its exact name',
                ],
                'range' => Range::schema(),
                'limit' => [
                    'default' => 200,
                    'description' => 'Maximum number of terms',
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 1000,
                ],
                'minImpressions' => [
                    'default' => 0,
                    'description' => 'Skips terms with fewer impressions',
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 1000000,
                ],
                'onlyWithCost' => [
                    'default' => false,
                    'description' => 'true = only terms with a cost greater than 0',
                    'type' => 'boolean',
                ],
                'minCost' => [
                    'description' => 'Minimum term cost in PLN (skips cheaper ones)',
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1000000,
                ],
                'noConversions' => [
                    'default' => false,
                    'description' => 'true = only terms without conversions',
                    'type' => 'boolean',
                ],
                'ngrams' => [
                    'default' => 0,
                    'description' => '0 = off; 1-3 = an extra table of n-grams (words, pairs, triples) from terms without conversions, excluding stop words, sorted by cost',
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 3,
                ],
            ],
            'required' => ['campaign'],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => CampaignTools::searchTerms(...),
    ],
    [
        'name' => 'list_conversion_actions',
        'title' => 'Conversion actions',
        'description' => 'Read-only. The list of configured conversion actions (name, status, type, category; "manual change in the Google Ads UI only" for types the API cannot change) and the account conversion tracking settings: acceptance of the customer data terms, enhanced conversions (enablement_setting) and the conversion-managing account. The settings fields are read-only in the API: enable them manually in the UI (Goals → Settings).',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => fn(array $args): array => ConversionTools::list(),
    ],
    [
        'name' => 'get_conversion_tag',
        'title' => 'LP2 conversion tag data',
        'description' => ConversionTools::tagDescription(),
        'inputSchema' => [
            'type' => 'object',
            'properties' => [],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => fn(array $args): array => ConversionTools::tag(),
    ],
    [
        'name' => 'change_history',
        'title' => 'Change history',
        'description' => 'Change history: entries of this connector\'s journal (approved, failed and refused plans) and account changes from change_event (UI, API, other tools; Google keeps about 30 days). source=connector | account | all (default all). Account entries: date, user, client type (e.g. UI), resource, operation, changed fields and, when the API provides them, old → new values. Note: change_event does not cover all resources (e.g. conversion goals). Time shown in the account time zone.',
        'inputSchema' => [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [
                'limit' => [
                    'default' => 20,
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                ],
                'source' => [
                    'default' => 'all',
                    'description' => 'Source of the entries',
                    'type' => 'string',
                    'enum' => ['connector', 'account', 'all'],
                ],
                'days' => [
                    'default' => 30,
                    'description' => 'How many days back to read account changes (max 30)',
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 30,
                ],
                'campaign' => [
                    'description' => 'Campaign ID: limits account changes to this campaign',
                    'type' => 'string',
                ],
            ],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => ChangeHistory::run(...),
    ],
];
