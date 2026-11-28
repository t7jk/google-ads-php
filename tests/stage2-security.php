<?php
// Offline regression tests of the stage-2 hardening: link tokens, plan approval, client scope and ownership, token expiry,
// refresh-token reuse, login throttling. A THROWAWAY data directory is used (GADS_DATA_DIR): nothing is written to the dev
// database and nothing reaches Google. Controllers run in child processes (tests/_child.php).
// Run: php tests/stage2-security.php
declare(strict_types=1);
require __DIR__ . '/_env.php';

putenv('GADS_DATA_DIR=' . ($dataDir = make_data_dir()));
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Auth\{LinkToken, LoginLinks};
use App\Controllers\{AuthController, McpController, OAuthController, PlansController};
use App\Lib\{Clients, Db, Mcp, McpServer};
use App\Media\UploadLog;
use App\Plans\{Plan, PlanLink, PlanStore};

$fail = 0;
$check = checker($fail);
$now = (int) round(microtime(true) * 1000);
$rpc = function (string $method, array $params = []): array {
    return McpServer::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
};
$toolNames = fn(array $response): array => array_column($response['result']['tools'], 'name');
$callText = fn(array $response): string => (string) ($response['result']['content'][0]['text'] ?? '');
$newPlan = function (): array {
    return array_merge(Plan::base(), [
        'kind' => 'new_campaign', 'summary' => 'test', 'diff' => [], 'warnings' => [], 'validation' => ['ok' => true, 'errors' => []],
        'steps' => [['title' => 't', 'operations' => [['campaignBudgetOperation' => ['create' => ['resourceName' => 'customers/1/campaignBudgets/-1', 'amountMicros' => '1000000']]]]]],
        'beforeFingerprint' => 'x',
    ]);
};

// ---- A. Link tokens (audit 15) ----
$t = LinkToken::make('plan:abc', LinkToken::TTL_MS);
$check('link: new token has a key version', str_starts_with($t, 'v1.'));
$check('link: valid for its purpose', LinkToken::verify('plan:abc', $t));
$check('link: other purpose rejected', !LinkToken::verify('plan:xyz', $t) && !LinkToken::verify('upload', $t));
$check('link: tampered MAC rejected', !LinkToken::verify('plan:abc', substr($t, 0, -2) . 'xx'));
$check('link: expired rejected', !LinkToken::verify('plan:abc', $t, $now + LinkToken::TTL_MS + 5000));
$check('link: future key version rejected', !LinkToken::verify('plan:abc', 'v2' . substr($t, 2)));
$up = LinkToken::make(LinkToken::UPLOAD, LinkToken::UPLOAD_TTL_MS, 'claude-code-1a2b');
$check('upload link: carries the client', LinkToken::verify(LinkToken::UPLOAD, $up) && LinkToken::context(LinkToken::UPLOAD, $up) === 'claude-code-1a2b');
$check('upload link: context cannot be swapped', !LinkToken::verify(LinkToken::UPLOAD, str_replace('claude-code-1a2b', 'claude-code-ffff', $up)));
$check('upload link: lives 2 h, not 24 h', LinkToken::verify(LinkToken::UPLOAD, $up, $now + 3600_000) && !LinkToken::verify(LinkToken::UPLOAD, $up, $now + 3 * 3600_000));
$legacyMac = function (string $purpose, string $exp): string {
    $key = hash_hkdf('sha256', (string) base64_decode((string) config('secret_key'), true), 32, 'gads-link-token'); // the pre-0.3 key
    return rtrim(strtr(base64_encode(hash_hmac('sha256', "$purpose\n$exp", $key, true)), '+/', '-_'), '=');
};
$exp = (string) ($now + 3600_000);
$check('legacy token (old key, 2 parts) still accepted until it expires', LinkToken::verify('plan:abc', $exp . '.' . $legacyMac('plan:abc', $exp)));
$check('legacy token expired is rejected', !LinkToken::verify('plan:abc', $exp . '.' . $legacyMac('plan:abc', $exp), $now + 2 * 3600_000));
$farExp = (string) ($now + 5 * 86400_000);
$check('legacy token with an expiry beyond 24 h is rejected', !LinkToken::verify('plan:abc', $farExp . '.' . $legacyMac('plan:abc', $farExp)));
$check('legacy token for another purpose is rejected', !LinkToken::verify('plan:other', $exp . '.' . $legacyMac('plan:abc', $exp)));
$check('PlanLink still works through LinkToken', PlanLink::valid(PlanLink::purpose('p1'), PlanLink::make(PlanLink::purpose('p1'))));

