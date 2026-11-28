<?php
// The ads_report tool (port of src/mcp/tools-ads-report.ts): account diagnostic reports, read-only.
// The views live in app/AdsReport/*.php (one class per view, method view(array $args): array).
// A view returns: header, rows, [textRows], [extra], [footer], [empty]; here we compose the TSV text and structuredContent from it.
declare(strict_types=1);

use App\Ads\{Params, Table, Time, UserError};
use App\AdsReport\{AccountHygiene, Audiences, Billing, BudgetSimulation, Clicks, Conversions, Fmt, ImpressionShare, LandingPages, OfflineUploads, Policy, Queries, Recommendations, Segments};
use App\Google\Ads;
use App\Lib\Mcp;

const ADS_REPORT_MAX_CLICK_DAYS = 90;
/** Views without a date range (current state or their own range): b = null and the suffix "Current state.". */
const ADS_REPORT_NO_DATES = ['offline_uploads', 'recommendations', 'clicks', 'policy', 'budget_simulation', 'billing', 'account_hygiene'];
/** Views in which campaign is ignored. */
const ADS_REPORT_NO_CAMPAIGN = ['offline_uploads', 'billing', 'account_hygiene'];

/** Campaign ID from digits or an exact name (non-REMOVED campaigns); missing -> UserError. */
function ads_report_resolve_campaign(string $campaign): string
{
    if (preg_match('/^\d+$/', $campaign)) {
        return $campaign;
    }
    foreach (Ads::search("SELECT campaign.id, campaign.name FROM campaign WHERE campaign.status != 'REMOVED'") as $r) {
        if (Fmt::get($r, 'campaign.name') === $campaign) {
            return (string) $r['campaign']['id'];
        }
    }
    throw new UserError("Campaign \"{$campaign}\" not found");
}

/** YYYY-MM-DD date range in the account time zone; null for ALL_TIME. LAST_7/30_DAYS end yesterday (as in Google Ads). */
function ads_report_bounds(mixed $range, DateTimeInterface $now, string $tz): ?array
{
    if ($range === 'ALL_TIME') {
        return null;
    }
    $today = Time::dateInZone($now, $tz);
    return match (true) {
        $range === 'LAST_7_DAYS' => ['from' => Fmt::shiftDay($today, -7), 'to' => Fmt::shiftDay($today, -1)],
        $range === 'LAST_30_DAYS' => ['from' => Fmt::shiftDay($today, -30), 'to' => Fmt::shiftDay($today, -1)],
        $range === 'LAST_90_DAYS' => ['from' => Fmt::shiftDay($today, -90), 'to' => $today],
        is_array($range) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($range['from'] ?? '')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($range['to'] ?? ''))
            => ['from' => $range['from'], 'to' => $range['to']],
        default => throw new UserError('Parameter range: LAST_7_DAYS, LAST_30_DAYS, LAST_90_DAYS, ALL_TIME or {from,to} in YYYY-MM-DD format'),
    };
}

/** Date for clicks: yesterday by default (account time zone); rejects future dates and dates older than 90 days. */
function ads_report_click_date(?string $date, DateTimeInterface $now, string $tz): string
{
    $today = Time::dateInZone($now, $tz);
    $d = $date ?? Fmt::shiftDay($today, -1);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || gmdate('Y-m-d', (int) strtotime("$d 00:00:00 UTC")) !== $d) {
        throw new UserError('date must have the format YYYY-MM-DD.');
    }
    if ($d > $today) {
        throw new UserError("date $d is in the future (today is $today).");
    }
    $oldest = Fmt::shiftDay($today, -ADS_REPORT_MAX_CLICK_DAYS);
    if ($d < $oldest) {
        throw new UserError('click_view provides data at most ' . ADS_REPORT_MAX_CLICK_DAYS . " days back (oldest day: $oldest); the given date $d is older.");
    }
    return $d;
}

