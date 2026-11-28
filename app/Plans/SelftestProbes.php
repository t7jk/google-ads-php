<?php
declare(strict_types=1);

namespace App\Plans;

use App\Ads\Plural;
use App\Google\{Ads, Ga4, Ga4Admin, Token};
use App\Lp2\{LeadsClient, Lp2NotAvailable};

/**
 * Selftest probes (port of selftest-probes.ts): reads only (Ads search, GA4 runReport and Admin list*, lp2 GET).
 * Each probe has its own try/catch: one failure does not spoil the others. Never calls a write.
 * No per-probe timeout (PHP has a whole-request limit and curl timeouts in Http).
 * Result: probe key => ['text', 'note', 'level'?: warn|danger].
 */
final class SelftestProbes
{
    /** @param list<string> $toolNames @return array<string, array> */
    public static function run(array $toolNames): array
    {
        $out = [];
        $out['ads'] = self::probe('Applies to: list_campaigns, account_overview, search_terms, list_conversion_actions, campaign_health_check and the other Google Ads reads', function () {
            $cs = Ads::search(PlanQueries::CONVERSION_TRACKING)[0]['customer']['conversionTrackingSetting'] ?? [];
            return ['text' => 'read works, conversion tracking: ' . ($cs['conversionTrackingStatus'] ?? 'no data')];
        });
        $out['ga4data'] = self::probe('Applies to: ga4_report (also view: leads_by_source), ga4_key_events (the part with event counts)', function () {
            if (!Cfg::ga4PropertyId()) {
                return self::notConfigured('GA4_PROPERTY_ID missing');
            }
            Ga4::runReport(['dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']], 'metrics' => [['name' => 'sessions']], 'limit' => 1]);
            return ['text' => 'service access works'];
        });
        $out['ga4admin'] = self::probe('Dotyczy: ga4_key_events, ga4_data_streams, ga4_ads_links', function () {
            if (!Cfg::ga4PropertyId()) {
                return self::notConfigured('GA4 Admin API');
            }
            $s = Ga4Admin::listDataStreams();
            $ids = array_values(array_filter(array_column($s, 'measurementId')));
            return ['text' => Plural::withCount(count($s), 'stream', 'streams', 'streams') . ($ids ? ', measurement ID ' . implode(', ', $ids) : '')];
        });
        $out['editScope'] = self::probe('Dotyczy: prepare_ga4_key_events', function () {
            return Token::hasGa4EditScope() ? ['text' => 'nadany']
                : ['level' => 'warn', 'text' => 'missing', 'note' => 'Applies to: prepare_ga4_key_events. ' . Ga4Admin::EDIT_MISSING];
        });
        $out['uploadClicks'] = self::probe('Applies to: prepare_offline_conversions', function () {
            $n = count(array_filter(Ads::search(PlanQueries::CONVERSION_ACTIONS), fn($r) => ($r['conversionAction']['type'] ?? null) === 'UPLOAD_CLICKS' && ($r['conversionAction']['status'] ?? null) !== 'REMOVED'));
            return $n > 0 ? ['text' => Plural::withCount($n, 'action', 'actions', 'actions') . ' UPLOAD_CLICKS']
                : ['level' => 'warn', 'text' => 'no UPLOAD_CLICKS action', 'note' => 'Applies to: prepare_offline_conversions. Create it with prepare_conversion_action (mode: "create", type: "UPLOAD_CLICKS")'];
        });
        $out['lp2'] = self::probe('Dotyczy: lp2_leads, prepare_offline_conversions', function () {
            if (!config('lp2_admin_api_url') || !config('lp2_admin_api_token')) {
                return ['level' => 'warn', 'text' => LeadsClient::NOT_CONFIGURED];
            }
            try {
                $now = Clock::now();
                $agg = LeadsClient::aggregates($now->modify('-1 day')->format('Y-m-d'), $now->format('Y-m-d'));
                return ['text' => 'works, ' . Plural::withCount(count($agg), 'row', 'rows', 'rows')];
            } catch (Lp2NotAvailable $e) {
                if (in_array($e->getMessage(), [LeadsClient::NOT_CONFIGURED, LeadsClient::NOT_DEPLOYED], true)) {
                    return ['level' => 'warn', 'text' => $e->getMessage()];
                }
                throw $e;
            }
        });
        $out['tools'] = ['text' => Plural::withCount(count($toolNames), 'tool', 'tools', 'tools') . ' available to the model', 'note' => 'Applies to: all. ' . implode(', ', $toolNames)];
        return $out;
    }

    private static function notConfigured(string $what): array
    {
        return ['level' => 'warn', 'text' => "not configured ($what)"];
    }

    private static function probe(string $notePrefix, callable $f): array
    {
        try {
            $r = $f();
            return $r + ['note' => $notePrefix];
        } catch (\Throwable $e) {
            return ['level' => 'danger', 'text' => 'error: ' . $e->getMessage(), 'note' => $notePrefix];
        }
    }
}