// ---- B. Login throttle and return path (audit 18, approval flow) ----
$results = [];
for ($i = 0; $i < 7; $i++) {
    $results[] = LoginLinks::allowAttempt('203.0.113.7');
}
$check('login: 5 attempts per IP allowed, then refused (every attempt counts)', $results === [true, true, true, true, true, false, false], json_encode($results));
$check('login: another IP is not affected', LoginLinks::allowAttempt('203.0.113.8'));
$goodNext = '/plan?id=' . str_repeat('a1', 16) . '&t=' . $t;
$check('login: plan page path accepted as return path', LoginLinks::validNextPath($goodNext) === $goodNext);
$badNexts = ['//evil.example/x', 'https://evil.example/', '/settings', '/plan?id=zz&t=1', "/plan?id=" . str_repeat('a1', 16) . "&t=x\r\nSet-Cookie: a=b"];
$check('login: other return paths rejected (no open redirect)', array_filter($badNexts, fn($p) => LoginLinks::validNextPath($p) !== null) === []);

// ---- C. Static token expiry (audit 10) ----
$created = Clients::create('static', 'claude-code-token');
$row = Db::one('SELECT * FROM oauth_tokens WHERE client_id = ?', [$created['client_id']]);
$days = (strtotime($row['access_expires_at']) - time()) / 86400;
$check('static token: expires in ~180 days by default', $days > 179 && $days < 181, (string) $days);
Db::exec('UPDATE oauth_tokens SET access_expires_at = ? WHERE id = ?', [now_iso(-10), $row['id']]);
$check('static token: renewal extends an expired token', Clients::renewStaticToken((int) $row['id'])
    && Db::one('SELECT access_expires_at FROM oauth_tokens WHERE id = ?', [$row['id']])['access_expires_at'] > now_iso(170 * 86400));
$oauthClient = Clients::create('oauth', 'claude-code');
Db::exec("INSERT INTO oauth_tokens (client_id, access_hash, refresh_hash, access_expires_at, refresh_expires_at, created_at) VALUES (?, 'ah', 'rh', ?, ?, ?)", [$oauthClient['client_id'], now_iso(3600), now_iso(86400), now_iso()]);
$oauthRow = Db::one('SELECT id FROM oauth_tokens WHERE client_id = ?', [$oauthClient['client_id']]);
$check('static token: an OAuth connection cannot be "renewed"', !Clients::renewStaticToken((int) $oauthRow['id']));
Db::exec('UPDATE oauth_tokens SET revoked_at = ? WHERE id = ?', [now_iso(), $row['id']]);
$check('static token: a revoked token stays revoked after renewal', Clients::renewStaticToken((int) $row['id']) === false);
$check('connections: unused for 30+ days is flagged', Clients::isUnused(['last_used_at' => now_iso(-40 * 86400), 'created_at' => now_iso(-50 * 86400)])
    && !Clients::isUnused(['last_used_at' => null, 'created_at' => now_iso(-2 * 86400)]));

// ---- D. Scope per client (audit 11) ----
$readClient = Clients::create('reader', 'claude-code', Clients::SCOPE_READ);
$check('scope: stored per client, default is write (as before)', Clients::find($readClient['client_id'])['scope'] === 'ads.read' && Clients::find($oauthClient['client_id'])['scope'] === 'ads.write');
Mcp::$clientId = $readClient['client_id'];
Mcp::$scope = Clients::SCOPE_READ;
$names = $toolNames($rpc('tools/list'));
$check('scope read: tools/list has reports but no prepare_* tool', in_array('run_gaql', $names, true) && in_array('show_plan', $names, true) && array_filter($names, fn($n) => str_starts_with($n, 'prepare_')) === []);
$res = $rpc('tools/call', ['name' => 'prepare_campaign_pause', 'arguments' => ['campaign' => '1']]);
$check('scope read: calling prepare_campaign_pause by name is refused', ($res['result']['isError'] ?? false) === true && str_contains($callText($res), 'read-only'), $callText($res));
$check('scope read: a read-only tool still works (upload_link)', str_contains($callText($rpc('tools/call', ['name' => 'upload_link', 'arguments' => []])), '/upload?t='));
Mcp::$clientId = $oauthClient['client_id'];
Mcp::$scope = Clients::SCOPE_WRITE;
$check('scope write: prepare_* tools are listed', in_array('prepare_campaign_pause', $toolNames($rpc('tools/list')), true));

