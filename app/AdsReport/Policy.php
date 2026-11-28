<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Ads\PlNames;
use App\Google\Ads;

/** B7: policy status of ads and PMax asset group assets (current state). */
final class Policy
{
    private static function short(mixed $s, int $n = 60): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) ($s ?? '')));
        return mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1) . '…' : $t;
    }

    /** Disapproved or limited: approval_status != APPROVED or non-empty policy_topic_entries. */
    public static function isFlagged(mixed $ps): bool
    {
        if (!$ps) {
            return false;
        }
        return (isset($ps['approvalStatus']) && $ps['approvalStatus'] !== 'APPROVED') || Fmt::list($ps['policyTopicEntries'] ?? null) !== [];
    }

    private static function describe(mixed $ps): array
    {
        $entries = Fmt::list($ps['policyTopicEntries'] ?? null);
        $count = fn(array $e, string $k) => count(Fmt::list($e[$k] ?? null));
        $types = array_values(array_unique(array_filter(array_map(fn($e) => $e['type'] ?? null, $entries))));
        $why = array_values(array_unique(array_map(
            fn($e) => PlNames::policyTopicWhy($e['topic'] ?? null) . (!empty($e['type']) ? ' - ' . PlNames::policyTypeWhy($e['type']) : ''), $entries)));
        return [
            'approval' => $ps['approvalStatus'] ?? null, 'review' => $ps['reviewStatus'] ?? null,
            'topics' => implode('; ', array_map(fn($e) => ($e['topic'] ?? '?') . ($count($e, 'evidences') ? ' (evidence: ' . $count($e, 'evidences') . ')' : '')
                . ($count($e, 'constraints') ? ' (constraints: ' . $count($e, 'constraints') . ')' : ''), $entries)),
            'restriction' => implode(', ', $types), 'why' => implode('; ', $why),
        ];
    }

    private static function severity(array $r): int
    {
        return $r['approval'] === 'DISAPPROVED' ? 0 : ($r['approval'] === 'APPROVED' ? 2 : 1);
    }

    public static function view(array $a): array
    {
        $ar = Ads::search(Queries::adPolicy($a['campaignId']));
        $sr = Ads::search(Queries::assetPolicy($a['campaignId']));
        $all = [];
        foreach ($ar as $r) {
            $ps = Fmt::get($r, 'adGroupAd.policySummary');
            $all[] = ['campaignId' => (string) Fmt::get($r, 'campaign.id', ''), 'campaign' => Fmt::get($r, 'campaign.name', ''), 'group' => Fmt::get($r, 'adGroup.name', ''), 'kind' => 'ad',
                'fieldType' => Fmt::get($r, 'adGroupAd.ad.type'),
                'item' => self::short(Fmt::get($r, 'adGroupAd.ad.name')) ?: 'ad ' . Fmt::get($r, 'adGroupAd.ad.id', ''),
                'status' => Fmt::get($r, 'adGroupAd.status'), 'primaryStatus' => null] + self::describe($ps) + ['_flag' => self::isFlagged($ps)];
        }
        foreach ($sr as $r) {
            $ps = Fmt::get($r, 'assetGroupAsset.policySummary');
            $item = self::short(Fmt::get($r, 'asset.textAsset.text')) ?: self::short(Fmt::get($r, 'asset.name'))
                ?: 'asset ' . Fmt::get($r, 'asset.id', Fmt::lastId(Fmt::get($r, 'assetGroupAsset.asset'))) . ' (' . Fmt::get($r, 'asset.type', '?') . ')';
            $all[] = ['campaignId' => (string) Fmt::get($r, 'campaign.id', ''), 'campaign' => Fmt::get($r, 'campaign.name', ''), 'group' => Fmt::get($r, 'assetGroup.name', ''), 'kind' => 'asset',
                'fieldType' => Fmt::get($r, 'assetGroupAsset.fieldType'), 'item' => $item,
                'status' => Fmt::get($r, 'assetGroupAsset.status'), 'primaryStatus' => Fmt::get($r, 'assetGroupAsset.primaryStatus')] + self::describe($ps) + ['_flag' => self::isFlagged($ps)];
        }
        $flagged = count(array_filter($all, fn($r) => $r['_flag']));
        $shown = $a['includeApproved'] ? $all : array_filter($all, fn($r) => $r['_flag']);
        $rows = array_map(function ($r) {
            unset($r['_flag']);
            return $r;
        }, array_values($shown));
        usort($rows, fn($x, $y) => (self::severity($x) <=> self::severity($y)) ?: Fmt::cmp((string) $x['campaign'], (string) $y['campaign']));
        return [
            'header' => 'Policy: checked ' . count($all) . ' (ads ' . count($ar) . ', asset group assets ' . count($sr) . "), disapproved or limited $flagged" . ($a['includeApproved'] ? ', approved ones shown too' : '') . '.',
            'rows' => $rows, 'extra' => ['checked' => count($all), 'ads' => count($ar), 'assets' => count($sr), 'flagged' => $flagged],
            'empty' => $all ? 'No disapproved or limited ads and assets.' : 'No disapproved or limited ads and assets (the account has no ads or asset group assets to check).',
            'footer' => 'Disapproved or limited = approval_status other than APPROVED or non-empty policy_topic_entries. Evidence details are in Google Ads; includeApproved=true also shows approved items.',
        ];
    }
}
