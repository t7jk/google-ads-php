<?php
declare(strict_types=1);

namespace App\Ga4;

use App\Ads\Table;
use App\Report\Js;

/** Port src/mcp/ga4-report.ts: helper functions of the GA4 report (pure, no network calls). */
final class ReportShape
{
    public const DIMENSIONS = ['sessionSourceMedium', 'sessionSource', 'sessionMedium', 'sessionCampaignName', 'landingPage', 'eventName', 'date', 'deviceCategory'];
    public const METRICS = ['sessions', 'keyEvents', 'eventCount', 'engagementRate', 'totalUsers'];
    private const DEFAULT_DIMENSIONS = ['landingPage', 'sessionSource', 'sessionMedium', 'sessionCampaignName'];
    private const DEFAULT_METRICS = ['sessions', 'keyEvents'];
    public const LEADS_EVENT = 'generate_lead';
    public const GOOGLE_CPC = 'google / cpc';

    /**
     * Chooses dimensions, metrics and the event filter. The leads_by_source view has its own set and no path filter.
     * @return array{dimensions:list<string>,metrics:list<string>,eventName:?string,pathFilter:bool}
     */
    public static function resolve(?string $view, ?array $dimensions, ?array $metrics, ?string $eventName): array
    {
        if ($view === 'leads_by_source') {
            return ['dimensions' => ['date', 'sessionSourceMedium'], 'metrics' => ['eventCount'], 'eventName' => self::LEADS_EVENT, 'pathFilter' => false];
        }
        // eventName + sessions gives misleading results, so with an event filter the default metric is eventCount.
        return [
            'dimensions' => $dimensions ?: self::DEFAULT_DIMENSIONS,
            'metrics' => $metrics ?: ($eventName ? ['eventCount'] : self::DEFAULT_METRICS),
            'eventName' => $eventName,
            'pathFilter' => true,
        ];
    }

    /** Combines the path filter and the event filter via andGroup. */
    public static function combineFilters(?array $pathFilter, ?string $eventName): ?array
    {
        $parts = array_values(array_filter([
            $pathFilter,
            $eventName ? ['filter' => ['fieldName' => 'eventName', 'stringFilter' => ['matchType' => 'EXACT', 'value' => $eventName]]] : null,
        ]));
        return match (count($parts)) {
            0 => null,
            1 => $parts[0],
            default => ['andGroup' => ['expressions' => $parts]],
        };
    }

    /** Sorts descending by the first metric and cuts to the limit. */
    public static function sortAndLimit(array $rows, string $firstMetric, int $limit): array
    {
        usort($rows, fn($a, $b) => Js::num($b[$firstMetric] ?? null) <=> Js::num($a[$firstMetric] ?? null));
        return array_slice($rows, 0, $limit);
    }

    public static function sumMetric(array $rows, string $metric): int|float
    {
        return Js::normalize((float) array_sum(array_map(fn($r) => Js::num($r[$metric] ?? null), $rows)));
    }

    /** TSV table: columns ordered dimensions, then metrics. */
    public static function table(array $rows, array $dimensions, array $metrics): string
    {
        $cols = [...$dimensions, ...$metrics];
        return Table::toTsv(array_map(fn($r) => array_combine($cols, array_map(fn($c) => $r[$c] ?? '', $cols)), $rows));
    }

    public static function googleCpcTotal(array $rows): int|float
    {
        return self::sumMetric(array_values(array_filter($rows, fn($r) => ($r['sessionSourceMedium'] ?? null) === self::GOOGLE_CPC)), 'eventCount');
    }
}
