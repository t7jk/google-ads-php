<?php
// Unit test of the D2 plans with STUBBED writes (Executor::$mutatorForTests, OfflineRun::$senderForTests, Ga4Run::$adminForTests):
// nothing reaches Google Ads or GA4. Reads (the PMax snapshot) are real but read-only.
// Run: php tests/plans-d2.php  (writes and cleans up plan rows and upload-log rows in the dev database).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
} // CLI only, never via the web

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Lib\Db;
use App\Media\Blobs;
use App\Plans\Build\{AssetGroup, ConversionActions, Ga4KeyEventsPlan, Offline, PmaxRules, Signals};
use App\Plans\{BlobOps, Clock, Executor, Ga4Run, MissingBlob, OfflineLog, OfflineRun, Plan, PlanStore, Undo};
use App\Plans\Snapshot\PmaxSnapshot;

$fail = 0;
$check = function (string $name, bool $ok, string $info = '') use (&$fail): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $name . ($ok ? '' : " $info") . "\n";
    $fail += $ok ? 0 : 1;
};
$ids = [];
$now = Clock::now();
$exec = function (array $plan) use (&$ids): array {
    PlanStore::save($plan);
    $ids[] = $plan['id'];
    return Executor::approve($plan['id'], Plan::hash(PlanStore::get($plan['id'])));
};

// 1. Blob references: collecting, materializing, missing file.
$sha = Blobs::put("\x89PNG test", 'png');
$ops = [['assetOperation' => ['create' => ['imageAsset' => ['data' => BlobOps::ref($sha)], 'other' => ['a' => 1]]]]];
$check('blob: collect', BlobOps::collect($ops) === [$sha]);
$check('blob: materialize', BlobOps::materialize($ops)[0]['assetOperation']['create']['imageAsset']['data'] === base64_encode("\x89PNG test"));
try {
    BlobOps::materialize([['x' => BlobOps::ref(str_repeat('a', 64))]]);
    $check('blob: missing file -> MissingBlob', false);
} catch (MissingBlob $e) {
    $check('blob: missing file -> MissingBlob', $e->getMessage() === BlobOps::MISSING_MESSAGE);
}

