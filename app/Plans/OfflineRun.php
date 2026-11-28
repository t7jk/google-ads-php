<?php
declare(strict_types=1);

namespace App\Plans;

use App\Ads\{AdsApiError, Errors, MutationRefused};
use App\Google\Ads;
use App\Lib\Events;

/**
 * Validation and execution of an offline conversions plan (port of the offline branch of executor.ts and tools-plan.ts). Called from Kinds/Pmax.php
 * ('validate' and 'run' of the offline_conversions kind). The result of each conversion goes to OfflineLog (deduplication by orderId).
 * Pitfall: partialFailure: Google returns 200 even when individual conversions are rejected; rejected ones have an empty entry in results.
 */
final class OfflineRun
{
    private const UNDO_NOTE = 'Cannot be undone: uploaded conversions cannot be withdrawn through the API.';

    /** Replaces the upload in tests: fn(array $convs, bool $validateOnly): array{results, partialFailureError?}. In production null = Ads::uploadClickConversions. */
    public static ?\Closure $senderForTests = null;

    private static function send(array $convs, bool $validateOnly): array
    {
        return self::$senderForTests ? (self::$senderForTests)($convs, $validateOnly) : Ads::uploadClickConversions($convs, $validateOnly);
    }

    /**
     * Errors from partialFailureError: conversion index => error code. Rejected ones have an empty entry in $results.
     * @return array<int,string>
     */
    public static function partialFailures(?array $err, int $count, array $results): array
    {
        $out = [];
        foreach ((array) ($err['details'] ?? []) as $d) {
            foreach ((array) ($d['errors'] ?? []) as $x) {
                $idx = self::errorIndex($x);
                if ($idx !== null && $idx >= 0 && $idx < $count && !isset($out[$idx])) {
                    $out[$idx] = self::errorCode($x);
                }
            }
        }
        if ($err && !$out) {
            for ($i = 0; $i < $count; $i++) {
                if (empty($results[$i])) {
                    $out[$i] = 'UNKNOWN';
                }
            }
        }
        return $out;
    }

    private static function errorIndex(mixed $x): ?int
    {
        foreach ((array) ($x['location']['fieldPathElements'] ?? []) as $f) {
            if (($f['fieldName'] ?? null) === 'conversions' && isset($f['index']) && is_int($f['index'])) {
                return $f['index'];
            }
        }
        return null;
    }

    private static function errorCode(mixed $x): string
    {
        foreach ((array) ($x['errorCode'] ?? []) as $v) {
            if (is_string($v)) {
                return $v;
            }
        }
        return 'UNKNOWN';
    }

    /** validateOnly in Google: rejected conversions add an error to the plan. Always returns true (handled). */
    public static function validate(array &$plan): bool
    {
        $ops = Plan::ops($plan);
        if (!$ops) {
            return true;
        }
        try {
            $convs = array_map(fn($o) => $o['clickConversionOperation'], $ops);
            $r = self::send($convs, true);
            $bad = self::partialFailures($r['partialFailureError'] ?? null, count($convs), []);
            if (!empty($r['partialFailureError']) && $bad) {
                $plan['validation']['errors'][] = 'Google rejected ' . count($bad) . ' of ' . count($convs) . ' conversions during validation: ' . implode(', ', array_unique($bad)) . ' (narrow the "from/to" date range and try again)';
                $plan['validation']['ok'] = false;
            }
        } catch (AdsApiError | MutationRefused $e) {
            Events::log('plan', ['event' => 'validate_failed', 'planId' => $plan['id'], 'ok' => false, 'message' => $e->getMessage()]);
            $plan['validation']['errors'][] = Errors::describe($e);
            $plan['validation']['ok'] = false;
        }
        return true;
    }

    /** Execution of an approved plan (the plan is already claimed by Executor::approve). @return array{ok:bool,message:string,results?:array} */
    public static function run(array $plan): array
    {
        $id = $plan['id'];
        $ops = Plan::ops($plan);
        if (!$ops || array_filter($ops, fn($o) => array_keys($o) !== ['clickConversionOperation'])) {
            return Executor::refuse($id, 'The offline conversions plan contains disallowed operations', true);
        }
        $convs = array_map(fn($o) => $o['clickConversionOperation'], $ops);
        $sent = OfflineLog::sentIds();
        if (array_filter($convs, fn($c) => isset($sent[(string) $c['orderId']]))) {
            return Executor::refuse($id, 'Some of the conversions have already been sent; prepare a new plan', true);
        }
        Journal::append('execute_started', $id, "Execution started: {$plan['summary']}");
        try {
            $r = self::send($convs, false);
        } catch (MutationRefused $e) {
            return Executor::refuse($id, $e->getMessage(), true);
        } catch (\Throwable $e) {
            return Executor::fail($id, 'Could not send the conversions: ' . Errors::describe($e) . '. Nothing was sent.');
        }
        $failed = self::partialFailures($r['partialFailureError'] ?? null, count($convs), $r['results'] ?? []);
        $entries = self::logEntries($convs, $failed, $id);
        try {
            OfflineLog::append($entries);
        } catch (\Throwable) {
            // auxiliary log; Google deduplicates by orderId anyway
        }
        return self::finish($plan, $entries, count($convs));
    }

    /** @param array<int,string> $failed @return list<array> entries of the upload log */
    private static function logEntries(array $convs, array $failed, string $planId): array
    {
        $ts = Clock::iso();
        $out = [];
        foreach ($convs as $i => $c) {
            $base = ['orderId' => (string) $c['orderId'], 'ts' => $ts, 'planId' => $planId];
            $out[] = !isset($failed[$i]) ? $base + ['result' => 'ok']
                : $base + ['result' => $failed[$i] === 'DUPLICATE_ORDER_ID' ? 'duplicate' : 'error', 'code' => $failed[$i]];
        }
        return $out;
    }

    private static function finish(array $plan, array $entries, int $total): array
    {
        $count = fn(string $r) => count(array_filter($entries, fn($e) => $e['result'] === $r));
        [$okN, $dupN, $errN] = [$count('ok'), $count('duplicate'), $count('error')];
        $codes = implode(', ', array_unique(array_map(fn($e) => $e['code'] ?? '', array_filter($entries, fn($e) => $e['result'] === 'error'))));
        $message = "Sent $okN of $total conversions" . ($dupN ? ", $dupN already existed in Google (duplicate orderId)" : '')
            . ($errN ? ", $errN rejected (codes: " . ($codes ?: 'unknown') . '; check the upload log, you can retry them with another plan)' : '') . '. ' . self::UNDO_NOTE;
        $results = array_map(fn($e) => ['orderId' => $e['orderId'], 'result' => $e['result']] + (isset($e['code']) ? ['code' => $e['code']] : []), $entries);
        $ok = $okN + $dupN > 0 || $errN === 0;
        PlanStore::finish($plan['id'], $ok ? 'done' : 'failed', ['message' => $message, 'results' => $results]);
        Journal::append($okN + $dupN > 0 ? 'execute' : 'execute_failed', $plan['id'], "{$plan['summary']} — $message", $okN);
        Executor::recordForUndo($plan, []); // a plan without reverse operations: only the execution time
        return ['ok' => $ok, 'message' => $message, 'results' => $results];
    }
}
