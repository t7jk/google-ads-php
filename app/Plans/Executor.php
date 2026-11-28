<?php
declare(strict_types=1);

namespace App\Plans;

use App\Ads\{Errors, MutationRefused};
use App\Google\Ads;

/**
 * Execution of an approved plan (port of src/plans/executor.ts). The ONLY place that calls Ads::mutate with validateOnly=false.
 * Rules: claim via an atomic UPDATE (one request wins), account-state fingerprint check (stale plan = refusal),
 * write through the GuardOps guard (inside Ads::mutate), journal before and after. Plan kind behaviour is described in Kinds/*.php.
 * Result: ['ok' => bool, 'message' => string, 'results'? => mixed, 'alreadyUsed'? => true].
 */
final class Executor
{
    private const REFUSALS = [
        'not_found' => 'No such plan',
        'expired' => 'The plan has expired; prepare a new one',
        'hash_mismatch' => 'The plan differs from the preview',
        'already_used' => 'This plan has already been used',
    ];
    private const CHANGED = 'The campaign has changed since the preview; prepare a new plan';
    private const LAUNCH_REFUSED = 'You launch the campaign manually in Google Ads';
    private const INVALID = 'The plan has validation errors; fix them and prepare a new one';

    /** Replaces the write in unit tests: fn(array $ops, bool $validateOnly): array{results}. In production null = Ads::mutate. */
    public static ?\Closure $mutatorForTests = null;

    public static function approve(string $planId, string $hash): array
    {
        $peek = PlanStore::get($planId);
        if ($peek && $peek['status'] === 'pending' && isset($peek['validation']) && empty($peek['validation']['ok'])) {
            return self::refuse($planId, self::INVALID, false);
        }
        $c = PlanStore::claim($planId, $hash);
        if (!$c['ok']) {
            if ($c['reason'] === 'already_used' && (PlanStore::get($planId)['status'] ?? null) === 'done') {
                return ['ok' => true, 'message' => 'This plan has already been executed', 'alreadyUsed' => true];
            }
            $message = self::REFUSALS[$c['reason']];
            Journal::append('refused', $planId, $message);
            return ['ok' => false, 'message' => $message];
        }
        try {
            return self::run($c['plan']);
        } catch (\Throwable $e) {
            $message = Errors::describe($e);
            if ((PlanStore::get($planId)['status'] ?? null) === 'done') {
                return ['ok' => true, 'message' => 'Changes applied.'];
            }
            try {
                PlanStore::finish($planId, 'failed', ['message' => $message]);
            } catch (\Throwable) {
                // nothing more to do
            }
            Journal::append('execute_failed', $planId, $message);
            return ['ok' => false, 'message' => $message];
        }
    }

    /** Refusal to execute (the plan is closed with status refused if it was already claimed). Available to the kinds' own 'run' hooks. */
    public static function refuse(string $planId, string $message, bool $claimed): array
    {
        if ($claimed) {
            PlanStore::finish($planId, 'refused', ['message' => $message]);
        }
        Journal::append('refused', $planId, $message);
        return ['ok' => false, 'message' => $message];
    }

    /** Execution failure (status failed + journal). */
    public static function fail(string $planId, string $message): array
    {
        PlanStore::finish($planId, 'failed', ['message' => $message]);
        Journal::append('execute_failed', $planId, $message, 0);
        return ['ok' => false, 'message' => $message];
    }

