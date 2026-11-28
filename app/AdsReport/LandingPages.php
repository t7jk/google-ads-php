<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Google\Ads;

/** B4: landing pages. expanded=false: landing_page_view (with quality fields), true: expanded_landing_page_view. */
final class LandingPages
{
    /** Click identifier values in the URL are replaced with "...". */
    public static function redactClickIds(mixed $u): string
    {
        return (string) preg_replace('/([?&](?:gclid|gbraid|wbraid|dclid|msclkid|fbclid)=)[^&#]*/i', '${1}…', (string) ($u ?? ''));
    }

    public static function view(array $a): array
    {
        $expanded = $a['expanded'] === true;
        $raw = Ads::search(Queries::landingPages($a['campaignId'], $a['b'], $expanded));
        $rows = [];
        foreach ($raw as $r) {
            $m = $r['metrics'] ?? [];
            $url = $expanded ? Fmt::get($r, 'expandedLandingPageView.expandedFinalUrl') : Fmt::get($r, 'landingPageView.unexpandedFinalUrl');
            $conv = Fmt::num($m['conversions'] ?? null);
            $row = [
                'campaignId' => (string) Fmt::get($r, 'campaign.id', ''), 'campaign' => Fmt::get($r, 'campaign.name', ''), 'url' => self::redactClickIds($url),
                'clicks' => Fmt::num($m['clicks'] ?? null), 'impressions' => Fmt::num($m['impressions'] ?? null), 'ctrPct' => Fmt::r2(Fmt::num($m['ctr'] ?? null) * 100),
                'cost' => Fmt::zl($m['costMicros'] ?? null) ?? 0, 'conversions' => Fmt::r2($conv), 'allConversions' => Fmt::r2(Fmt::num($m['allConversions'] ?? null)),
                'costPerConversion' => $conv > 0 ? Fmt::zl($m['costPerConversion'] ?? null) : null,
            ];
            if (!$expanded) {
                $pct = fn(string $k) => isset($m[$k]) ? Fmt::r2(Fmt::num($m[$k]) * 100) : null;
                $row += ['speedScore' => isset($m['speedScore']) ? Fmt::num($m['speedScore']) : null,
                    'mobileFriendlyClicksPct' => $pct('mobileFriendlyClicksPercentage'), 'ampClicksPct' => $pct('validAcceleratedMobilePagesClicksPercentage')];
            }
            $rows[] = $row;
        }
        usort($rows, fn($x, $y) => ($y['clicks'] <=> $x['clicks']) ?: ($y['cost'] <=> $x['cost']));
        $label = Fmt::rangeLabel($a['b']);
        $view = $expanded ? 'expanded_landing_page_view (final URL after template expansion)' : 'landing_page_view (unexpanded URL)';
        $viewName = $expanded ? 'expanded_landing_page_view' : 'landing_page_view';
        return [
            'header' => "Landing pages by $view, " . ($a['campaignId'] ? "campaign {$a['campaignId']}" : 'all campaigns') . ", $label.",
            'rows' => $rows, 'extra' => ['range' => $label, 'expanded' => $expanded],
            'empty' => "No rows in $viewName ($label). Possible causes: no clicks in the period, or a PMax campaign: Google does not always expose landing page views for it (PMax reports pages in the \"Landing pages\" report in the UI and in pmax_report). Try expanded=true.",
            'footer' => $expanded ? 'The expanded view has no page quality fields (speed_score etc.).' : 'speedScore (0-100 per Google, often empty), mobileFriendlyClicksPct and ampClicksPct are the share of clicks in %. ctrPct in %.',
        ];
    }
}