// ---- E. approve_plan is off by default (audit 1) ----
Mcp::$clientId = 'claude-ai-aaaa1111';
$check('approve_plan: not listed, even for a claude-ai client', !in_array('approve_plan', $toolNames($rpc('tools/list')), true));
$p = $newPlan();
PlanStore::save($p);
$res = $rpc('tools/call', ['name' => 'approve_plan', 'arguments' => ['planId' => $p['id'], 'planHash' => Plan::hash(PlanStore::get($p['id']))]]);
$check('approve_plan: calling it by name is refused and the plan stays pending', ($res['result']['isError'] ?? false) === true && PlanStore::get($p['id'])['status'] === 'pending', $callText($res));
$check('show_plan: the hash is not given to the model', !isset($rpc('tools/call', ['name' => 'show_plan', 'arguments' => ['planId' => $p['id']]])['result']['structuredContent']['hash']));

// ---- F. Ownership of plans, uploads, events (audit 11) ----
Mcp::$clientId = 'claude-code-aaaa';
$planA = $newPlan();
PlanStore::save($planA);
Mcp::$clientId = null;
$planLegacy = $newPlan();
PlanStore::save($planLegacy);
$check('plan: stores the creating client', Db::one('SELECT client_id FROM plans WHERE id = ?', [$planA['id']])['client_id'] === 'claude-code-aaaa');
$check('plan: no owner outside /mcp (legacy rows look the same)', Db::one('SELECT client_id FROM plans WHERE id = ?', [$planLegacy['id']])['client_id'] === null);
$show = fn(string $client, string $id): string => (function () use ($client, $id, $rpc, $callText): string {
    Mcp::$clientId = $client;
    return $callText($rpc('tools/call', ['name' => 'show_plan', 'arguments' => ['planId' => $id]]));
})();
$check('show_plan: the owner sees its plan', str_contains($show('claude-code-aaaa', $planA['id']), 'test'));
$check('show_plan: another client gets "No such plan"', $show('claude-code-bbbb', $planA['id']) === 'No such plan');
$check('show_plan: a plan without an owner (legacy) stays visible to everybody', str_contains($show('claude-code-bbbb', $planLegacy['id']), 'test'));
$check('PlanStore::recent: filtered per client, all for the admin',
    in_array($planA['id'], array_column(PlanStore::recent('claude-code-aaaa'), 'id'), true)
    && !in_array($planA['id'], array_column(PlanStore::recent('claude-code-bbbb'), 'id'), true)
    && in_array($planLegacy['id'], array_column(PlanStore::recent('claude-code-bbbb'), 'id'), true)
    && in_array($planA['id'], array_column(PlanStore::recent(null), 'id'), true));
UploadLog::addYoutube('batch1', 'abcdefghijk', 'Video of A', null, 10, 'claude-code-aaaa');
UploadLog::addYoutube('batch2', 'lmnopqrstuv', 'Legacy video', null, 10, null);
$ids = fn(?string $client): array => array_column(UploadLog::recent(24, $client), 'youtubeId');
$check('uploads: owner sees its own and legacy, another client only legacy, admin all',
    $ids('claude-code-aaaa') === ['abcdefghijk', 'lmnopqrstuv'] && $ids('claude-code-bbbb') === ['lmnopqrstuv'] && count($ids(null)) === 2, json_encode([$ids('claude-code-aaaa'), $ids('claude-code-bbbb')]));
