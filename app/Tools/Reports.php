<?php
// Reports (port of src/mcp/tools-reports.ts): pmax_report and keyword_ideas. Logic in App\Report\PmaxReport and KeywordIdeas.
// Schemas and descriptions mirror the Node version.
declare(strict_types=1);

use App\Report\{KeywordIdeas, PmaxReport, Range};

return [
    [
        'name' => 'pmax_report',
        'title' => 'Performance Max campaign report',
        'description' => 'Read-only report for PMax campaigns. view: assets (asset group assets: status and metrics), channels (split by network), placements (placements - impressions only), locations (user locations), hours (day of week and hour), search_categories (search term categories). Compact TSV table: cost in PLN, CTR, CPA; with compareTo also Δ columns relative to the previous period or year. The placements view has impressions only, search_categories has no cost.',
        'inputSchema' => [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [
                'campaign' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Campaign ID (digits) or its exact name',
                ],
                'view' => [
                    'type' => 'string',
                    'enum' => ['assets', 'channels', 'placements', 'locations', 'hours', 'search_categories'],
                    'description' => 'assets | channels | placements | locations | hours | search_categories',
                ],
                'range' => Range::schema('Date range: LAST_7_DAYS, LAST_30_DAYS, LAST_90_DAYS, ALL_TIME or {from,to} YYYY-MM-DD'),
                'compareTo' => [
                    'description' => 'Compare with the previous period of the same length or with the year earlier (not for ALL_TIME)',
                    'type' => 'string',
                    'enum' => ['previous_period', 'previous_year'],
                ],
                'limit' => [
                    'default' => 50,
                    'description' => 'Maximum number of rows (default 50)',
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 500,
                ],
            ],
            'required' => ['campaign', 'view'],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => PmaxReport::run(...),
    ],
    [
        'name' => 'keyword_ideas',
        'title' => 'Keyword Planner',
        'description' => 'Keyword ideas from the Google Keyword Planner (read-only): average monthly searches, competition, top-of-page bid (PLN) and a 12-month trend. Provide keywords and/or url (allowed domain). Defaults to Poland and the Polish language.',
        'inputSchema' => [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [
                'keywords' => [
                    'description' => 'Seed keywords (up to 20)',
                    'maxItems' => 20,
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 80,
                    ],
                ],
                'url' => [
                    'description' => 'Page URL from an allowed domain',
                    'type' => 'string',
                ],
                'locations' => [
                    'description' => 'Location names or geoTargetConstants IDs; defaults to Poland',
                    'maxItems' => 10,
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'minLength' => 1,
                    ],
                ],
                'language' => [
                    'description' => 'languageConstants/ID or just the ID; defaults to 1030 (Polish)',
                    'type' => 'string',
                ],
                'limit' => [
                    'default' => 50,
                    'description' => 'Number of ideas (default 50)',
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                ],
            ],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => KeywordIdeas::run(...),
    ],
];