// 2. PMax rules.
$check('rules: text too long', str_contains((string) PmaxRules::checkText('HEADLINE', str_repeat('a', 31)), '31 characters'));
$check('rules: youtube', PmaxRules::youtubeId('https://youtu.be/dQw4w9WgXcQ?t=3') === 'dQw4w9WgXcQ' && PmaxRules::youtubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQ') === 'dQw4w9WgXcQ' && PmaxRules::youtubeId('not a video') === null);

// 3. Custom audience: execution with the mock, message with the ID.
Executor::$mutatorForTests = fn(array $ops, bool $vo) => ['results' => [['resourceName' => 'customers/1/customAudiences/777']]];
$realNames = array_map(fn($r) => (string) ($r['customAudience']['name'] ?? ''), App\Google\Ads::search(Signals::CUSTOM_AUDIENCE_NAMES_QUERY)); // plan fingerprint = the real list (read)
$plan = Signals::customAudiencePlan(['name' => 'Test D2 ' . bin2hex(random_bytes(3)), 'keywords' => ['english for kids'], 'urls' => ['https://example.com/x']], $realNames, $now);
$check('custom_audience: plan valid', $plan['validation']['ok'], json_encode($plan['validation']));
$r = $exec($plan);
$check('custom_audience: executed with ID', $r['ok'] && str_contains($r['message'], 'ID 777'), json_encode($r));
$dup = Signals::customAudiencePlan(['name' => 'other'], ['Other'], $now);
$check('custom_audience: duplicate name', !$dup['validation']['ok']);

// 4. Asset group (real snapshot, mocked write): image materialization + refusal when the blob is missing.
$camp = '23009915985';
$snap = PmaxSnapshot::load($camp);
if ($snap['campaign'] && $snap['assetGroups']) {
    $gid = (string) $snap['assetGroups'][0]['assetGroup']['id'];
    $sent = [];
    Executor::$mutatorForTests = function (array $ops, bool $vo) use (&$sent): array {
        $sent = $ops;
        return ['results' => array_map(fn($o) => [array_key_first($o) === 'assetOperation' ? 'assetResult' : 'x' => ['resourceName' => 'customers/1/assets/9']], $ops)];
    };
    $img = ['sha' => $sha, 'width' => 1200, 'height' => 628, 'bytes' => 9, 'role' => 'image', 'fieldType' => 'MARKETING_IMAGE', 'name' => 'test d2'];
    $plan = AssetGroup::plan(['campaignId' => $camp, 'assetGroup' => $gid], $snap, [$img], [], $now);
    $check('asset_group: plan with an image (no errors)', $plan['validation']['ok'], json_encode($plan['validation']));
    $check('asset_group: blob reference in the plan', BlobOps::collect(Plan::ops($plan)) === [$sha]);
    $r = $exec($plan);
    $check('asset_group: executed', $r['ok'], json_encode($r));
    $check('asset_group: image bytes sent as base64', ($sent[0]['assetOperation']['create']['imageAsset']['data'] ?? '') === base64_encode("\x89PNG test"), json_encode($sent[0] ?? null));
    // a plan whose blob no longer exists
    $missing = ['sha' => str_repeat('b', 64)] + $img;
    $plan2 = AssetGroup::plan(['campaignId' => $camp, 'assetGroup' => $gid], $snap, [$missing], [], $now);
    $r = $exec($plan2);
    $check('asset_group: missing blob -> refusal', !$r['ok'] && $r['message'] === BlobOps::MISSING_MESSAGE, json_encode($r));
    // the account changed after the preview (fingerprint)
    $plan3 = AssetGroup::plan(['campaignId' => $camp, 'assetGroup' => $gid, 'path1' => 'zzzd2'], $snap, [], [], $now);
    $plan3['beforeFingerprint'] = 'old';
    $r = $exec($plan3);
    $check('asset_group: stale fingerprint -> refusal', !$r['ok'] && str_contains($r['message'], 'has changed'), json_encode($r));
} else {
    echo "SKIPPED asset group (PMax campaign $camp not found in the account)\n";
}

// 5. Offline conversions: classification, execution with a partial failure, upload log, no re-sending.
$action = ['id' => '1', 'name' => 'Action', 'type' => 'UPLOAD_CLICKS', 'status' => 'ENABLED'];
$mk = fn(string $id, array $o = []) => $o + ['id' => $id, 'createdAt' => gmdate('c', time() - 3600), 'clickType' => 'gclid', 'clickId' => 'CLK' . $id, 'clickTs' => gmdate('c', time() - 7200), 'adUserData' => 'granted'];
$suffix = bin2hex(random_bytes(3));
$leads = [$mk("a$suffix"), $mk("b$suffix"), $mk("c$suffix", ['adUserData' => 'unknown']), $mk("d$suffix", ['clickType' => 'gbraid']), $mk("e$suffix", ['clickTs' => gmdate('c', time() - 100 * 86400), 'createdAt' => gmdate('c', time() - 99 * 86400)])];
$c = Offline::classify($leads, [], $now);
$check('offline: classification', count($c['eligible']) === 2 && $c['skipped']['noConsent'] === 1 && $c['skipped']['noClickId'] === 1 && $c['skipped']['tooOld'] === 1, json_encode($c['skipped']));
$check('offline: Google time format', (bool) preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\+0[12]:00$/', $c['eligible'][0]['dateTime']), $c['eligible'][0]['dateTime']);
$check('offline: action pick', isset(Offline::pickUploadAction([$action], null)['action']) && isset(Offline::pickUploadAction([['type' => 'WEBPAGE'] + $action], '1')['error']));
$plan = Offline::plan($action, $leads, [], $now);
$check('offline: no click identifiers in the preview', !str_contains(json_encode($plan['diff']), 'CLK'));
OfflineRun::$senderForTests = fn(array $convs, bool $vo) => ['results' => [[], []], 'partialFailureError' => ['details' => [['errors' => [
    ['errorCode' => ['conversionUploadError' => 'TOO_RECENT_CONVERSION_ACTION'], 'location' => ['fieldPathElements' => [['fieldName' => 'conversions', 'index' => 1]]]]]]]]];
$r = $exec($plan);
$check('offline: executed with a partial failure', $r['ok'] && str_contains($r['message'], 'Sent 1 of 2') && str_contains($r['message'], '1 rejected'), json_encode($r));
$sentIds = OfflineLog::sentIds();
$check('offline: log (ok stored, error left for retry)', isset($sentIds["a$suffix"]) && !isset($sentIds["b$suffix"]), json_encode(array_keys($sentIds)));
$plan2 = Offline::plan($action, $leads, [], $now); // deliberately without passing the log
$r = $exec($plan2);
$check('offline: re-sending the same orderId rejected', !$r['ok'] && str_contains($r['message'], 'already been sent'), json_encode($r));
OfflineRun::$senderForTests = null;

// 6. GA4: execution on a fake client, fingerprint after, undo.
$fake = new class () {
    public array $events = [['name' => 'properties/1/keyEvents/1', 'eventName' => 'generate_lead', 'countingMethod' => 'ONCE_PER_EVENT', 'deletable' => true]];
    public function listKeyEvents(): array
    {
        return $this->events;
    }
    public function createKeyEvent(string $n, string $m): array
    {
        $k = ['name' => 'properties/1/keyEvents/' . (count($this->events) + 10), 'eventName' => $n, 'countingMethod' => $m, 'deletable' => true]; $this->events[] = $k; return $k;
    }
    public function deleteKeyEvent(string $name): void
    {
        $this->events = array_values(array_filter($this->events, fn($e) => $e['name'] !== $name));
    }
};
Ga4Run::$adminForTests = $fake;
$plan = Ga4KeyEventsPlan::plan(['mark' => ['sign_up', 'sign_up'], 'unmark' => ['generate_lead']], $fake->events, [], $now);
$check('ga4: duplicate in mark = error', !$plan['validation']['ok']);
$plan = Ga4KeyEventsPlan::plan(['mark' => ['sign_up'], 'unmark' => ['generate_lead']], $fake->events, ['sign_up' => true], $now);
$check('ga4: plan valid, removals first', $plan['validation']['ok'] && isset($plan['steps'][0]['operations'][0]['ga4KeyEventOperation']['delete']), json_encode($plan['steps']));
$r = $exec($plan);
$names = array_column($fake->events, 'eventName');
$check('ga4: executed (removed and added)', $r['ok'] && $names === ['sign_up'], json_encode([$r, $names]));
$done = PlanStore::get($plan['id']);
$check('ga4: afterFingerprint and executedAt stored', !empty($done['afterFingerprint']) && !empty($done['executedAt']));
$u = Ga4KeyEventsPlan::undoPlan($done, $fake->events, Clock::now());
$check('ga4: undo plan (create generate_lead + delete sign_up)', isset($u['plan']) && count($u['plan']['steps'][0]['operations']) === 2, json_encode($u));
$changed = Ga4KeyEventsPlan::undoPlan($done, [['eventName' => 'x', 'countingMethod' => 'ONCE_PER_EVENT']], Clock::now());
$check('ga4: undo refuses after the list changed', !empty($changed['refused']) && $changed['message'] === Undo::ACCOUNT_CHANGED, json_encode($changed));
$stale = Ga4KeyEventsPlan::plan(['mark' => ['zzz_evt']], [], null, $now); // fingerprint of an empty list != the fake's list
$r = $exec($stale);
$check('ga4: stale list -> refusal', !$r['ok'] && str_contains($r['message'], 'changed since'), json_encode($r));
Ga4Run::$adminForTests = null;

// 7. Conversion action: input rules.
$check('conv: no mode with fields', ConversionActions::inputErrors(['name' => 'x']) !== []);
$check('conv: create requires type', ConversionActions::inputErrors(['mode' => 'create']) !== []);
$check('conv: update without id', ConversionActions::inputErrors(['mode' => 'update']) !== []);
$check('conv: valid create', ConversionActions::inputErrors(['mode' => 'create', 'type' => 'WEBPAGE']) === []);

Executor::$mutatorForTests = null;
foreach ($ids as $id) {
    Db::exec('DELETE FROM plans WHERE id = ?', [$id]);
    Db::exec('DELETE FROM offline_log WHERE plan_id = ?', [$id]);
}
echo $fail ? "\nFAILURES: $fail\n" : "\nAll OK\n";
exit($fail ? 1 : 0);
