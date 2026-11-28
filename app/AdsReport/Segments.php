<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Ads\PlNames;
use App\Google\Ads;

/** B6: metrics by dimension (device, day of week, hour, age, gender), per campaign. */
final class Segments
{
    private const DEVICES = ['MOBILE', 'DESKTOP', 'TABLET', 'CONNECTED_TV', 'OTHER', 'UNKNOWN'];
    private const COLNAME = ['device' => 'device', 'day_of_week' => 'dayOfWeek', 'hour' => 'hour', 'age' => 'age', 'gender' => 'gender'];
    private const PMAX_NOTE = 'PMax campaigns return no age or gender segments (Google does not expose them in age_range_view or gender_view for PMax); for a PMax breakdown use pmax_report (e.g. the hours view) or the device, day_of_week, hour segments.';

    /** Natural order of segment values (not by cost). */
    private static function order(string $kind, string $v): int
    {
        $by = function (array $list) use ($v): int {
            $i = array_search($v, $list, true);
            return $i === false ? 999 : $i;
        };
        return match ($kind) {
            'device' => $by(self::DEVICES),
            'day_of_week' => $by(PlNames::DAYS),
            'age' => $by(PlNames::AGES),
            'gender' => $by(PlNames::GENDERS),
            default => (int) $v,
        };
    }

    private static function rawValue(string $kind, array $r): string
    {
        $path = match ($kind) {
            'device' => 'segments.device', 'day_of_week' => 'segments.dayOfWeek', 'hour' => 'segments.hour',
            'age' => 'adGroupCriterion.ageRange.type', default => 'adGroupCriterion.gender.type',
        };
        return (string) Fmt::get($r, $path, '');
    }

    private static function label(string $kind, string $v): string
    {
        return match ($kind) {
            'device' => PlNames::deviceName($v), 'day_of_week' => PlNames::dayName($v),
            'hour' => str_pad($v, 2, '0', STR_PAD_LEFT) . ':00', 'age' => PlNames::ageName($v), default => PlNames::genderName($v),
        };
    }

    public static function view(array $a): array
    {
        $kind = $a['segment'];
        $raw = Ads::search(Queries::segments($kind, $a['campaignId'], $a['b']));
        $agg = [];
        foreach ($raw as $r) {
            $v = self::rawValue($kind, $r);
            $key = Fmt::get($r, 'campaign.id', '') . "|$v";
            $agg[$key] ??= ['campaignId' => (string) Fmt::get($r, 'campaign.id', ''), 'campaign' => Fmt::get($r, 'campaign.name', ''), 'v' => $v, 'clicks' => 0, 'impressions' => 0, 'cost' => 0, 'conv' => 0];
            $m = $r['metrics'] ?? [];
            $agg[$key]['clicks'] += Fmt::num($m['clicks'] ?? null);
            $agg[$key]['impressions'] += Fmt::num($m['impressions'] ?? null);
            $agg[$key]['cost'] += Fmt::num($m['costMicros'] ?? null) / 1e6;
            $agg[$key]['conv'] += Fmt::num($m['conversions'] ?? null);
        }
        $items = array_values(array_filter($agg, fn($x) => $x['clicks'] || $x['impressions'] || $x['cost'] || $x['conv']));
        usort($items, fn($x, $y) => Fmt::cmp($x['campaign'], $y['campaign']) ?: Fmt::cmp($x['campaignId'], $y['campaignId']) ?: (self::order($kind, $x['v']) <=> self::order($kind, $y['v'])));
        $rows = array_map(fn($x) => [
            'campaignId' => $x['campaignId'], 'campaign' => $x['campaign'], self::COLNAME[$kind] => self::label($kind, $x['v']), 'clicks' => $x['clicks'], 'impressions' => $x['impressions'],
            'ctrPct' => $x['impressions'] ? Fmt::r2($x['clicks'] / $x['impressions'] * 100) : null, 'cost' => Fmt::r2($x['cost']), 'conversions' => Fmt::r2($x['conv']),
            'costPerConversion' => $x['conv'] > 0 ? Fmt::r2($x['cost'] / $x['conv']) : null,
        ], $items);
        $range = Fmt::rangeLabel($a['b']);
        $col = self::COLNAME[$kind];
        $isPmaxSensitive = $kind === 'age' || $kind === 'gender';
        $out = [
            'header' => "Segmentation by: $col, " . ($a['campaignId'] ? "campaign {$a['campaignId']}" : 'all campaigns') . ", $range.",
            'rows' => $rows, 'extra' => ['segment' => $kind, 'range' => $range],
            'empty' => "No data for segment $col ($range)." . ($isPmaxSensitive ? ' ' . self::PMAX_NOTE : ''),
        ];
        if ($isPmaxSensitive) {
            $out['footer'] = self::PMAX_NOTE;
        }
        return $out;
    }
}
