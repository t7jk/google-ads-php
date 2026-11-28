<?php
// GA4 tools (port of ga4_report from tools-read.ts and tools-ga4.ts). Logic in App\Ga4\Tools.
// Schemas and descriptions mirror the Node version.
declare(strict_types=1);

use App\Ga4\Tools;
use App\Report\Range;

return [
    [
        'name' => 'ga4_report',
        'title' => 'GA4 report',
        'description' => 'Read-only. Sessions and events from GA4' . ((($p = (string) config('landing_path_prefix', '')) !== '') ? ' for landing pages ' . $p : '') . '. The response text contains a summary and a TSV table of rows (sorted descending by the first metric, at most limit rows, default 100; "showing N of M"). Path filter: pathMatch=prefix (default: exactly /lp2 and everything under /lp2/), contains, exact or any (no filter). The dimensions and metrics parameters narrow the columns (default landingPage, sessionSource, sessionMedium, sessionCampaignName and sessions, keyEvents). eventName is an exact event filter; note: an eventName filter with the sessions metric gives misleading results, so with eventName the default metric is eventCount. view=leads_by_source is a ready-made view of the generate_lead event (date × sessionSourceMedium, eventCount, no path filter, because the event occurs on the thank-you page, not on the session landing page) with a total for google / cpc. When the result is empty, it shows the 5 most frequent landing pages without the filter.',
        'inputSchema' => [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [
                'range' => Range::schema(),
                'pathMatch' => [
                    'default' => 'prefix',
                    'description' => 'How the landing page path is matched',
                    'type' => 'string',
                    'enum' => ['prefix', 'contains', 'exact', 'any'],
                ],
                'path' => [
                    'description' => 'Path for the filter; defaults to LANDING_PATH_PREFIX from the configuration',
                    'type' => 'string',
                ],
                'dimensions' => [
                    'description' => 'Dimensions (allow-list: sessionSourceMedium, sessionSource, sessionMedium, sessionCampaignName, landingPage, eventName, date, deviceCategory); defaults to landingPage, sessionSource, sessionMedium, sessionCampaignName',
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['sessionSourceMedium', 'sessionSource', 'sessionMedium', 'sessionCampaignName', 'landingPage', 'eventName', 'date', 'deviceCategory'],
                    ],
                ],
                'metrics' => [
                    'description' => 'Metrics (allow-list: sessions, keyEvents, eventCount, engagementRate, totalUsers); defaults to sessions, keyEvents (with eventName: eventCount)',
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['sessions', 'keyEvents', 'eventCount', 'engagementRate', 'totalUsers'],
                    ],
                ],
                'eventName' => [
                    'description' => 'Exact event name filter, combined with the path filter; with it the default metric is eventCount',
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 100,
                ],
                'view' => [
                    'description' => 'Ready-made view: leads_by_source = the generate_lead event by day and source/medium, no path filter, with a google / cpc total',
                    'type' => 'string',
                    'enum' => ['leads_by_source'],
                ],
                'limit' => [
                    'default' => 100,
                    'description' => 'How many rows to show in the text (default 100)',
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 500,
                ],
            ],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => Tools::report(...),
    ],
    [
        'name' => 'ga4_key_events',
        'title' => 'GA4 key events',
        'description' => 'Read-only. The list of key events of the GA4 property (name, counting method, creation date, whether it can be unmarked) and the events of the last 7 days with counts and a keyEvent yes/no column. Warns when an automatic/enhanced GA4 event is marked as key (first_visit, session_start, page_view, user_engagement, scroll, click, view_search_results, file_download, video_*; form_start/form_submit are automatic form events - they usually should not be key alongside generate_lead) and when generate_lead occurs but is not a key event. Make changes with prepare_ga4_key_events. The GA4 change history is in the GA4 UI (Admin → Change history) and in server_events.',
        'inputSchema' => [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => fn(array $args): array => Tools::keyEvents(),
    ],
    [
        'name' => 'ga4_data_streams',
        'title' => 'GA4 data streams',
        'description' => 'Read-only. Data streams of the GA4 property: type, measurement ID (G-...), default URL and name.',
        'inputSchema' => [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => fn(array $args): array => Tools::dataStreams(),
    ],
    [
        'name' => 'ga4_ads_links',
        'title' => 'GA4 links to Google Ads',
        'description' => 'Read-only. Links of the GA4 property to Google Ads accounts; compares customerId with the connector ADS_CUSTOMER_ID: OK (same account), not linked to the connector account, or another account.',
        'inputSchema' => [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => fn(array $args): array => Tools::adsLinks(),
    ],
];
