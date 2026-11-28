<?php
declare(strict_types=1);

namespace App\Report;

use App\Ads\Params;
use App\Ads\Time;
use App\Ads\UserError;
use App\Lib\Db;
use App\Lib\Mcp;

/**
 * The server_events tool (port of src/mcp/tools-events.ts). Source: the events table (Lib\Events::log): columns ts, type, data_json;
 * entry = {ts, type, ...data}. Filters tool/planId/ok work on fields in data. Secrets are already masked on write (Events::clean).
 */
final class ServerEvents
{
    public const TYPES = ['mcp_tool', 'plan', 'ads_api', 'auth', 'web', 'ui', 'server'];

    public static function run(array $args): array
    {
        $sinceArg = (string) ($args['since'] ?? '2h');
        $since = self::parseSince($sinceArg, CampaignTools::now());
        if ($since === null) {
            return Mcp::error('Invalid "since": provide e.g. 30m, 2h, 1d or an ISO date.');
        }
        $types = $args['types'] ?? null;
        if ($types !== null && (!is_array($types) || array_diff($types, self::TYPES))) {
            throw new UserError('Parameter types may contain only: ' . implode(', ', self::TYPES));
        }
        $format = (string) ($args['format'] ?? 'table');
        if (!in_array($format, ['table', 'json'], true)) {
            throw new UserError('Parameter format must be table or json');
        }
        $limit = min(max((int) Js::num($args['limit'] ?? null) ?: 50, 1), 500);
        $tz = CampaignTools::tz();
        $tool = isset($args['tool']) && is_string($args['tool']) && $args['tool'] !== '' ? $args['tool'] : null;
        $planId = isset($args['planId']) && is_string($args['planId']) && $args['planId'] !== '' ? $args['planId'] : null;
        $onlyErrors = Params::bool($args, 'onlyErrors', false);

        $entries = array_map(fn($e) => $e + ['localTime' => Time::dateToZoned(new \DateTimeImmutable('@' . (int) strtotime($e['ts'])), $tz)],
            self::read($since, $limit, $types ?: [], $tool, $planId, $onlyErrors));
        $head = 'Events (' . count($entries) . ', since ' . Time::dateToZoned($since, $tz) . ", time zone $tz, newest first):";
        if ($format === 'json') {
            $text = "$head\n" . Js::jsonLine($entries);
        } else {
            $lines = array_map(fn($e) => implode(' | ', [$e['localTime'], $e['type'], self::keyOf($e), self::resultOf($e), self::detailOf($e)]), $entries);
            $text = "$head\ntime | type | key | result | details\n" . ($lines ? implode("\n", $lines) : '(no events)');
        }
        return Mcp::json($text, ['entries' => $entries, 'count' => count($entries), 'order' => 'newest first', 'timeZone' => $tz]);
    }

    /** "30m", "2h", "1d" (relative to $now) or an ISO date; unrecognised = null. */
    public static function parseSince(string $s, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d+)\s*([mhd])$/i', trim($s), $m)) {
            $unit = ['m' => 60, 'h' => 3600, 'd' => 86400][strtolower($m[2])];
            return $now->modify('-' . ((int) $m[1] * $unit) . ' seconds');
        }
        try {
            return trim($s) === '' ? null : new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Newest entries first. @return list<array<string,mixed>> */
    private static function read(\DateTimeImmutable $since, int $limit, array $types, ?string $tool, ?string $planId, bool $onlyErrors): array
    {
        $sql = 'SELECT ts, type, data_json FROM events WHERE ts >= ?';
        $params = [$since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
        if ($types) {
            $sql .= ' AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            array_push($params, ...$types);
        }
        $out = [];
        foreach (Db::all("$sql ORDER BY ts DESC, id DESC", $params) as $row) {
            $data = json_decode((string) $row['data_json'], true);
            $e = ['ts' => $row['ts'], 'type' => $row['type']] + (is_array($data) ? $data : []);
            if (!self::visibleToClient($e)) {
                continue;
            }
            if ($tool !== null && ($e['tool'] ?? null) !== $tool) {
                continue;
            }
            if ($planId !== null && ($e['planId'] ?? null) !== $planId) {
                continue;
            }
            if ($onlyErrors && !(($e['ok'] ?? null) === false || ($e['error'] ?? null) !== null)) {
                continue;
            }
            $out[] = $e;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Per-client visibility: an MCP client does not see events that belong to another client, either directly (the `client`
     * field of tool calls, tokens, uploads) or through a plan it does not own. Events with no owner (web pages, errors, start/stop)
     * stay visible: they are diagnostics of the server, not of a client. Outside /mcp everything is visible.
     */
    private static function visibleToClient(array $event): bool
    {
        if (Mcp::$clientId === null) {
            return true;
        }
        if (!Mcp::canSee(is_string($event['client'] ?? null) ? $event['client'] : null)) {
            return false;
        }
        return !is_string($event['planId'] ?? null) || Mcp::canSee(self::planOwner($event['planId']));
    }

    private static function planOwner(string $planId): ?string
    {
        static $owners = [];
        if (!array_key_exists($planId, $owners)) {
            $row = Db::one('SELECT client_id FROM plans WHERE id = ?', [$planId]);
            $owners[$planId] = $row['client_id'] ?? null; // an unknown (e.g. pruned) plan has no owner: visible
        }
        return $owners[$planId];
    }

    private static function cut(string $s, int $n): string
    {
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
    }

    private static function oneLine(mixed $v): string
    {
        return trim((string) preg_replace('/\s+/', ' ', is_array($v) ? '[object Object]' : Js::str($v ?? '')));
    }

    private static function keyOf(array $e): string
    {
        foreach (['tool', 'endpoint', 'path', 'planId', 'kind', 'event'] as $k) {
            if (($e[$k] ?? null) !== null) {
                return self::cut(Js::str($e[$k]), 80);
            }
        }
        return '';
    }

    private static function resultOf(array $e): string
    {
        if (($e['ok'] ?? null) === false || ($e['error'] ?? null) !== null) {
            return 'ERROR';
        }
        return ($e['ok'] ?? null) === true ? 'ok' : '';
    }

    private static function detailOf(array $e): string
    {
        $bits = [];
        $key = self::keyOf($e);
        if (!empty($e['event']) && $e['event'] !== $key) {
            $bits[] = Js::str($e['event']);
        }
        if (!empty($e['planId']) && $e['planId'] !== $key) {
            $bits[] = 'plan ' . Js::str($e['planId']);
        }
        if (($e['status'] ?? null) !== null) {
            $bits[] = 'HTTP ' . Js::str($e['status']);
        }
        if (is_array($e['codes'] ?? null) && $e['codes']) {
            $bits[] = implode(',', $e['codes']);
        }
        if (($e['ms'] ?? null) !== null) {
            $bits[] = Js::str($e['ms']) . ' ms';
        }
        foreach (['error', 'resultMessage', 'summary', 'message', 'reason', 'text'] as $k) {
            if (!empty($e[$k])) {
                $bits[] = self::oneLine($e[$k]);
                break;
            }
        }
        return self::cut(implode('; ', $bits), 160);
    }
}