Mcp::$clientId = 'claude-code-aaaa';
Db::exec('INSERT INTO events (ts, type, data_json) VALUES (?, ?, ?)', [now_iso(), 'mcp_tool', json_encode(['tool' => 'x', 'client' => 'claude-code-aaaa', 'marker' => 'event-of-A'])]);
Db::exec('INSERT INTO events (ts, type, data_json) VALUES (?, ?, ?)', [now_iso(), 'plan', json_encode(['planId' => $planA['id'], 'marker' => 'plan-event-of-A'])]);
Db::exec('INSERT INTO events (ts, type, data_json) VALUES (?, ?, ?)', [now_iso(), 'web', json_encode(['marker' => 'server-event'])]);
$eventsFor = fn(string $client): string => (function () use ($client, $rpc, $callText): string {
    Mcp::$clientId = $client;
    return $callText($rpc('tools/call', ['name' => 'server_events', 'arguments' => ['since' => '1h', 'format' => 'json']]));
})();
$forA = $eventsFor('claude-code-aaaa');
$forB = $eventsFor('claude-code-bbbb');
$check('server_events: the owner sees its events', str_contains($forA, 'event-of-A') && str_contains($forA, 'plan-event-of-A'));
$check('server_events: another client does not see them (nor events of a plan it does not own)', !str_contains($forB, 'event-of-A') && !str_contains($forB, 'plan-event-of-A'));
$check('server_events: ownerless server events stay visible', str_contains($forB, 'server-event'));
Mcp::$clientId = null;

// ---- G. Plan approval needs an admin session (audit 1) ----
$plan = $newPlan();
PlanStore::save($plan);
$hash = Plan::hash(PlanStore::get($plan['id']));
$token = PlanLink::make(PlanLink::purpose($plan['id']));
$approve = fn(array $over = [], bool $admin = false): string => call_controller($dataDir, [
    'class' => PlansController::class, 'method' => 'approve', 'admin' => $admin, 'stub_mutator' => [1],
    'post' => $over + ['planId' => $plan['id'], 'csrf' => $token, 'planHash' => $hash],
]);
$out = $approve();
$check('approve: no admin session -> refused, plan not executed', str_contains($out, 'Login required') && PlanStore::get($plan['id'])['status'] === 'pending', $out);
$out = $approve(['csrf' => 'v1.1.bad'], true);
$check('approve: admin session but a bad link token -> refused', str_contains($out, 'Access denied') && PlanStore::get($plan['id'])['status'] === 'pending', $out);
$out = $approve(['planHash' => 'wrong'], true);
$check('approve: admin session + token but a wrong plan hash -> not executed', PlanStore::get($plan['id'])['status'] === 'pending', $out);
$out = $approve([], true);
$check('approve: admin session + token + hash -> executed', PlanStore::get($plan['id'])['status'] === 'done', $out);
$plan2 = $newPlan();
PlanStore::save($plan2);
$token2 = PlanLink::make(PlanLink::purpose($plan2['id']));
$page = fn(bool $admin): string => call_controller($dataDir, [
    'class' => PlansController::class, 'method' => 'show', 'admin' => $admin, 'server' => ['REQUEST_METHOD' => 'GET'], 'get' => ['id' => $plan2['id'], 't' => $token2],
]);
$out = $page(false);
$check('plan page: without a session it shows a login form that returns here, not the Approve form', str_contains($out, 'name="email"') && str_contains($out, 'name="next"') && !str_contains($out, 'name="planHash"'));
$out = $page(true);
$check('plan page: with an admin session it shows the Approve form', str_contains($out, 'name="planHash"') && !str_contains($out, 'name="email"'));
$check('plan page: the link given to the model (approveUrl) is the plan page', str_starts_with(Plan::pageUrl($plan2['id'], $token2), 'https://example.test/plan?id=')
    && str_contains(call_controller($dataDir, ['class' => PlansController::class, 'method' => 'show', 'server' => ['REQUEST_METHOD' => 'GET'], 'get' => ['id' => $plan2['id'], 't' => 'v1.1.bad']]), 'Access denied'));

