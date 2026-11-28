<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Google\Ads;

/** A2: impression share, limiting factors and a verdict per campaign. */
final class ImpressionShare
{
    public static function view(array $a): array
    {
        $b = $a['b'];
        $raw = Ads::search(Queries::impressionShare($a['campaignId'], $b));
        $firstCost = self::firstCostDays($a['campaignId'], $b);
        $days = $b ? (int) round((strtotime("{$b['to']} 00:00:00 UTC") - strtotime("{$b['from']} 00:00:00 UTC")) / 86400) + 1 : null;
        $rows = [];
        foreach ($raw as $r) {
            $rows[] = self::row($r, $b, $firstCost);
        }
        usort($rows, fn($x, $y) => $y['cost'] <=> $x['cost']);
        $label = Fmt::rangeLabel($b);
        return [
            'header' => "Impression share and campaign limitations, $label; shares in %, \"<10%\" = below 10%, empty = not reported by Google.",
            'rows' => $rows, 'extra' => ['range' => $label, 'days' => $days],
            'footer' => 'Shares refer to the search network. PMax campaigns usually have only searchIS, budgetLostIS and rankLostIS (no top-position shares). avgDailyCost = cost / avgDays, i.e. the number of days in the range in which the campaign could run (from the first day with spend in the range, no earlier than the start date in the settings, to the end of the range or the end date); empty for ALL_TIME.',
        ];
    }

    /** First day with spend in the range, per campaign (only when a date range is given). */
    private static function firstCostDays(?string $campaignId, ?array $b): array
    {
        $first = [];
        if (!$b) {
            return $first;
        }
        foreach (Ads::search(Queries::dailyCost($campaignId, $b)) as $d) {
            $id = (string) Fmt::get($d, 'campaign.id', '');
            $day = (string) Fmt::get($d, 'segments.date', '');
            if (Fmt::num(Fmt::get($d, 'metrics.costMicros')) > 0 && $day !== '' && (!isset($first[$id]) || $day < $first[$id])) {
                $first[$id] = $day;
            }
        }
        return $first;
    }

    private static function row(array $r, ?array $b, array $firstCost): array
    {
        $m = $r['metrics'] ?? [];
        $cost = Fmt::zl($m['costMicros'] ?? null) ?? 0;
        $reasons = array_map('strval', Fmt::list(Fmt::get($r, 'campaign.primaryStatusReasons')));
        $id = (string) Fmt::get($r, 'campaign.id', '');
        $days = $b ? self::activeDays($b, Fmt::get($r, 'campaign.startDateTime'), Fmt::get($r, 'campaign.endDateTime'), $firstCost[$id] ?? null) : null;
        $pmax = Fmt::get($r, 'campaign.advertisingChannelType') === 'PERFORMANCE_MAX';
        $lost = fn(string $k) => isset($m[$k]) ? Fmt::num($m[$k]) : null;
        $row = [
            'campaignId' => $id, 'campaign' => Fmt::get($r, 'campaign.name', ''), 'type' => Fmt::get($r, 'campaign.advertisingChannelType'),
            'searchIS' => Rules::sharePct($m['searchImpressionShare'] ?? null), 'budgetLostIS' => Rules::sharePct($m['searchBudgetLostImpressionShare'] ?? null), 'rankLostIS' => Rules::sharePct($m['searchRankLostImpressionShare'] ?? null),
            'topIS' => Rules::sharePct($m['searchTopImpressionShare'] ?? null), 'absTopIS' => Rules::sharePct($m['searchAbsoluteTopImpressionShare'] ?? null),
            'biddingStatus' => Fmt::get($r, 'campaign.biddingStrategySystemStatus'), 'primaryStatus' => Fmt::get($r, 'campaign.primaryStatus'), 'primaryStatusReasons' => implode(',', $reasons),
            'budgetDaily' => Fmt::zl(Fmt::get($r, 'campaignBudget.amountMicros')), 'avgDailyCost' => $b ? Fmt::r2($cost / $days) : null, 'avgDays' => $days,
            'clicks' => Fmt::num($m['clicks'] ?? null), 'impressions' => Fmt::num($m['impressions'] ?? null), 'cost' => $cost,
            'verdict' => Rules::impressionShareVerdict([
                'primaryStatus' => Fmt::get($r, 'campaign.primaryStatus'), 'primaryStatusReasons' => $reasons, 'biddingStatus' => Fmt::get($r, 'campaign.biddingStrategySystemStatus'),
                'budgetLost' => $lost('searchBudgetLostImpressionShare'), 'rankLost' => $lost('searchRankLostImpressionShare'), 'impressions' => Fmt::num($m['impressions'] ?? null),
            ]),
        ];
        if ($pmax && (!isset($m['searchTopImpressionShare']) || !isset($m['searchAbsoluteTopImpressionShare']))) {
            $row['note'] = 'PMax: Google does not report top-position impression share';
        }
        return $row;
    }

    /** Number of days in the range in which the campaign could run: intersection of [from,to] with [start, end], counted from the first day with spend. */
    public static function activeDays(array $b, mixed $startDateTime, mixed $endDateTime, ?string $firstCostDay = null): int
    {
        $day = fn($v) => preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) ($v ?? ''), $m) ? $m[1] : null;
        [$from, $to] = [$b['from'], $b['to']];
        $st = $day($startDateTime);
        $en = $day($endDateTime);
        if ($st && $st > $from) {
            $from = $st;
        }
        if ($firstCostDay && $firstCostDay > $from) {
            $from = $firstCostDay; // the start date in the settings is often old while the campaign actually started later
        }
        if ($en && $en < $to && !str_starts_with($en, '2037')) {
            $to = $en; // 2037-12-30 = no end date
        }
        $n = (int) round((strtotime("$to 00:00:00 UTC") - strtotime("$from 00:00:00 UTC")) / 86400) + 1;
        return max($n, 1);
    }
}
