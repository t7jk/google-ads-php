<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Ga4\KeyEvents;
use App\Plans\{Json, Plan, Undo};

/**
 * Plan for marking/unmarking GA4 key events (port of ga4/key-events.ts; writes to the GA4 Admin API, not to Google Ads).
 * GA4 has no validateOnly and no atomic operations: the plan is checked locally and executed operation by operation (Plans\Ga4Run).
 * Operacja: ['ga4KeyEventOperation' => ['create' => [eventName, countingMethod]] | ['delete' => [name, eventName, countingMethod]]].
 * Undo: undo.ops are the inverse operations; the name of a created event is ['$result' => operation index] (resolved from the results).
 */
final class Ga4KeyEventsPlan
{
    /** Key event limit in a standard property (GA4 360: 50). */
    public const MAX_KEY_EVENTS = 30;
    private const SECTION = 'Key events';
    private const NOTE = 'This change applies to GA4 (not Google Ads); the GA4 change history is in the GA4 UI (Admin → Change history)';

    /** Fingerprint of the key event list: sorted "name:method" items. @param list<array{eventName:string,countingMethod:string}> $list */
    public static function fingerprint(array $list): string
    {
        $items = array_map(fn($k) => "{$k['eventName']}:{$k['countingMethod']}", $list);
        sort($items, SORT_STRING);
        return Json::fingerprint($items);
    }

    private static function dupes(array $xs): array
    {
        return array_keys(array_filter(array_count_values($xs), fn($n) => $n > 1));
    }

    private static function isDelete(array $op): bool
    {
        return isset($op['ga4KeyEventOperation']['delete']);
    }

    /** Inverse operations: create -> delete using the result (resource name), delete -> create with the old counting method. */
    private static function inverseOps(array $ops): array
    {
        $out = [];
        foreach (array_values($ops) as $i => $op) {
            $g = $op['ga4KeyEventOperation'];
            $out[] = isset($g['create'])
                ? ['ga4KeyEventOperation' => ['delete' => ['name' => ['$result' => $i], 'eventName' => $g['create']['eventName'], 'countingMethod' => $g['create']['countingMethod']]]]
                : ['ga4KeyEventOperation' => ['create' => ['eventName' => $g['delete']['eventName'], 'countingMethod' => $g['delete']['countingMethod']]]];
        }
        return $out;
    }

    private static function row(string $name, string $before, string $after, string $change, array $extra = []): array
    {
        return ['section' => self::SECTION, 'label' => $name, 'before' => $before, 'after' => $after, 'change' => $change] + $extra;
    }

    /**
     * @param array{mark?:list<string>,unmark?:list<string>,countingMethod?:string} $req
     * @param list<array> $current Ga4Admin::listKeyEvents @param array<string,true>|null $recentNames events seen in the last 30 days of data (null = could not be checked)
     */
    public static function plan(array $req, array $current, ?array $recentNames, \DateTimeImmutable $now): array
    {
        $mark = $req['mark'] ?? [];
        $unmark = $req['unmark'] ?? [];
        $method = $req['countingMethod'] ?? 'ONCE_PER_EVENT';
        $errors = [];
        foreach (self::dupes($mark) as $d) {
            $errors[] = "Event \"{$d}\" appears more than once in the mark list";
        }
        foreach (self::dupes($unmark) as $d) {
            $errors[] = "Event \"{$d}\" appears more than once in the unmark list";
        }
        foreach (array_filter($mark, fn($x) => in_array($x, $unmark, true)) as $d) {
            $errors[] = "Event \"{$d}\" is in both the mark and unmark lists";
        }
        $byName = [];
        foreach ($current as $k) {
            $byName[$k['eventName']] = $k;
        }
        $warnings = [];
        $diff = [];
        $ops = [];
        foreach (array_unique($unmark) as $name) {
            self::unmark($name, $byName, $ops, $diff, $warnings);
        }
        foreach (array_unique($mark) as $name) {
            self::mark($name, $method, $byName, $recentNames, $ops, $diff, $warnings, $errors);
        }
        $creates = count(array_filter($ops, fn($o) => !self::isDelete($o)));
        $deletes = count($ops) - $creates;
        if (count($current) - $deletes + $creates > self::MAX_KEY_EVENTS) {
            $errors[] = 'After the change there would be ' . (count($current) - $deletes + $creates) . ' key events; the property limit is ' . self::MAX_KEY_EVENTS;
        }
        usort($ops, fn($a, $b) => (int) self::isDelete($b) <=> (int) self::isDelete($a)); // deletions first (the sort is stable)
        return array_merge(Plan::base($now), [
            'kind' => 'ga4_key_events',
            'summary' => $ops ? "GA4 key events: mark $creates, unmark $deletes" : 'GA4 key events: no changes',
            'diff' => $diff, 'steps' => $ops ? [['title' => 'GA4 key events', 'operations' => $ops]] : [], 'warnings' => $warnings,
            'validation' => ['ok' => !$errors, 'errors' => $errors], 'beforeFingerprint' => self::fingerprint($current),
            'undo' => ['ops' => self::inverseOps($ops), 'notUndoable' => [], 'notes' => [self::NOTE]],
        ]);
    }

