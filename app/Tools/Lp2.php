<?php
// LP2 tools (port of src/mcp/tools-lp2.ts): lp2_leads, verify_lp2. Logic in App\Lp2\LeadsReport and App\Lp2\Verify.
declare(strict_types=1);

use App\Lp2\{LeadsReport, Verify};

return [
    [
        'name' => 'lp2_leads',
        'title' => 'lp2 leads (aggregates)',
        'description' => 'Read-only: aggregates of lp2 form submissions from the lp2-admin endpoint (api/ads-leads.php; LP2_ADMIN_API_URL and LP2_ADMIN_API_TOKEN): day, source (google = Google Ads attribution, other), with or without a click identifier (gclid/gbraid/wbraid), count. Never returns names, phone numbers, e-mails or submission contents. For comparison it adds Google Ads conversions from the same days (the CONVERSION_ACTION_ID action, if set). When the lp2 endpoint is not deployed, it says so plainly. Maximum range 90 days.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$', 'description' => 'Range start YYYY-MM-DD (default 30 days back)'],
                'to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$', 'description' => 'Range end YYYY-MM-DD (default today)'],
            ],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => fn(array $args): array => LeadsReport::run($args),
    ],
    [
        'name' => 'verify_lp2',
        'title' => 'LP2 landing page verification',
        'description' => 'Read-only: fetches the landing page (plain GET requests, no browser and no cookies) and checks: (1) redirects (codes, chain) and whether the gclid, gbraid, wbraid parameters survive them (each separately, test values - a request with parameters may be recorded in the page statistics); (2) the presence of the gtag tag, the AW- ID and the label from get_conversion_tag, and a heuristic whether the conversion event fires after the form is submitted (submit) or on page load (load); code from GTM and third-party domains is invisible (then unknown, with an explanation); (3) forbidden phrases (from LP2_FORBIDDEN_PHRASES or the parameter) with context: element kind, snippet, hidden state (heuristic: hidden, display:none, classes hidden/d-none/is-hidden, also via ancestors) - comments, <script> and <style> are marked separately as invisible content; (4) form <select> lists with label and options; (5) Consent Mode v2 mode (gtag(\'consent\',\'default\'|\'update\') with the values ad_storage, ad_user_data, ad_personalization, analytics_storage; CMP presence: Cookiebot, CookieYes, Complianz, Klaro, OneTrust, Usercentrics, iubenda or the lp2 banner; the lp2CanTrack definition - default or overridden): the result is "advanced mode (cookieless pings when consent is denied)", "basic mode (nothing is sent when consent is denied)" or "no consent mode" with a justification (a heuristic from the HTML and scripts of this domain; code from GTM and third-party domains is invisible); (6) the thank-you page (thankYouUrl or LP2_THANKYOU_PATH) that the form redirects to after submission: a conversion call on that page counts as fired after the form is submitted; the conversion field summarises where it was found. Up to 10 <script src> files from allowed domains are also analysed (2 MB each; text analysis only, never executed). Only https and hosts from the allow-list; every redirect is checked before fetching; max 5 redirects, 10 s timeout, 2 MB of HTML.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'urls' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1], 'maxItems' => 6, 'description' => 'URLs to check (https, host from the allow-list). Default: the first allowed host + LANDING_PATH_PREFIX, with and without a trailing slash'],
                'forbiddenPhrases' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1], 'maxItems' => 50, 'description' => 'Forbidden phrases; override LP2_FORBIDDEN_PHRASES from the configuration. Letter case and Polish diacritics do not matter'],
                'testParams' => ['type' => 'boolean', 'default' => true, 'description' => 'Whether to check that the gclid, gbraid, wbraid parameters survive redirects (an additional GET request with test values)'],
                'thankYouUrl' => ['type' => 'string', 'minLength' => 1, 'description' => 'Thank-you page after the form is submitted (URL or path). Defaults to LP2_THANKYOU_PATH from the configuration'],
                'fetchScripts' => ['type' => 'boolean', 'default' => true, 'description' => 'Whether to fetch and text-analyse <script src> files from allowed domains (max 10, 2 MB each)'],
            ],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => fn(array $args): array => Verify::run($args),
    ],
];
