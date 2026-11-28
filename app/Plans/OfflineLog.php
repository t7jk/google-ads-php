<?php
declare(strict_types=1);

namespace App\Plans;

use App\Lib\Db;

/**
 * Log of uploaded offline conversions (port of store/offline-log.ts; table offline_log): so the same orderId is never sent twice.
 * Entry: orderId, ts, result (ok|duplicate|error), code?, planId?. It holds no click identifiers or personal data.
 */
final class OfflineLog
{
    /** orderIds considered sent (ok, or a duplicate on Google's side); errors may be retried. @return array<string,true> */
    public static function sentIds(): array
    {
        $out = [];
        foreach (Db::all("SELECT DISTINCT order_id FROM offline_log WHERE result IN ('ok', 'duplicate')") as $r) {
            $out[(string) $r['order_id']] = true;
        }
        return $out;
    }

    /** @param list<array{orderId:string,ts:string,result:string,code?:string,planId?:string}> $entries */
    public static function append(array $entries): void
    {
        foreach ($entries as $e) {
            Db::exec('INSERT INTO offline_log (order_id, ts, result, code, plan_id) VALUES (?, ?, ?, ?, ?)', [$e['orderId'], $e['ts'], $e['result'], $e['code'] ?? null, $e['planId'] ?? null]);
        }
    }

    /** Latest entries (newest first). */
    public static function recent(int $limit): array
    {
        return Db::all('SELECT order_id AS orderId, ts, result, code, plan_id AS planId FROM offline_log ORDER BY id DESC LIMIT ' . max(1, $limit));
    }
}