    private static function run(array $plan): array
    {
        $id = $plan['id'];
        if ($plan['kind'] === 'selftest') {
            $message = 'Window test approved; everything works.';
            Journal::append('execute', $id, 'Test okna zatwierdzony', 0);
            PlanStore::finish($id, 'done', ['message' => $message]);
            return ['ok' => true, 'message' => $message];
        }
        if ($plan['kind'] === 'launch') {
            return self::refuse($id, self::LAUNCH_REFUSED, true);
        }
        $def = Kinds::get($plan['kind']);
        if (isset($def['run'])) {
            return $def['run']($plan);
        }
        if ($def === null || (empty($def['fingerprint']) && empty($def['noSnapshot']))) {
            return self::refuse($id, 'This plan kind is not supported yet', true);
        }
        if (!empty($plan['undoOf']) && ($why = self::undoOriginalRefusal($plan['undoOf'])) !== null) {
            return self::refuse($id, $why, true);
        }
        $planOps = Plan::ops($plan);
        if ($errs = Guard::assertNoEnable($planOps)) {
            return self::refuse($id, $errs[0], true);
        }
        if (!empty($def['needsCampaign']) && empty($plan['campaignId'])) {
            return self::refuse($id, 'The plan does not point to a campaign; prepare a new one', true);
        }
        if (!empty($def['fingerprint'])) {
            try {
                $current = $def['fingerprint']($plan);
            } catch (\Throwable $e) {
                return self::fail($id, Errors::describe($e, 'read'));
            }
            if ($current !== $plan['beforeFingerprint']) {
                return self::refuse($id, self::CHANGED, true);
            }
        }
        // Journal trace before the first real change: an "executing" plan without a final entry can be diagnosed.
        Journal::append('execute_started', $id, "Execution started: {$plan['summary']}");
        return self::send($plan, $def, $planOps);
    }

    private static function undoOriginalRefusal(string $originalId): ?string
    {
        $orig = PlanStore::get($originalId);
        return $orig ? Undo::refusal($orig) : 'The plan this one was meant to undo does not exist';
    }

    /** Sending the operations to Google (blob materialization -> mutate) and storing the result. */
    private static function send(array $plan, array $def, array $planOps): array
    {
        $id = $plan['id'];
        try {
            try {
                $materialize = Kinds::hook('materialize');
                $toSend = $materialize ? $materialize($planOps) : $planOps;
            } catch (MissingBlob $e) {
                return self::refuse($id, $e->getMessage(), true);
            }
            $mutate = $def['mutate'] ?? self::$mutatorForTests ?? fn(array $ops, bool $vo) => Ads::mutate($ops, $vo);
            if (isset($def['mutate']) && self::$mutatorForTests) {
                $mutate = self::$mutatorForTests;
            }
            $results = $mutate($toSend, false)['results'];
        } catch (MutationRefused $e) {
            return self::refuse($id, $e->getMessage(), true);
        } catch (\Throwable $e) {
            return self::fail($id, 'Could not apply the changes: ' . Errors::describe($e) . '. Nothing was changed.');
        }
        $n = count($toSend);
        $ops = \App\Ads\Plural::withCount($n, 'operation', 'operations', 'operations');
        $default = $plan['kind'] === 'new_campaign' ? "Changes applied ($ops). The campaign stays paused." : "Changes applied ($ops).";
        $message = isset($def['message']) ? $def['message']($plan, $planOps, $results, $default) : $default;
        PlanStore::finish($id, 'done', ['message' => $message, 'results' => $results]);
        Journal::append('execute', $id, "{$plan['summary']} — $message", $n, ['resources' => self::resourceNames($results)]);
        self::recordForUndo($plan, $results);
        return ['ok' => true, 'message' => $message, 'results' => $results];
    }

    /** @return list<string> */
    private static function resourceNames(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            if (!empty($r['resourceName'])) {
                $out[] = $r['resourceName'];
                continue;
            }
            foreach ((array) $r as $k => $v) {
                if (str_ends_with((string) $k, 'Result') && !empty($v['resourceName'])) {
                    $out[] = $v['resourceName'];
                }
            }
        }
        return $out;
    }

    /**
     * After execution: execution time, fingerprint of the plan's scope (to refuse undo after later changes) and the "undone"
     * marker of the original plan. Mutate results (resourceName of created resources) are already in result.results. A read error does not spoil the execution.
     * Also available to the kinds' own 'run' hooks (e.g. offline: only executedAt).
     */
    public static function recordForUndo(array $plan, array $results): void
    {
        $fields = ['executedAt' => Clock::iso()];
        if (!empty($plan['undo']['ops'])) {
            try {
                $target = Undo::target(array_merge($plan, ['result' => ['results' => $results]]));
                $tdef = Kinds::get($target['kind']);
                if (!empty($tdef['fingerprint']) && (empty($tdef['needsCampaign']) || !empty($target['campaignId']))) {
                    $fields['afterFingerprint'] = $tdef['fingerprint'](array_merge($plan, $target));
                }
            } catch (\Throwable $e) {
                Journal::append('undo_unavailable', $plan['id'], 'State after execution was not saved (undo unavailable): ' . Errors::describe($e, 'read'));
            }
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
