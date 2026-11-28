<?php
// Offline tests of Lib\LogReader (the Logs tab): order, limit, filters, masking, day files, bad lines. Run: php tests/logs.php
declare(strict_types=1);
require __DIR__ . '/_env.php';

putenv('GADS_DATA_DIR=' . ($dataDir = make_data_dir()));
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Lib\LogReader;

$fail = 0;
$check = checker($fail);
$logDir = $dataDir . '/logs';
mkdir($logDir, 0770, true);

/** Writes JSONL lines (arrays are encoded, strings are written as they are) to logs/app-<day>.log. */
$write = function (string $day, array $lines) use ($logDir): void {
    $text = '';
    foreach ($lines as $line) {
        $text .= (is_array($line) ? json_encode($line) : $line) . "\n";
    }
    file_put_contents("$logDir/app-$day.log", $text);
};

$check('empty: no log directory content gives no rows', LogReader::recent('all') === []);

// ---- order and the limit, over two day files ----
$yesterday = [];
for ($i = 1; $i <= 80; $i++) {
    $yesterday[] = ['ts' => sprintf('2026-11-27T10:%02d:%02dZ', intdiv($i, 60), $i % 60), 'type' => 'web', 'event' => "old$i", 'ok' => true];
}
$today = [];
for ($i = 1; $i <= 60; $i++) {
    $today[] = ['ts' => sprintf('2026-11-28T10:%02d:%02dZ', intdiv($i, 60), $i % 60), 'type' => 'mcp_request', 'client' => 'c1', 'ip' => '203.0.113.5', 'ok' => true, 'method' => "new$i"];
}
$write('2026-11-27', $yesterday);
$write('2026-11-28', $today);
$rows = LogReader::recent('all');
$check('limit: at most 100 rows', count($rows) === 100, (string) count($rows));
$check('order: the newest event is first', str_contains($rows[0]['summary'], 'new60'), $rows[0]['summary']);
$check('order: today comes before yesterday, newest first inside a day', str_contains($rows[59]['summary'], 'new1') && str_contains($rows[60]['summary'], 'old80'));
$check('days: older days fill up the list', str_contains($rows[99]['summary'], 'old41'), $rows[99]['summary']);
$check('limit: a custom limit is honoured', count(LogReader::recent('all', 5)) === 5);
$check('columns: client and ip are separate fields', $rows[0]['client'] === 'c1' && $rows[0]['ip'] === '203.0.113.5' && $rows[0]['type'] === 'mcp_request');
$check('summary: own columns are not repeated', !str_contains($rows[0]['summary'], 'c1') && !str_contains($rows[0]['summary'], 'ip:'));

// ---- filters ----
$write('2026-11-28', [
    ['ts' => '2026-11-28T11:00:00Z', 'type' => 'admin_login', 'ok' => false, 'result' => 'unknown_email'],
    ['ts' => '2026-11-28T11:00:01Z', 'type' => 'admin_login', 'ok' => true],
    ['ts' => '2026-11-28T11:00:02Z', 'type' => 'token', 'ok' => false, 'result' => 'invalid_grant'],
    ['ts' => '2026-11-28T11:00:03Z', 'type' => 'mcp_tool', 'ok' => true, 'tool' => 'list_campaigns', 'client' => 'c1'],
    ['ts' => '2026-11-28T11:00:04Z', 'type' => 'error', 'class' => 'RuntimeException', 'message' => 'boom'],
    ['ts' => '2026-11-28T11:00:05Z', 'type' => 'google_login', 'ok' => true],
    ['ts' => '2026-11-28T11:00:06Z', 'type' => 'rate_limited', 'bucket' => 'mcp'],
    ['ts' => '2026-11-28T11:00:07Z', 'type' => 'web', 'event' => 'upload', 'ok' => true],
]);
$types = fn(string $f): array => array_column(LogReader::recent($f), 'type');
$check('filter connections: only MCP/OAuth/connection events', $types('connections') === ['mcp_tool', 'token']);
$check('filter logins: admin, link and Google logins', $types('logins') === ['google_login', 'admin_login', 'admin_login']);
$check('filter failed: ok=false, errors and rate limits', $types('failed') === ['rate_limited', 'error', 'token', 'admin_login']);
$check('filter failed: rows carry the failed flag', array_unique(array_column(LogReader::recent('failed'), 'failed')) === [true]);
$check('filter all: successful rows are not flagged', LogReader::recent('all')[0]['failed'] === false);
$check('filter: an unknown value means all', LogReader::normalizeFilter('nope') === 'all' && LogReader::normalizeFilter('failed') === 'failed');
$check('filter is applied before the limit', count(LogReader::recent('logins', 100)) === 3);

// ---- masking and robustness ----
$write('2026-11-28', [
    'this is not json',
    '{"broken": ',
    '"a string"',
    ['ts' => '2026-11-28T12:00:00Z', 'type' => 'web', 'refresh_token' => 'sekret-A', 'client_secret' => 'sekret-B', 'password' => 'sekret-C',
        'url' => 'https://x.test/plan?id=1&t=sekret-D&z=2', 'note' => 'see /login/verify?t=sekret-E', 'nested' => ['access_token' => 'sekret-F', 'fine' => 'visible'], 'code' => 'cb?code=sekret-G'],
    ['ts' => '2026-11-28T12:00:01Z', 'type' => 'web', 'event' => str_repeat('long ', 200)],
]);
$rows = LogReader::recent('all');
$check('robustness: lines that are not JSON objects are skipped', $rows[0]['ts'] === '2026-11-28T12:00:01Z' && $rows[1]['ts'] === '2026-11-28T12:00:00Z' && str_starts_with($rows[2]['ts'], '2026-11-27'));
$dump = json_encode($rows);
$check('masking: no secret value reaches the rows', !preg_match('/sekret-[A-G]/', (string) $dump), (string) $dump);
$check('masking: non-secret text stays visible', str_contains($dump, 'visible') && str_contains($dump, 'z=2'));
$check('summary: shortened to a bounded length', mb_strlen($rows[0]['summary']) <= 241, (string) mb_strlen($rows[0]['summary']));

// ---- big file: only the tail is needed ----
$big = [];
for ($i = 1; $i <= 20000; $i++) {
    $big[] = ['ts' => '2026-11-29T00:00:00Z', 'type' => 'web', 'event' => "big$i", 'ok' => true];
}
$write('2026-11-29', $big);
$rows = LogReader::recent('all');
$check('big file: newest line first, 100 rows', count($rows) === 100 && str_contains($rows[0]['summary'], 'big20000') && str_contains($rows[99]['summary'], 'big19901'));
$check('scan cap: a rare filter does not read the whole history', LogReader::recent('failed') === [] || count(LogReader::recent('failed')) < 100);
$check('files with other names are ignored', (function () use ($logDir): bool {
    file_put_contents("$logDir/php-error.log", "{\"type\":\"web\",\"event\":\"intruder\"}\n");
    return !str_contains((string) json_encode(LogReader::recent('all')), 'intruder');
})());

remove_dir($dataDir);
exit($fail === 0 ? 0 : 1);
