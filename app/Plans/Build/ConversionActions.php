<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Plans\{Cfg, Goals, Json, Plan, Text, Undo};

/**
 * Conversion action plans (port of build-conversion-action.ts): create (WEBPAGE, AD_CALL, UPLOAD_CLICKS) and update an existing one.
 * The old mode (no mode: the LP2 action from the config) lives in Misc::conversionActionPlan.
 * Pitfalls: the action type cannot be changed after creation; actions that are read-only in the API (Goals::isNonMutableActionType) are not changed,
 * we give the manual path instead; there is no deletion (status is only ENABLED/HIDDEN); creating an action is not undone (Undo::NOT_UNDOABLE_CREATE).
 */
final class ConversionActions
{
    /** Manual path in the UI for actions the API cannot change. */
    public const MANUAL_ACTION_PATH = 'Google Ads → Goals → Conversions → action name → Settings';
    private const CREATE_ONLY = ['type', 'phoneCallDurationSeconds'];
    private const UPDATE_ONLY = ['conversionActionId', 'status'];
    private const DEFAULT_LOOKBACK_DAYS = 30;
    private const DEFAULT_CALL_SECONDS = 60;

    /**
     * Extra input rules (superRefine from the Node version, which JSON Schema cannot express). @return list<string> error messages
     */
    public static function inputErrors(array $v): array
    {
        $mode = $v['mode'] ?? null;
        if ($mode === null) {
            $extra = array_keys($v);
            return $extra ? ['Fields ' . implode(', ', $extra) . ' require a mode: set mode to create or update'] : [];
        }
        $e = [];
        if ($mode === 'create') {
            foreach (self::UPDATE_ONLY as $k) {
                if (isset($v[$k])) {
                    $e[] = "Field $k is allowed only with mode=update";
                }
            }
            $type = $v['type'] ?? null;
            if ($type === null) {
                $e[] = 'With mode=create give type: WEBPAGE, AD_CALL or UPLOAD_CLICKS';
            }
            if (($type === 'AD_CALL' || $type === 'UPLOAD_CLICKS') && empty($v['name'])) {
                $e[] = "Type $type requires the name field";
            }
            if ($type !== 'AD_CALL' && isset($v['phoneCallDurationSeconds'])) {
                $e[] = 'phoneCallDurationSeconds applies only to type AD_CALL';
            }
            if (isset($v['alwaysUseDefaultValue']) && !isset($v['defaultValue'])) {
                $e[] = 'alwaysUseDefaultValue requires defaultValue';
            }
            return $e;
        }
        foreach (self::CREATE_ONLY as $k) {
            if (isset($v[$k])) {
                $e[] = "Field $k cannot be changed (the action type is immutable; set the call duration manually in the UI)";
            }
        }
        if (empty($v['conversionActionId'])) {
            $e[] = 'With mode=update give conversionActionId';
        }
        return $e;
    }

    private static function countPl(mixed $c): string
    {
        return $c === 'MANY_PER_CLICK' ? 'many per click' : ($c === 'ONE_PER_CLICK' ? 'one per click' : (string) ($c ?? 'unknown'));
    }

    private static function pln(mixed $n): string
    {
        return $n === null ? 'none' : 'PLN ' . Text::numStr($n);
    }

    /** Query for the action to change (full fields, with resourceName for undo). ID is digits only (schema validation + cast). */
    public static function detailQuery(string $id): string
    {
        return 'SELECT conversion_action.resource_name, conversion_action.id, conversion_action.name, conversion_action.status, conversion_action.type, conversion_action.category, conversion_action.primary_for_goal, conversion_action.counting_type, conversion_action.click_through_lookback_window_days, conversion_action.value_settings.default_value, conversion_action.value_settings.always_use_default_value FROM conversion_action WHERE conversion_action.id = '
            . (int) $id . " AND conversion_action.status != 'REMOVED'";
    }

