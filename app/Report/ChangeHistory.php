<?php
declare(strict_types=1);

namespace App\Report;

use App\Ads\Params;
use App\Ads\Time;
use App\Ads\UserError;
use App\Google\Ads;
use App\Lib\Db;
use App\Lib\Mcp;

/**
 * The change_history tool (port of the tail of tools-read.ts). Two sources:
 * - connector: plan journal entries. In PHP the journal = the events table, type='plan', where data.event is in JOURNAL_TYPES
 *   (execute, execute_started, execute_failed, refused, undo_unavailable), data.summary = description (as the Node Journal logs them);
 * - account: change_event from Google Ads (about 30 days).
 */
final class ChangeHistory
{
    private const JOURNAL_TYPES = ['execute', 'execute_started', 'execute_failed', 'refused', 'undo_unavailable'];

    public static function run(array $args): array
    {
        $limit = Params::int($args, 'limit', 1, 200, 20);
        $source = (string) ($args['source'] ?? 'all');
        if (!in_array($source, ['connector', 'account', 'all'], true)) {
            throw new UserError('Parameter source must be one of: connector, account, all');
        }
        $days = Params::int($args, 'days', 1, 30, 30);
        $campaign = isset($args['campaign']) && is_string($args['campaign']) ? $args['campaign'] : null;
        $tz = CampaignTools::tz();
        $entries = [];
        $notes = [];
        $accountError = null;
        if ($source !== 'account') {
            foreach (self::journal($limit) as $e) {
                $entries[] = ['source' => 'connector', 'localTime' => self::local($e['ts'], $tz)] + $e;
            }
        }
        if ($source !== 'connector') {
            try {
                array_push($entries, ...self::accountChanges($campaign, $days, $limit, $tz));
            } catch (\Throwable $e) {
                if ($source === 'account') {
                    throw $e;
                }
                $accountError = $e->getMessage();
                $notes[] = "Note: could not read account changes ($accountError); showing connector entries only.";
            }
        }
        usort($entries, fn($a, $b) => (self::ts($b['ts']) <=> self::ts($a['ts'])));
        $out = array_slice($entries, 0, $limit);
        $line = fn($e) => $e['source'] === 'account'
            ? "- {$e['localTime']} [account" . ($e['clientType'] ? ", {$e['clientType']}" : '') . ($e['user'] ? ", {$e['user']}" : '') . "] {$e['summary']}"
            : "- {$e['localTime']} [connector] {$e['type']}: {$e['summary']}";
        $text = 'Entries (' . count($out) . ", time zone $tz):\n" . implode("\n", array_map($line, $out)) . ($notes ? "\n" . implode("\n", $notes) : '');
        return Mcp::json($text, ['entries' => $out, 'source' => $source] + ($accountError !== null ? ['accountError' => $accountError] : []));
    }

    private static function ts(string $iso): int
    {
        $t = strtotime($iso);
        return $t === false ? 0 : $t;
    }

    private static function local(string $iso, string $tz): string
    {
        $t = strtotime($iso);
        return $t === false ? $iso : Time::dateToZoned(new \DateTimeImmutable('@' . $t), $tz);
    }

    /** Latest plan journal entries (newest first). @return list<array> */
    private static function journal(int $limit): array
    {
        $out = [];
        foreach (Db::all("SELECT ts, data_json FROM events WHERE type = 'plan' ORDER BY ts DESC, id DESC LIMIT 500") as $row) {
            $d = json_decode((string) $row['data_json'], true) ?: [];
            if (!in_array($d['event'] ?? null, self::JOURNAL_TYPES, true)) {
                continue;
            }
            $out[] = ['ts' => $row['ts'], 'type' => $d['event'], 'planId' => $d['planId'] ?? null, 'summary' => (string) ($d['summary'] ?? ''), 'ops' => $d['ops'] ?? null, 'detail' => $d['detail'] ?? null];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Account changes from change_event. @return list<array> */
    private static function accountChanges(?string $campaign, int $days, int $limit, string $tz): array
    {
        $cid = $campaign !== null ? Metrics::resolveCampaignId($campaign) : null;
        $range = Time::changeEventRange(CampaignTools::now(), $tz, $days);
        $q = Queries::changeEvents($range['from'], $range['to'], $cid, $limit, Ads::customerId());
        $entries = [];
        foreach (Ads::search($q) as $r) {
            $e = $r['changeEvent'] ?? [];
            $ts = Time::zonedToDate((string) ($e['changeDateTime'] ?? ''), $tz)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
            $fields = array_values(array_filter(array_map('trim', explode(',', (string) ($e['changedFields'] ?? '')))));
            $oldR = !empty($e['oldResource']) ? array_values($e['oldResource'])[0] : null;
            $newR = !empty($e['newResource']) ? array_values($e['newResource'])[0] : null;
            $changes = array_map(fn($f) => ['field' => $f, 'old' => Js::get($oldR, $f), 'new' => Js::get($newR, $f)], $fields);
            $summary = Js::str($e['changeResourceType'] ?? '?') . ' ' . Js::str($e['resourceChangeOperation'] ?? '?');
            if ($fields) {
                $summary .= ': ' . implode(', ', array_map(fn($c) => trim("{$c['field']} " . ($c['old'] === null ? '' : Js::jsonLine($c['old']) . ' → ') . ($c['new'] === null ? '' : Js::jsonLine($c['new']))), $changes));
            }
            $entries[] = [
                'source' => 'account', 'ts' => $ts, 'localTime' => self::local($ts, $tz), 'user' => $e['userEmail'] ?? null, 'clientType' => $e['clientType'] ?? null,
                'resourceType' => $e['changeResourceType'] ?? null, 'resource' => $e['changeResourceName'] ?? null, 'operation' => $e['resourceChangeOperation'] ?? null,
                'campaign' => !empty($e['campaign']) ? (string) basename((string) $e['campaign']) : null, 'changedFields' => $fields, 'changes' => $changes, 'summary' => $summary,
            ];
        }
        return $entries;
    }
}
