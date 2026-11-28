<?php
declare(strict_types=1);

namespace App\Google;

/** GA4 Data API (port of src/ga4/client.ts): runReport only, for the property from config 'ga4_property_id'. */
final class Ga4
{
    /** @return array{rows: list<array<string,string>>} each row: dimension/metric name => value */
    public static function runReport(array $body): array
    {
        $property = (string) config('ga4_property_id', '');
        $started = microtime(true);
        [$status, $text, $headers] = GoogleApi::send('POST', "https://analyticsdata.googleapis.com/v1beta/properties/$property:runReport", $body);
        $j = json_decode($text, true) ?: [];
        if ($status >= 400) {
            $msg = $j['error']['message'] ?? 'unknown error';
            GoogleApi::logFailure('ga4', 'runReport', $status, $headers, $started, $msg);
            throw new \RuntimeException("GA4 error ($status): $msg");
        }
        $dims = array_column($j['dimensionHeaders'] ?? [], 'name');
        $mets = array_column($j['metricHeaders'] ?? [], 'name');
        $rows = [];
        foreach ($j['rows'] ?? [] as $r) {
            $row = [];
            foreach ($r['dimensionValues'] ?? [] as $i => $v) {
                $row[$dims[$i]] = $v['value'];
            }
            foreach ($r['metricValues'] ?? [] as $i => $v) {
                $row[$mets[$i]] = $v['value'];
            }
            $rows[] = $row;
        }
        return ['rows' => $rows];
    }
}