// ---- H. Login link returns to the plan page ----
$verifyLink = function (?string $next) use ($dataDir): array {
    LoginLinks::send(LoginLinks::LOGIN, 'admin@example.org', '203.0.113.9', false, $next);
    preg_match('/t=([0-9a-f]{64})/', (string) file_get_contents($dataDir . '/' . LoginLinks::FALLBACK_FILE), $m);
    $row = LoginLinks::peek($m[1] ?? '');
    $out = call_controller($dataDir, ['class' => AuthController::class, 'method' => 'verify', 'post' => ['t' => $m[1] ?? '']]);
    return [$row, Db::one('SELECT used_at FROM login_links WHERE token_hash = ?', [hash('sha256', $m[1] ?? '')])['used_at'] ?? null, $out];
};
[$row, $usedAt] = $verifyLink(LoginLinks::validNextPath(Plan::pagePath($plan2['id'], $token2)));
$check('login link: stores the plan page as the return path and logs in (link consumed)', ($row['next_path'] ?? '') === Plan::pagePath($plan2['id'], $token2) && $usedAt !== null);
$check('login link: returnPath leads back to the plan page', LoginLinks::returnPath($row) === Plan::pagePath($plan2['id'], $token2));
[$row] = $verifyLink(null);
$check('login link: without a return path -> home page; address change -> settings', LoginLinks::returnPath($row) === '/'
    && LoginLinks::returnPath(['purpose' => LoginLinks::EMAIL_CHANGE, 'next_path' => '/plan?id=x']) === '/settings');

// ---- I. Refresh token reuse (audit 19) ----
$cl = Clients::create('refresh-test', 'claude-code');
$old = bin2hex(random_bytes(16));
Db::exec('INSERT INTO oauth_tokens (client_id, access_hash, refresh_hash, access_expires_at, refresh_expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
    [$cl['client_id'], hash('sha256', 'acc'), hash('sha256', $old), now_iso(3600), now_iso(86400), now_iso()]);
$refresh = fn(string $token): array => (function () use ($dataDir, $cl, $token): array {
    $out = call_controller($dataDir, ['class' => OAuthController::class, 'method' => 'token',
        'post' => ['grant_type' => 'refresh_token', 'refresh_token' => $token, 'client_id' => $cl['client_id'], 'client_secret' => $cl['secret']]]);
    return json_decode(trim(explode("\n--HEADERS--", $out)[0]), true) ?: ['raw' => $out];
})();
$first = $refresh($old);
$check('refresh: a valid token rotates', isset($first['refresh_token']) && $first['refresh_token'] !== $old, json_encode($first));
$again = $refresh($old);
$check('refresh: replaying the old token right after rotation is a plain error (honest retry), connection stays', ($again['error'] ?? '') === 'invalid_grant'
    && Db::one('SELECT revoked_at FROM oauth_tokens WHERE client_id = ?', [$cl['client_id']])['revoked_at'] === null, json_encode($again));
Db::exec('UPDATE oauth_tokens SET refresh_rotated_at = ? WHERE client_id = ?', [now_iso(-120), $cl['client_id']]);
$replay = $refresh($old);
$check('refresh: replaying the old token later revokes the whole connection', ($replay['error'] ?? '') === 'invalid_grant'
    && Db::one('SELECT revoked_at FROM oauth_tokens WHERE client_id = ?', [$cl['client_id']])['revoked_at'] !== null);
$check('refresh: the newest token of that connection no longer works either', ($refresh($first['refresh_token'] ?? 'x')['error'] ?? '') === 'invalid_grant');

// ---- J. last_used_at is written at most once a minute (audit 19) ----
$static = Clients::create('touch', 'claude-code-token');
$tokenRow = Db::one('SELECT id FROM oauth_tokens WHERE client_id = ?', [$static['client_id']]);
$callMcp = fn() => call_controller($dataDir, ['class' => McpController::class, 'method' => 'handle', 'server' => ['REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer ' . $static['token']]]);
$lastUsed = fn(): ?string => Db::one('SELECT last_used_at FROM oauth_tokens WHERE id = ?', [$tokenRow['id']])['last_used_at'];
$callMcp();
$check('last_used_at: set by the first request', $lastUsed() !== null);
$recent = now_iso(-10);
Db::exec('UPDATE oauth_tokens SET last_used_at = ? WHERE id = ?', [$recent, $tokenRow['id']]);
$callMcp();
$check('last_used_at: not rewritten within a minute', $lastUsed() === $recent);
$stale = now_iso(-300);
Db::exec('UPDATE oauth_tokens SET last_used_at = ? WHERE id = ?', [$stale, $tokenRow['id']]);
$callMcp();
$check('last_used_at: refreshed when older than a minute', $lastUsed() > $stale);

remove_dir($dataDir);
echo $fail === 0 ? "All OK\n" : "FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
