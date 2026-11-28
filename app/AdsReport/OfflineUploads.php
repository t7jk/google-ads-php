<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Ads\Plural;
use App\Google\Ads;

/** A1b: summaries of offline conversion uploads (campaign and range are ignored). */
final class OfflineUploads
{
    public static function view(array $a): array
    {
        $byAction = Ads::search(Queries::offlineActionSummary());
        $byClient = Ads::search(Queries::offlineClientSummary());
        $actions = Ads::search(Queries::conversionActionsState());
        $uploadActions = count(array_filter($actions, fn($r) => Fmt::get($r, 'conversionAction.type') === 'UPLOAD_CLICKS'));
        if (!$byAction && !$byClient) {
            return ['header' => '', 'rows' => [], 'extra' => ['uploadClicksActions' => $uploadActions],
                'empty' => 'No offline conversions have been uploaded yet.' . ($uploadActions ? '' : ' The account also has no UPLOAD_CLICKS conversion action (nothing can be uploaded without one).')];
        }
        $rows = [];
        foreach ($byClient as $r) {
            $s = $r['offlineConversionUploadClientSummary'] ?? [];
            $rows[] = self::row('client', $s['client'] ?? '', null, $s,
                isset($s['successRate']) ? Fmt::r2(Fmt::num($s['successRate']) * 100) : self::rate($s['successfulEventCount'] ?? null, $s['totalEventCount'] ?? null));
        }
        foreach ($byAction as $r) {
            $s = $r['offlineConversionUploadConversionActionSummary'] ?? [];
            $rows[] = self::row('action', $s['conversionActionName'] ?? '', $s['conversionActionId'] ?? null, $s, self::rate($s['successfulEventCount'] ?? null, $s['totalEventCount'] ?? null));
        }
        $details = [];
        foreach ([...array_map(fn($r) => $r['offlineConversionUploadClientSummary'] ?? null, $byClient), ...array_map(fn($r) => $r['offlineConversionUploadConversionActionSummary'] ?? null, $byAction)] as $s) {
            if ($s) {
                $details[] = ['name' => $s['conversionActionName'] ?? $s['client'] ?? '', 'alerts' => $s['alerts'] ?? [], 'dailySummaries' => $s['dailySummaries'] ?? [], 'jobSummaries' => $s['jobSummaries'] ?? []];
            }
        }
        return [
            'header' => 'Offline conversion uploads: ' . Plural::withCount(count($rows), 'summary', 'summaries', 'summaries') . '.',
            'rows' => $rows, 'extra' => ['details' => $details, 'uploadClicksActions' => $uploadActions],
            'footer' => 'Full alerts, daily summaries and job summaries are in structuredContent.details.',
        ];
    }

    private static function row(string $level, mixed $name, mixed $actionId, array $s, mixed $successRate): array
    {
        return ['level' => $level, 'name' => $name, 'actionId' => $actionId, 'status' => $s['status'] ?? null,
            'total' => Fmt::num($s['totalEventCount'] ?? null), 'successful' => Fmt::num($s['successfulEventCount'] ?? null), 'pending' => Fmt::num($s['pendingEventCount'] ?? null),
            'successRatePct' => $successRate, 'lastUpload' => $s['lastUploadDateTime'] ?? null,
            'alerts' => count(Fmt::list($s['alerts'] ?? null)), 'dailySummaries' => count(Fmt::list($s['dailySummaries'] ?? null)), 'jobSummaries' => count(Fmt::list($s['jobSummaries'] ?? null))];
    }

    private static function rate(mixed $success, mixed $total): int|float|null
    {
        return Fmt::num($total) > 0 ? Fmt::r2(Fmt::num($success) / Fmt::num($total) * 100) : null;
    }
}
