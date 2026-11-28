<?php
// Unit test of the plan core with a STUBBED write (Executor::$mutatorForTests); nothing reaches Google Ads.
// Run: php tests/plans-executor.php  (uses a THROWAWAY data directory via GADS_DATA_DIR; the dev database is never opened).
declare(strict_types=1);
require __DIR__ . '/_env.php';

putenv('GADS_DATA_DIR=' . ($dataDir = make_data_dir()));
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Plans\{Executor, GuardOps, Json, Plan, PlanStore, Undo};

$fail = 0;
$check = checker($fail);

$mk = function (array $ops): array {
    return array_merge(Plan::base(), [
        'kind' => 'new_campaign', 'summary' => 'test', 'diff' => [], 'warnings' => [], 'validation' => ['ok' => true, 'errors' => []],
        'steps' => [['title' => 't', 'operations' => $ops]], 'beforeFingerprint' => 'x',
    ]);
};

// 1. Executing a plan with the mock: claim, result, status done, second claim rejected.
$calls = [];
Executor::$mutatorForTests = function (array $ops, bool $vo) use (&$calls): array {
    $calls[] = $vo;
    return ['results' => [['campaignBudgetResult' => ['resourceName' => 'customers/1/campaignBudgets/9']]]];
};
$p = $mk([['campaignBudgetOperation' => ['create' => ['resourceName' => 'customers/1/campaignBudgets/-1', 'amountMicros' => '1000000']]]]);
PlanStore::save($p);
$stored = PlanStore::get($p['id']);
$r = Executor::approve($p['id'], 'wrong-hash');
$check('wrong hash rejected', !$r['ok'] && $r['message'] === 'The plan differs from the preview', json_encode($r));
$r = Executor::approve($p['id'], Plan::hash($stored));
$check('execution ok', $r['ok'] && str_starts_with($r['message'], 'Changes applied (1 operation).'), json_encode($r));
$check('mock called once, validateOnly=false', $calls === [false], json_encode($calls));
$check('status done + executedAt', PlanStore::get($p['id'])['status'] === 'done' && !empty(PlanStore::get($p['id'])['executedAt']));
$r2 = Executor::approve($p['id'], Plan::hash($stored));
$check('second execution = already executed', $r2['ok'] && !empty($r2['alreadyUsed']), json_encode($r2));

// 2. Expired plan.
$e = $mk([]);
$e['expiresAt'] = '2000-01-01T00:00:00.000Z';
PlanStore::save($e);
$r = Executor::approve($e['id'], Plan::hash(PlanStore::get($e['id'])));
$check('expired plan rejected', !$r['ok'] && str_contains($r['message'], 'expired'), json_encode($r));

// 3. Guard: enabling a campaign and removing a budget are rejected.
$check('guard: ENABLED', (bool) GuardOps::checkMutateOps([['campaignOperation' => ['update' => ['status' => 'ENABLED'], 'updateMask' => 'status']]], 30000000, 30.0));
$check('guard: remove budget', (bool) GuardOps::checkMutateOps([['campaignBudgetOperation' => ['remove' => 'x']]], 30000000, 30.0));
$check('guard: budget too large', (bool) GuardOps::checkMutateOps([['campaignBudgetOperation' => ['update' => ['amountMicros' => '31000000']]]], 30000000, 30.0));
$check('guard: valid pause', GuardOps::checkMutateOps([['campaignOperation' => ['update' => ['status' => 'PAUSED'], 'updateMask' => 'status']]], 30000000, 30.0) === []);

// 4. Empty objects survive storage (maximizeConversions: {}).
$o = $mk([['campaignOperation' => ['update' => ['maximizeConversions' => new stdClass()], 'updateMask' => 'maximizeConversions']]]);
PlanStore::save($o);
$check('empty object preserved', str_contains(Json::encode(PlanStore::get($o['id'])['steps']), '"maximizeConversions":{}'));

// 5. Undo: removing a keyword -> re-create it; creating a link -> remove it.
$lookup = Undo::lookupFrom([['resourceName' => 'customers/1/campaignCriteria/5~6', 'negative' => true, 'keyword' => ['text' => 'a', 'matchType' => 'PHRASE']]]);
$u = Undo::derive([['campaignCriterionOperation' => ['remove' => 'customers/1/campaignCriteria/5~6']]], $lookup);
$check('undo: remove -> create', ($u['ops'][0]['campaignCriterionOperation']['create']['keyword']['text'] ?? '') === 'a', json_encode($u));
$u = Undo::derive([['campaignBudgetOperation' => ['update' => ['resourceName' => 'customers/1/campaignBudgets/1', 'amountMicros' => '5000000'], 'updateMask' => 'amountMicros']]],
    Undo::lookupFrom([['resourceName' => 'customers/1/campaignBudgets/1', 'amountMicros' => '3000000']]));
$check('undo: budget', ($u['ops'][0]['campaignBudgetOperation']['update']['amountMicros'] ?? '') === '3000000', json_encode($u));

Executor::$mutatorForTests = null;
remove_dir($dataDir);
echo $fail ? "\nFAILURES: $fail\n" : "\nAll OK\n";
exit($fail ? 1 : 0);
