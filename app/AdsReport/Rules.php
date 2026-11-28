<?php
declare(strict_types=1);

namespace App\AdsReport;

/** Verdict rules for the impression_share view (port of src/ads/ads-report-rules.ts); pure functions, no queries. */
final class Rules
{
    private const MIN_IMPRESSIONS = 100;

    /** $c: primaryStatus, primaryStatusReasons[], biddingStatus, budgetLost, rankLost, impressions. */
    public static function impressionShareVerdict(array $c): string
    {
        $reasons = $c['primaryStatusReasons'] ?? [];
        $status = $c['primaryStatus'] ?? null;
        if ($status === 'PAUSED' || in_array('CAMPAIGN_PAUSED', $reasons, true)) {
            return 'paused';
        }
        if ($status === 'ENDED' || $status === 'REMOVED' || in_array('CAMPAIGN_ENDED', $reasons, true)) {
            return 'ended';
        }
        $out = [];
        if (str_starts_with((string) ($c['biddingStatus'] ?? ''), 'LEARNING_')) {
            $out[] = 'in learning phase';
        }
        if (($c['budgetLost'] ?? 0) > 0.2 || in_array('BUDGET_CONSTRAINED', $reasons, true)) {
            $out[] = 'limited by budget';
        }
        if (($c['rankLost'] ?? 0) > 0.2) {
            $out[] = 'limited by rank';
        }
        if (!$out) {
            $out[] = ($c['impressions'] ?? 0) < self::MIN_IMPRESSIONS ? 'little data' : 'no clear limitations';
        }
        return implode('; ', $out);
    }

    /** Google returns 0.0999 for "below 10%". Returns a number in % (1 decimal), "<10%" or null (missing). */
    public static function sharePct(mixed $v): int|float|string|null
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $n = (float) $v;
        if (abs($n - 0.0999) < 0.00005) {
            return '<10%';
        }
        return Fmt::norm(floor($n * 1000 + 0.5) / 10);
    }
}