    /** Action fingerprint (the builder and the loader build the same fields before execution; a missing row = null). */
    public static function fingerprint(string $id, ?array $row): string
    {
        return Json::fingerprint(['conversionAction' => $id, 'row' => $row === null ? null : [
            'name' => $row['name'] ?? null, 'status' => $row['status'] ?? null, 'primary' => $row['primaryForGoal'] ?? null, 'counting' => $row['countingType'] ?? null,
            'window' => isset($row['clickThroughLookbackWindowDays']) ? (int) $row['clickThroughLookbackWindowDays'] : null,
            'value' => $row['valueSettings']['defaultValue'] ?? null, 'always' => $row['valueSettings']['alwaysUseDefaultValue'] ?? null,
        ]]);
    }

    private static function row(string $label, string $after, string $change = 'add'): array
    {
        return ['section' => 'Conversion', 'label' => $label, 'before' => null, 'after' => $after, 'change' => $change];
    }

    /** New action. @param list<array> $existing account actions (conversionAction), used to check the name */
    public static function createPlan(array $input, array $existing, \DateTimeImmutable $now): array
    {
        $type = $input['type'];
        $name = $input['name'] ?? Cfg::conversionActionName();
        $errors = [];
        foreach ($existing as $a) {
            if (trim((string) ($a['name'] ?? '')) === $name && ($a['status'] ?? null) !== 'REMOVED') {
                $errors[] = 'A conversion action with this name already exists';
                break;
            }
        }
        $counting = $input['countingType'] ?? 'ONE_PER_CLICK';
        $window = $input['clickThroughLookbackWindowDays'] ?? self::DEFAULT_LOOKBACK_DAYS;
        $category = $type === 'AD_CALL' ? 'PHONE_CALL_LEAD' : 'SUBMIT_LEAD_FORM';
        $primary = $input['primaryForGoal'] ?? ($type !== 'UPLOAD_CLICKS');
        $value = ['defaultValue' => $input['defaultValue'] ?? 0, 'alwaysUseDefaultValue' => $input['alwaysUseDefaultValue'] ?? true];
        $create = ['name' => $name, 'type' => $type, 'category' => $category, 'status' => 'ENABLED', 'countingType' => $counting,
            'primaryForGoal' => $primary, 'clickThroughLookbackWindowDays' => $window, 'valueSettings' => $value];
        if ($type === 'AD_CALL') {
            $create['phoneCallDurationSeconds'] = $input['phoneCallDurationSeconds'] ?? self::DEFAULT_CALL_SECONDS;
        }
        $typePl = ['WEBPAGE' => 'Website', 'AD_CALL' => 'Calls from ads (call extension)'][$type] ?? 'Click import (offline upload)';
        $diff = [
            self::row('Name', $name), self::row('Type', $typePl), self::row('Category', $category),
            self::row('Counting', self::countPl($counting) . ", $window-day window"),
            self::row('Value', self::pln($value['defaultValue']) . ' (' . ($value['alwaysUseDefaultValue'] ? 'fixed' : 'default') . ')'),
            self::row('Primary conversion', $primary ? 'yes' : 'no'),
        ];
        $warnings = [];
        if ($type === 'AD_CALL') {
            array_splice($diff, 4, 0, [self::row('Minimum call duration', $create['phoneCallDurationSeconds'] . ' s')]);
        }
        if ($type === 'UPLOAD_CLICKS') {
            $w = $primary
                ? 'The action is primary: a form submission may be counted twice (tag + import), because order_id deduplication works only within a single action'
                : 'The action stays secondary (not primary) so a submission is not counted twice with the tag. Making it primary is your decision';
            $warnings[] = $w;
            $diff[] = ['section' => 'Conversion', 'label' => 'Note', 'before' => null, 'after' => $w, 'change' => 'info', 'level' => 'warn'];
        }
        return Undo::attach(array_merge(Plan::base($now), [
            'kind' => 'conversion_action', 'summary' => "New conversion action \"{$name}\" ($type)", 'diff' => $diff,
            'steps' => [['title' => 'New conversion action', 'operations' => [['conversionActionOperation' => ['create' => $create]]]]],
            'warnings' => $warnings, 'validation' => ['ok' => !$errors, 'errors' => $errors],
            'beforeFingerprint' => Json::fingerprint(['conversionAction' => $name, 'type' => $type, 'existing' => array_map(fn($a) => $a['name'] ?? null, $existing)]),
        ]));
    }

