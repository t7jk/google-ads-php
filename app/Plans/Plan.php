<?php
declare(strict_types=1);

namespace App\Plans;

/**
 * A change plan as a plain array (serialized to plans.payload_json). Fields (port of src/plans/types.ts):
 * id, kind, campaignId?, goalScope?, channel?, assetGroupId?, conversionActionId?, summary, diff[], steps[{title, operations[]}],
 * warnings[], validation{ok, errors[]}, beforeFingerprint, campaignState?, createdAt, expiresAt, status
 * (pending|executing|done|failed|refused), result?, undo?{ops,notUndoable,notes}, executedAt?, afterFingerprint?, undoOf?, undoneBy?, undoneAt?.
 * DiffItem: section, label, before, after, change (add|remove|change|info), beforeImage?, afterImage?, imageAlt?, note?, level? (danger|warn).
 */
final class Plan
{
    /** Common fields of a new plan: id, dates, status. The builder adds the rest. */
    public static function base(?\DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        return [
            'id' => bin2hex(random_bytes(16)),
            'createdAt' => Clock::iso($now),
            'expiresAt' => Clock::iso($now->modify('+' . Cfg::planTtlHours() . ' hours')),
            'status' => 'pending',
        ];
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', $id);
    }

    /** Plan hash the approver must supply (a plan changed after the preview gets a different hash). */
    public static function hash(array $p): string
    {
        $core = ['kind' => $p['kind']];
        if (isset($p['campaignId'])) {
            $core['campaignId'] = $p['campaignId'];
        }
        if (!empty($p['goalScope'])) {
            $core['goalScope'] = $p['goalScope'];
        }
        if (!empty($p['channel'])) {
            $core['channel'] = $p['channel'];
        }
        $core['steps'] = $p['steps'];
        $core['beforeFingerprint'] = $p['beforeFingerprint'];
        return Json::sha256(Json::canonical($core));
    }

    /** URL of the plan preview/approval page (the router only has exact routes, so the ID goes in the query: /plan?id=…&t=…). */
    public static function pageUrl(string $id, ?string $token = null): string
    {
        return base_url() . '/plan?id=' . $id . ($token ? '&t=' . $token : '');
    }

    /** Same as pageUrl() but relative to the application (for the post-login return path, see LoginLinks::validNextPath). */
    public static function pagePath(string $id, string $token): string
    {
        return '/plan?id=' . $id . '&t=' . $token;
    }

    /** Start of the plan image URL; the builder appends the blob sha256: Plan::imagePrefix($id) . $sha. The token (&t=) is added by Plan::view. */
    public static function imagePrefix(string $id): string
    {
        return base_url() . '/plan/img?id=' . $id . '&sha=';
    }

    /** All operations of the plan (in step order). */
    public static function ops(array $p): array
    {
        $out = [];
        foreach ($p['steps'] as $st) {
            array_push($out, ...$st['operations']);
        }
        return $out;
    }

    /**
     * Plan view for the window/page (port of toPlanView). $token: the 24 h link goes into approveUrl and the plan image URLs
     * so the preview works without login.
     */
    public static function view(array $p, ?string $token = null): array
    {
        $base = base_url();
        $prefix = self::imagePrefix($p['id']);
        $sign = fn($u) => ($u && $token && str_starts_with((string) $u, $prefix)) ? $u . '&t=' . $token : $u;
        $diff = $p['diff'];
        if ($token) {
            $diff = array_map(function ($d) use ($sign) {
                foreach (['beforeImage', 'afterImage'] as $k) {
                    if (!empty($d[$k])) {
                        $d[$k] = $sign($d[$k]);
                    }
                }
                return $d;
            }, $diff);
        }
        $view = [
            'id' => $p['id'], 'kind' => $p['kind'], 'summary' => $p['summary'], 'diff' => $diff, 'warnings' => $p['warnings'],
            'validation' => $p['validation'], 'expiresAt' => $p['expiresAt'], 'status' => $p['status'],
            'hash' => self::hash($p), 'approveUrl' => self::pageUrl($p['id'], $token), 'imageBase' => "$base/plan/",
        ];
        if (is_array($p['result'] ?? null) && is_string($p['result']['message'] ?? null)) {
            $view['resultMessage'] = $p['result']['message'];
        }
        if (!empty($p['campaignState'])) {
            $view['campaignState'] = $p['campaignState'];
        }
        return $view;
    }
}