/** Text and structuredContent from a view result (as in Node: header, "Current state.", "Showing X of Y.", TSV table, footer). */
function ads_report_compose(string $view, ?string $campaignId, array $out, int $limit): array
{
    $extra = $out['extra'] ?? [];
    if (!$out['rows']) {
        $text = $out['empty'] ?? "No data: view $view" . ($campaignId ? ", campaign $campaignId" : '') . '.';
        return Mcp::json($text, array_merge(['view' => $view, 'campaignId' => $campaignId, 'rows' => []], $extra));
    }
    $total = count($out['rows']);
    $rows = array_slice($out['rows'], 0, $limit);
    $state = $view === 'clicks' ? '' : (in_array($view, ADS_REPORT_NO_DATES, true) ? ' Current state.' : '');
    $textRows = array_slice($out['textRows'] ?? $out['rows'], 0, $limit);
    $text = "{$out['header']}$state Showing " . count($rows) . " of $total.\n" . Table::toTsv($textRows) . (($out['footer'] ?? '') !== '' ? "\n{$out['footer']}" : '');
    return Mcp::json($text, array_merge(['view' => $view, 'campaignId' => $campaignId, 'total' => $total, 'rows' => $rows], $extra));
}

return [
    [
        'name' => 'ads_report',
        'title' => 'Google Ads account report',
        'description' => 'Read-only: account diagnostic reports. view: conversions (conversions, all conversions and value by action and campaign, with a daily breakdown when byDay is set; also lists non-REMOVED actions without conversions in the period with status, primaryForGoal, includeInConversionsMetric - i.e. which action counts in the Conversions column), offline_uploads (summaries of offline conversion uploads: status, success rate, alerts, last upload; with no uploads: "No offline conversions have been uploaded yet."; campaign and range ignored), impression_share (search network impression share, loss to budget and rank, top-position shares, campaign and bidding strategy status, daily budget vs average spend, a short verdict per campaign; PMax has only some of the shares), recommendations (Google recommendations with predicted impact and the optimization score; list only, nothing is applied; range ignored; includeDismissed adds dismissed ones), landing_pages (landing pages: clicks, CTR, cost, conversions, page quality; expanded=true for final URLs; PMax may return no rows), clicks (clicks of a SINGLE day from click_view: shortened gclid, campaign, ad group, device, city/region, keyword; date defaults to yesterday, at most 90 days back, range ignored; matchLeads=true returns only the count of lp2 leads matched by gclid), segments (results by segment: device, day_of_week, hour, age, gender; natural order; PMax returns no age or gender), policy (disapproved or limited ads and PMax asset group assets with policy topics; includeApproved adds approved ones; current state), audiences (campaign and ad group audiences with metrics, observation/targeting mode; PMax has no such rows, so a list of asset group signals without metrics), budget_simulation (Google simulations: budget or target points with predicted clicks, impressions, cost and conversions; points above the connector limit of 30 PLN/day are flagged; current state), billing (account budgets, billing setups and invoices for month YYYY-MM, defaults to the previous month; current state, campaign ignored), account_hygiene (account users by role with masked e-mails, invitations, experiments, labels, a short verdict; current state, campaign ignored). Without campaign: all non-REMOVED campaigns. Costs in PLN, dates in Europe/Warsaw. Compact TSV table; views without dates say "current state".',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'view' => ['type' => 'string', 'enum' => Queries::VIEWS, 'description' => 'conversions | offline_uploads | impression_share | recommendations | landing_pages | clicks | segments | policy | audiences | budget_simulation | billing | account_hygiene'],
                'campaign' => ['description' => 'Campaign ID (digits) or its exact name; without it the whole account', 'type' => 'string', 'minLength' => 1],
                'range' => [
                    'default' => 'LAST_30_DAYS',
                    'description' => 'Date range: LAST_7_DAYS, LAST_30_DAYS, LAST_90_DAYS, ALL_TIME or {from,to} YYYY-MM-DD (does not apply to offline_uploads and recommendations)',
                    'anyOf' => [
                        ['type' => 'string', 'enum' => ['LAST_7_DAYS', 'LAST_30_DAYS', 'LAST_90_DAYS', 'ALL_TIME']],
                        ['type' => 'object', 'properties' => ['from' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'], 'to' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$']], 'required' => ['from', 'to']],
                    ],
                ],
                'limit' => ['default' => 50, 'description' => 'Maximum number of rows (default 50)', 'type' => 'integer', 'minimum' => 1, 'maximum' => 500],
                'byDay' => ['default' => false, 'description' => 'conversions only: daily breakdown (default: aggregated over the whole period)', 'type' => 'boolean'],
                'includeDismissed' => ['default' => false, 'description' => 'recommendations only: include dismissed recommendations', 'type' => 'boolean'],
                'expanded' => ['default' => false, 'description' => 'landing_pages only: true = expanded_landing_page_view (final URL, no quality fields)', 'type' => 'boolean'],
                'date' => ['description' => 'clicks only: day YYYY-MM-DD (default yesterday, Europe/Warsaw; at most 90 days back)', 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                'matchLeads' => ['default' => false, 'description' => 'clicks only: count lp2 leads matched by gclid (counts only)', 'type' => 'boolean'],
                'segment' => ['default' => 'device', 'description' => 'segments only: device | day_of_week | hour | age | gender', 'type' => 'string', 'enum' => Queries::SEGMENT_KINDS],
                'includeApproved' => ['default' => false, 'description' => 'policy only: also show approved ads and assets', 'type' => 'boolean'],
                'month' => ['description' => 'billing only: invoice month YYYY-MM (default previous month, Europe/Warsaw; from 2019)', 'type' => 'string', 'pattern' => '^\d{4}-\d{2}$'],
            ],
            'required' => ['view'],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => function (array $args): array {
            $view = (string) ($args['view'] ?? '');
            if (!in_array($view, Queries::VIEWS, true)) {
                throw new UserError('Parameter view must be one of: ' . implode(', ', Queries::VIEWS));
            }
            $segment = (string) ($args['segment'] ?? 'device');
            if (!in_array($segment, Queries::SEGMENT_KINDS, true)) {
                throw new UserError('Parameter segment must be one of: ' . implode(', ', Queries::SEGMENT_KINDS));
            }
            $limit = Params::int($args, 'limit', 1, 500, 50);
            $tz = (string) config('account_time_zone', 'Europe/Warsaw');
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $campaign = isset($args['campaign']) ? (string) $args['campaign'] : '';
            $campaignId = $campaign !== '' && !in_array($view, ADS_REPORT_NO_CAMPAIGN, true) ? ads_report_resolve_campaign($campaign) : null;
            $noDates = in_array($view, ADS_REPORT_NO_DATES, true);
            $b = $noDates ? null : ads_report_bounds($args['range'] ?? 'LAST_30_DAYS', $now, $tz);
            $byDay = Params::bool($args, 'byDay', false);
            $viewArgs = [
                'campaignId' => $campaignId, 'b' => $b, 'limit' => $limit, 'byDay' => $byDay && $view === 'conversions' && $b !== null,
                'includeDismissed' => Params::bool($args, 'includeDismissed', false), 'expanded' => Params::bool($args, 'expanded', false),
                'matchLeads' => Params::bool($args, 'matchLeads', false), 'segment' => $segment, 'includeApproved' => Params::bool($args, 'includeApproved', false),
                'month' => isset($args['month']) ? (string) $args['month'] : null, 'now' => $now, 'tz' => $tz,
                'maxBudgetPln' => (float) config('max_daily_budget_pln', 30),
                'date' => $view === 'clicks' ? ads_report_click_date(isset($args['date']) ? (string) $args['date'] : null, $now, $tz) : null,
            ];
            $handlers = [
                'conversions' => Conversions::view(...), 'offline_uploads' => OfflineUploads::view(...), 'impression_share' => ImpressionShare::view(...),
                'recommendations' => Recommendations::view(...), 'landing_pages' => LandingPages::view(...), 'clicks' => Clicks::view(...), 'segments' => Segments::view(...),
                'policy' => Policy::view(...), 'audiences' => Audiences::view(...), 'budget_simulation' => BudgetSimulation::view(...), 'billing' => Billing::view(...),
                'account_hygiene' => AccountHygiene::view(...),
            ];
            return ads_report_compose($view, $campaignId, $handlers[$view]($viewArgs), $limit);
        },
    ],
];
