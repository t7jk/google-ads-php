<?php
declare(strict_types=1);

namespace App\Plans;

use App\Google\Ga4Admin;
use App\Plans\Build\Ga4KeyEventsPlan;

/**
 * Execution of a GA4 key events plan (port of runGa4 from executor.ts): a separate branch outside Google Ads, operations run one by one
 * (GA4 has no validateOnly or batching; stopping midway leaves some changes applied, the message says how many were done).
 * Called from Kinds/External.php ('run' of the ga4_key_events kind; the plan is already claimed by Executor::approve).
 */
final class Ga4Run
{
    /** Replaces the GA4 Admin client in tests (a class or object with listKeyEvents/createKeyEvent/deleteKeyEvent). In production null = Ga4Admin. */
    public static string|object|null $adminForTests = null;

    private static function admin(): string|object
    {
        return self::$adminForTests ?? Ga4Admin::class;
    }

    /** Current list of key events (read). */
    public static function listKeyEvents(): array
    {
        return call_user_func([self::admin(), 'listKeyEvents']);
    }

    /** Key events now, or null when the read failed (undo then refuses). */
    public static function listOrNull(): ?array
    {
        try {
            return self::listKeyEvents();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function run(array $plan): array
    {
        $id = $plan['id'];
        if (!empty($plan['undoOf'])) {
            $orig = PlanStore::get($plan['undoOf']);
            $why = $orig ? Undo::refusal($orig) : 'The plan this one was meant to undo does not exist';
            if ($why !== null) {
                return Executor::refuse($id, $why, true);
            }
        }
        $ops = Plan::ops($plan);
        if (array_filter($ops, fn($o) => array_keys($o) !== ['ga4KeyEventOperation'] || count($o['ga4KeyEventOperation']) !== 1)) {
            return Executor::refuse($id, 'The GA4 plan contains disallowed operations', true);
        }
        try {
            $before = Ga4KeyEventsPlan::fingerprint(self::listKeyEvents());
        } catch (\Throwable $e) {
            return Executor::fail($id, $e->getMessage());
        }
        if ($before !== $plan['beforeFingerprint']) {
            return Executor::refuse($id, 'GA4 key events changed since the preview; prepare a new plan', true);
        }
        Journal::append('execute_started', $id, "Execution started: {$plan['summary']}");
        $results = [];
        foreach ($ops as $o) {
            try {
                $results[] = self::apply($o['ga4KeyEventOperation']);
            } catch (\Throwable $e) {
                return Executor::fail($id, "Could not apply the changes in GA4: {$e->getMessage()}. Done " . count($results) . ' of ' . count($ops) . ' operations (GA4 has no atomic operations; check the state in ga4_key_events).');
            }
        }
        $message = 'Changes applied in GA4 (' . \App\Ads\Plural::withCount(count($ops), 'operation', 'operations', 'operations') . '). GA4 change history: GA4 panel -> Admin -> Change history.';
        PlanStore::finish($id, 'done', ['message' => $message, 'results' => $results]);
        Journal::append('execute', $id, "{$plan['summary']} — $message", count($ops), ['resources' => array_column($results, 'name')]);
        self::recordState($plan);
        return ['ok' => true, 'message' => $message, 'results' => $results];
    }

    /** One GA4 operation: create returns the name of the new event, delete the name of the deleted one. @return array{name:string} */
    private static function apply(array $g): array
    {
        if (isset($g['create'])) {
            $k = call_user_func([self::admin(), 'createKeyEvent'], $g['create']['eventName'], $g['create']['countingMethod']);
            return ['name' => $k['name']];
        }
        if (isset($g['delete'])) {
            call_user_func([self::admin(), 'deleteKeyEvent'], $g['delete']['name']);
            return ['name' => $g['delete']['name']];
        }
        throw new \RuntimeException('Unknown GA4 operation');
    }

    /** Execution time, fingerprint of the list after execution (for undo) and the "undone" marker of the original plan. A read error does not spoil the execution. */
    private static function recordState(array $plan): void
    {
        $fields = ['executedAt' => Clock::iso()];
        $after = self::listOrNull();
        if ($after !== null) {
            $fields['afterFingerprint'] = Ga4KeyEventsPlan::fingerprint($after);
        }
        try {
            PlanStore::patch($plan['id'], $fields);
            if (!empty($plan['undoOf']) && PlanStore::get($plan['undoOf'])) {
                PlanStore::patch($plan['undoOf'], ['undoneBy' => $plan['id'], 'undoneAt' => $fields['executedAt']]);
            }
        } catch (\Throwable) {
            // auxiliary write; the execution already succeeded
        }
    }
}