    /**
     * Change of an existing action. @param array|null $row conversionAction object from detailQuery, or null
     * @return array{plan: array}|array{refused: string}
     */
    public static function updatePlan(array $input, ?array $row, \DateTimeImmutable $now): array
    {
        $id = $input['conversionActionId'];
        if ($row === null) {
            return ['refused' => "Conversion action with ID $id not found"];
        }
        if (Goals::isNonMutableActionType($row['type'] ?? null)) {
            return ['refused' => "Action \"{$row['name']}\" (type {$row['type']}) is read-only in the Google Ads API and cannot be changed through the connector. Change it manually: " . self::MANUAL_ACTION_PATH . '.'];
        }
        $rn = (string) ($row['resourceName'] ?? 'customers/' . Cfg::customerId() . "/conversionActions/$id");
        [$update, $mask, $diff] = self::changes($input, $row);
        if (!$mask) {
            return ['refused' => "Action \"{$row['name']}\" already has these settings: nothing to change"];
        }
        $plan = Undo::attach(array_merge(Plan::base($now), [
            'kind' => 'conversion_action_update', 'conversionActionId' => $id, 'summary' => "Change conversion action \"{$row['name']}\"", 'diff' => $diff,
            'steps' => [['title' => 'Change conversion action', 'operations' => [['conversionActionOperation' => ['update' => ['resourceName' => $rn] + $update, 'updateMask' => implode(',', $mask)]]]]],
            'warnings' => [], 'validation' => ['ok' => true, 'errors' => []],
            'beforeFingerprint' => self::fingerprint($id, $row),
        ]), ['resourceName' => $rn] + $row);
        return ['plan' => $plan];
    }

    /** Equality like === in JS: numbers are compared numerically (Google returns 50, the client may send 50.0). */
    private static function same(mixed $a, mixed $b): bool
    {
        return (is_int($a) || is_float($a)) && (is_int($b) || is_float($b)) ? (float) $a === (float) $b : $a === $b;
    }

    /** Fields to change: only those given and different from the current ones. @return array{0:array,1:list<string>,2:list<array>} [update body, mask, preview rows] */
    private static function changes(array $input, array $row): array
    {
        $update = [];
        $mask = [];
        $diff = [];
        $yesNo = fn($v) => $v ? 'yes' : 'no';
        $str = fn($v) => (string) $v;
        $window = isset($row['clickThroughLookbackWindowDays']) ? (int) $row['clickThroughLookbackWindowDays'] : null;
        // input field, mask, label, current value, formatter, path in the update body
        $fields = [
            ['name', 'name', 'Name', $row['name'] ?? null, $str, ['name']],
            ['primaryForGoal', 'primaryForGoal', 'Primary conversion', $row['primaryForGoal'] ?? true, $yesNo, ['primaryForGoal']],
            ['countingType', 'countingType', 'Counting', $row['countingType'] ?? null, [self::class, 'countPl'], ['countingType']],
            ['clickThroughLookbackWindowDays', 'clickThroughLookbackWindowDays', 'Conversion window (days)', $window, $str, ['clickThroughLookbackWindowDays']],
            ['defaultValue', 'valueSettings.defaultValue', 'Default value', $row['valueSettings']['defaultValue'] ?? null, [self::class, 'pln'], ['valueSettings', 'defaultValue']],
            ['alwaysUseDefaultValue', 'valueSettings.alwaysUseDefaultValue', 'Always use default value', $row['valueSettings']['alwaysUseDefaultValue'] ?? null, $yesNo, ['valueSettings', 'alwaysUseDefaultValue']],
            ['status', 'status', 'Status', $row['status'] ?? null, $str, ['status']],
        ];
        foreach ($fields as [$key, $field, $label, $before, $show, $path]) {
            $after = $input[$key] ?? null;
            if ($after === null || self::same($before, $after)) {
                continue;
            }
            $mask[] = $field;
            if (count($path) === 2) {
                $update[$path[0]][$path[1]] = $after;
            } else {
                $update[$path[0]] = $after;
            }
            $diff[] = ['section' => 'Conversion', 'label' => $label, 'before' => $before === null ? null : $show($before), 'after' => $show($after), 'change' => 'change'];
        }
        return [$update, $mask, $diff];
    }
}
