<?php
declare(strict_types=1);

namespace App\Plans;

use App\Lib\{Db, Mcp};

/**
 * Plan store in SQLite (table plans; port of src/store/plan-store.ts, JSON files -> rows).
 * payload_json = the whole plan; the columns status/result_json/executed_at/expires_at are the source of truth for those fields and are overlaid on read.
 * No in-memory locks (PHP has no long-lived process): execution exclusivity comes from the atomic
 * UPDATE ... WHERE status='pending' in claim(). Plans expire "on request" (expires_at is checked in claim), no timers.
 */
final class PlanStore
{
    /** Plans older than this many days are deleted on write (the undo window is 7 days). */
    private const RETENTION_DAYS = 30;
    /** How many days back we look for plans to undo / list. */
    private const LIST_DAYS = 8;

    public static function save(array $p): void
    {
        if (!Plan::validId($p['id'])) {
            throw new \InvalidArgumentException('Invalid plan ID');
        }
        unset($p['clientId']); // owner is the client_id column (set on insert), not part of the plan payload or its hash
        $payload = Json::encode($p);
        $result = array_key_exists('result', $p) && $p['result'] !== null ? Json::encode($p['result']) : null;
        $changed = Db::exec('UPDATE plans SET tool = ?, status = ?, payload_json = ?, result_json = ?, expires_at = ?, executed_at = ? WHERE id = ?',
            [$p['kind'], $p['status'], $payload, $result, $p['expiresAt'], $p['executedAt'] ?? null, $p['id']]);
        if ($changed === 0) {
            // The owner is the MCP client that created the plan (null outside /mcp: CLI, tests).
            Db::exec('INSERT INTO plans (id, tool, status, payload_json, result_json, created_at, expires_at, executed_at, client_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$p['id'], $p['kind'], $p['status'], $payload, $result, $p['createdAt'], $p['expiresAt'], $p['executedAt'] ?? null, Mcp::$clientId]);
            if (random_int(1, 50) === 1) {
                self::prune();
            }
        }
    }

    public static function get(string $id): ?array
    {
        if (!Plan::validId($id)) {
            return null;
        }
        $row = Db::one('SELECT payload_json, status, result_json, executed_at, client_id FROM plans WHERE id = ?', [$id]);
        return $row ? self::hydrate($row) : null;
    }

    private static function hydrate(array $row): array
    {
        $p = Json::decode($row['payload_json']);
        $p['status'] = $row['status'];
        $p['clientId'] = $row['client_id']; // owner (null for plans created before ownership was recorded)
        if ($row['result_json'] !== null) {
            $p['result'] = Json::decode($row['result_json']);
        }
        if ($row['executed_at'] !== null) {
            $p['executedAt'] = $row['executed_at'];
        }
        return $p;
    }

    /**
     * Atomic claim of a plan for execution.
     * @return array{ok: true, plan: array}|array{ok: false, reason: 'not_found'|'expired'|'hash_mismatch'|'already_used'}
     */
    public static function claim(string $id, string $hash): array
    {
        $first = self::get($id);
        if ($first === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ($first['status'] !== 'pending') {
            return ['ok' => false, 'reason' => 'already_used'];
        }
        if (strtotime($first['expiresAt']) <= Clock::now()->getTimestamp()) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        if (!hash_equals(Plan::hash($first), $hash)) {
            return ['ok' => false, 'reason' => 'hash_mismatch'];
        }
        // Only after all checks: a failed claim does not lock the plan. Exactly one request wins.
        if (Db::exec("UPDATE plans SET status = 'executing' WHERE id = ? AND status = 'pending'", [$id]) !== 1) {
            return ['ok' => false, 'reason' => 'already_used'];
        }
        $first['status'] = 'executing';
        return ['ok' => true, 'plan' => $first];
    }

    /**
     * Plans from the last LIST_DAYS days (newest first). $clientId = the asking MCP client: it gets its own plans and
     * legacy ones without an owner; null = all plans (admin, CLI).
     */
    public static function recent(?string $clientId = null): array
    {
        $since = Clock::iso(Clock::now()->modify('-' . self::LIST_DAYS . ' days'));
        $columns = 'payload_json, status, result_json, executed_at, client_id';
        $rows = $clientId === null
            ? Db::all("SELECT $columns FROM plans WHERE created_at >= ? ORDER BY created_at DESC", [$since])
            : Db::all("SELECT $columns FROM plans WHERE created_at >= ? AND (client_id IS NULL OR client_id = ?) ORDER BY created_at DESC", [$since, $clientId]);
        return array_map([self::class, 'hydrate'], $rows);
    }

    /** Adds fields to a stored plan (e.g. execution time, post-execution fingerprint, undo marker). */
    public static function patch(string $id, array $fields): void
    {
        $p = self::get($id) ?? throw new \RuntimeException('Plan does not exist');
        self::save($fields + $p);
    }

    public static function finish(string $id, string $status, mixed $result): void
    {
        $p = self::get($id) ?? throw new \RuntimeException('Plan does not exist');
        $p['status'] = $status;
        $p['result'] = $result;
        self::save($p);
    }

    public static function prune(): void
    {
        Db::exec('DELETE FROM plans WHERE created_at < ?', [Clock::iso(Clock::now()->modify('-' . self::RETENTION_DAYS . ' days'))]);
    }
}