    private static function unmark(string $name, array $byName, array &$ops, array &$diff, array &$warnings): void
    {
        $k = $byName[$name] ?? null;
        if (!$k) {
            $warnings[] = "\"{$name}\" is not a key event; skipping";
            return;
        }
        if (($k['deletable'] ?? null) === false) {
            $warnings[] = "\"{$name}\" cannot be unmarked via the API (deletable=false); do it manually in GA4 (Admin → Events → Key events)";
            return;
        }
        $ops[] = ['ga4KeyEventOperation' => ['delete' => ['name' => $k['name'], 'eventName' => $name, 'countingMethod' => $k['countingMethod']]]];
        $diff[] = self::row($name, "key event ({$k['countingMethod']})", 'not a key event', 'remove');
    }

    private static function mark(string $name, string $method, array $byName, ?array $recentNames, array &$ops, array &$diff, array &$warnings, array &$errors): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $name)) {
            $errors[] = "Invalid event name: $name";
            return;
        }
        if (isset($byName[$name])) {
            $warnings[] = "\"{$name}\" is already a key event; skipping";
            return;
        }
        if ($recentNames !== null && !isset($recentNames[$name])) {
            $warnings[] = "\"{$name}\" did not occur in the GA4 data of the last 30 days; check the spelling (an event can be marked in advance, but it may never appear)";
        }
        $noisy = KeyEvents::noisyWarning($name);
        if ($noisy !== null) {
            $warnings[] = $noisy;
        }
        $ops[] = ['ga4KeyEventOperation' => ['create' => ['eventName' => $name, 'countingMethod' => $method]]];
        $diff[] = self::row($name, 'not a key event', "key event ($method)", 'add', $noisy !== null ? ['level' => 'warn', 'note' => $noisy] : []);
    }

    /**
     * Undo plan for a ga4_key_events plan: create <-> delete; refuses when the event list changed after execution.
     * @param array|null $current current list (null = could not be read)
     * @return array{plan: array}|array{message: string, refused: bool}
     */
    public static function undoPlan(array $p, ?array $current, \DateTimeImmutable $now): array
    {
        if ($refusal = Undo::refusal($p, $now)) {
            return ['message' => $refusal, 'refused' => true];
        }
        if (empty($p['afterFingerprint'])) {
            return ['message' => 'The GA4 state after executing this plan was not saved; undo the change manually in the GA4 UI', 'refused' => true];
        }
        if ($current === null) {
            return ['message' => Undo::NO_STATE_CHECK, 'refused' => true];
        }
        if (self::fingerprint($current) !== $p['afterFingerprint']) {
            return ['message' => Undo::ACCOUNT_CHANGED, 'refused' => true];
        }
        $results = Undo::planResults($p);
        $ops = [];
        foreach ($p['undo']['ops'] as $op) {
            $g = $op['ga4KeyEventOperation'] ?? [];
            if (isset($g['delete']) && is_array($g['delete']['name'])) {
                $name = $results[$g['delete']['name']['$result']]['name'] ?? null;
                if (!$name) {
                    return ['message' => 'The name of the created event is missing from the results; undo manually in the GA4 UI', 'refused' => false];
                }
                $op = ['ga4KeyEventOperation' => ['delete' => ['name' => $name] + $g['delete']]];
            }
            $ops[] = $op;
        }
        return ['plan' => array_merge(Plan::base($now), [
            'kind' => 'ga4_key_events', 'summary' => 'Undo of plan ' . substr($p['id'], 0, 8) . ': GA4 key events', 'diff' => self::undoDiff($ops),
            'steps' => [['title' => 'Undo GA4 key events', 'operations' => $ops]], 'warnings' => [], 'validation' => ['ok' => true, 'errors' => []],
            'beforeFingerprint' => self::fingerprint($current), 'undoOf' => $p['id'],
            'undo' => ['ops' => self::inverseOps($ops), 'notUndoable' => [], 'notes' => []],
        ])];
    }

    private static function undoDiff(array $ops): array
    {
        return array_map(function ($o) {
            $g = $o['ga4KeyEventOperation'];
            return isset($g['create'])
                ? self::row($g['create']['eventName'], 'not a key event', "key event ({$g['create']['countingMethod']})", 'add')
                : self::row($g['delete']['eventName'], "key event ({$g['delete']['countingMethod']})", 'not a key event', 'remove');
        }, $ops);
    }
}
