<?php
declare(strict_types=1);

namespace App\Lp2;

use App\Ads\{Table, Time, UserError};
use App\Google\Ads;
use App\Lib\Mcp;

/** Logic of the lp2_leads tool (lead aggregates from lp2-admin + comparison with Google Ads conversions). Port of src/mcp/tools-lp2.ts. */
final class LeadsReport
{
    private const DAY_RE = '/^\d{4}-\d{2}-\d{2}\z/';

    private static function error(string $text): array
    {
        return Mcp::error($text) + ['structuredContent' => new \stdClass()];
    }

    /** Number as in JS: an integer without ".0". */
    private static function num(float $v): int|float
    {
        $r = round($v * 100) / 100;
        return floor($r) === $r ? (int) $r : $r;
    }

    public static function run(array $args): array
    {
        foreach (['from', 'to'] as $k) {
            if (isset($args[$k]) && !preg_match(self::DAY_RE, (string) $args[$k])) {
                throw new UserError("Parameter $k must be in the format YYYY-MM-DD");
            }
        }
        if ((string) config('lp2_admin_api_url', '') === '' || (string) config('lp2_admin_api_token', '') === '') {
            return self::error('The lp2 endpoint is not configured');
        }
        try {
            $tz = (string) config('account_time_zone', 'Europe/Warsaw');
            $to = $args['to'] ?? Time::dateInZone(new \DateTimeImmutable('now'), $tz);
            $from = $args['from'] ?? (new \DateTimeImmutable($to . 'T00:00:00Z'))->modify('-29 days')->format('Y-m-d');
            $agg = LeadsClient::aggregates($from, $to);

            $rows = [];
            $byDay = [];
            foreach ($agg as $a) {
                $rows[] = ['day' => $a['day'], 'source' => $a['source'], 'with gclid/gbraid/wbraid' => $a['withClickId'] ? $a['count'] : 0,
                    'without' => $a['withClickId'] ? 0 : $a['count'], 'total' => $a['count']];
                if ($a['source'] === 'google') {
                    $d = &$byDay[$a['day']];
                    $d ??= ['gclid' => 0, 'none' => 0];
                    $d[$a['withClickId'] ? 'gclid' : 'none'] += $a['count'];
                    unset($d);
                }
            }
            [$adsNote, $adsByDay, $allByDay] = self::adsConversions($from, $to);
            $cmp = [];
            if ($adsByDay !== null) {
                $days = array_unique([...array_keys($byDay), ...array_keys($adsByDay)]);
                sort($days);
                foreach ($days as $d) {
                    $cmp[] = ['day' => $d, 'google leads' => ($byDay[$d]['gclid'] ?? 0) + ($byDay[$d]['none'] ?? 0),
                        'with click identifier' => $byDay[$d]['gclid'] ?? 0, 'Google Ads conversions' => self::num($adsByDay[$d] ?? 0),
                        'all Google Ads conversions' => self::num($allByDay[$d] ?? 0)];
                }
            }
            $total = array_sum(array_column($agg, 'count'));
            $lines = ["Leads from lp2 $from – $to: $total.", '', $rows ? Table::toTsv($rows) : 'No leads in this range.'];
            if ($cmp) {
                array_push($lines, '', "Comparison with Google Ads conversions$adsNote (days may differ: conversions are counted on the click/conversion day depending on account settings):", Table::toTsv($cmp));
            } elseif ($adsNote !== '') {
                array_push($lines, '', "Comparison with Google Ads skipped$adsNote");
            }
            return Mcp::text(implode("\n", $lines), ['from' => $from, 'to' => $to, 'aggregates' => $agg, 'comparison' => $cmp]);
        } catch (Lp2NotAvailable $e) {
            return self::error($e->getMessage());
        } catch (UserError $e) {
            throw $e;
        } catch (\Throwable $e) {
            return self::error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Google Ads conversions for the days of the range (the conversion_action_id action, if set).
     * @return array{0:string,1:?array<string,float>,2:array<string,float>} [note, conversions by day (null = error), all conversions by day]
     */
    private static function adsConversions(string $from, string $to): array
    {
        $actionId = (string) config('conversion_action_id', '');
        try {
            $r = Ads::search("SELECT segments.date, segments.conversion_action, metrics.conversions, metrics.all_conversions FROM customer WHERE segments.date BETWEEN '" . \App\Report\Range::assertDate($from) . "' AND '" . \App\Report\Range::assertDate($to) . "'");
            $adsByDay = [];
            $allByDay = [];
            foreach ($r as $x) {
                if ($actionId !== '' && !str_ends_with((string) ($x['segments']['conversionAction'] ?? ''), "/$actionId")) {
                    continue;
                }
                $day = $x['segments']['date'];
                $adsByDay[$day] = ($adsByDay[$day] ?? 0) + (float) ($x['metrics']['conversions'] ?? 0);
                $allByDay[$day] = ($allByDay[$day] ?? 0) + (float) ($x['metrics']['allConversions'] ?? 0);
            }
            $note = '';
            if (!$adsByDay) {
                $note = ' (Google Ads returned no conversions' . ($actionId !== '' ? " for action $actionId" : '') . ' in this range: all-zero rows are not returned)';
            }
            if ($actionId === '' && $adsByDay) {
                $note = ' (sum of all conversion actions — set CONVERSION_ACTION_ID to compare a single one)';
            }
            return [$note, $adsByDay, $allByDay];
        } catch (\Throwable $e) {
            return [" (could not fetch Google Ads conversions: {$e->getMessage()})", null, []];
        }
    }
}
