<?php
// LEGACY NOTE: this script compares outputs 1:1 with the old Node server, which stays Polish. After the English translation of the
// PHP app, text differences are EXPECTED and the comparison will not pass; it is kept only as a historical regression harness.
// Comparison of the Google Ads MCP server: Node (reference) vs PHP (dev, local, no HTTP).
// Read-only tools ONLY (allow-list below) — never prepare_*/approve_plan/ui_event/upload_link on Node.
// Configuration via the environment (NO secrets in the repo):
//   GADS_NODE_URL        (REQUIRED: the full URL of the Node server's /mcp endpoint)
//   GADS_CLIENT_ID, GADS_CLIENT_SECRET  (OAuth client `claude-code`)
//   GADS_REPORT          (path of the .md report, default compare-results.md in the temp directory)
//   GADS_DUMP            (optional: a directory; for differences it saves the full results case-N.node.json / case-N.php.json)
//   GADS_ONLY            (optional: part of a case name to run)
// Usage: php tests/compare.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
} // CLI-only script, never over the web

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Lib\Mcp;
use App\Lib\McpServer;

// Like the claude-code client from Node: without approve_plan/ui_event (only clients from app_clients get those).
Mcp::$clientId = 'claude-code';

const READ_ONLY = ['account_overview', 'list_campaigns', 'campaign_details', 'campaign_health_check', 'change_history',
    'search_terms', 'keyword_ideas', 'list_conversion_actions', 'get_conversion_tag', 'pmax_report', 'ads_report', 'run_gaql',
    'ga4_report', 'ga4_key_events', 'ga4_data_streams', 'ga4_ads_links', 'server_events', 'lp2_leads', 'verify_lp2',
    'recent_uploads', 'show_plan'];
// Server-dependent state: only the format is compared (first line + structure), not the data.
const FORMAT_ONLY = ['server_events', 'recent_uploads', 'show_plan'];

/** Justified differences (regex on the case label => reason); counted separately, not as a port error. */
const KNOWN = [
    '/^\d+ account_overview/' => 'intentional: no daily limit counter in PHP (opsToday 0)',
    '/^\d+ (recent_uploads|server_events)/' => 'server-dependent state (the data differs by nature); text format matches',
    '/change_history \{"source":"connector"/' => 'connector entries depend on the server; format matches',
    '/campaign_details .*ALL_TIME/' => 'live metric drift between calls (the campaign is collecting traffic)',
    '/verify_lp2/' => 'Node bug in fold(): map[] is indexed by code points but used as a UTF-16 index — after an emoji (📍) hits/element/snippet are shifted by one character; PHP counts correctly',
];

/** Node MCP client (Streamable HTTP) with an OAuth token kept only in memory. */
final class NodeClient
{
    private string $token = '';
    private string $session = '';
    private int $id = 1;

    public function __construct(private string $url, private string $cid, private string $secret)
    {
    }

    private function http(string $method, string $url, array $headers = [], ?string $body = null, bool $follow = false): array
    {
        $ch = curl_init($url);
        $hdr = [];
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_TIMEOUT => 120,
            CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hdr) {
                if (str_contains($l, ':')) {
                    [$k, $v] = explode(':', $l, 2); $hdr[strtolower(trim($k))] = trim($v);
                }
                return strlen($l);
            }]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, (string) $out, $hdr];
    }

    /** authorization_code + PKCE S256 flow (authorize returns the code without a login). */
    public function login(): void
    {
        $base = preg_replace('#/mcp$#', '', $this->url);
        $verifier = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $redirect = 'http://127.0.0.1:8765/callback';
        $q = http_build_query(['response_type' => 'code', 'client_id' => $this->cid, 'redirect_uri' => $redirect,
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'x', 'resource' => $this->url]);
        [, , $h] = $this->http('GET', "$base/authorize?$q");
        parse_str((string) parse_url($h['location'] ?? '', PHP_URL_QUERY), $loc);
        if (empty($loc['code'])) {
            throw new RuntimeException('authorize without a code: ' . ($h['location'] ?? '?'));
        }
        [$c, $r] = $this->http('POST', "$base/token", ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'authorization_code', 'code' => $loc['code'], 'redirect_uri' => $redirect,
            'client_id' => $this->cid, 'client_secret' => $this->secret, 'code_verifier' => $verifier]));
        $j = json_decode($r, true);
        if (empty($j['access_token'])) {
            throw new RuntimeException("token HTTP $c");
        }
        $this->token = $j['access_token'];
        $this->rpc('initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [],
            'clientInfo' => ['name' => 'compare', 'version' => '1']]);
        $this->http('POST', $this->url, $this->headers(), json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    }

    private function headers(): array
    {
        $h = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'Authorization: Bearer ' . $this->token];
        if ($this->session !== '') {
            $h[] = 'Mcp-Session-Id: ' . $this->session;
        }
        return $h;
    }

    /** JSON-RPC call; the response may be JSON or an SSE stream. */
    public function rpc(string $method, array $params): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => $this->id++, 'method' => $method, 'params' => (object) $params]);
        [$code, $raw, $h] = $this->http('POST', $this->url, $this->headers(), $body);
        if (!empty($h['mcp-session-id'])) {
            $this->session = $h['mcp-session-id'];
        }
        $j = json_decode($raw, true);
        if ($j === null && preg_match_all('/^data: (.*)$/m', $raw, $m)) {
            $j = json_decode(end($m[1]), true);
        }
        if (!is_array($j)) {
            throw new RuntimeException("Node HTTP $code: " . substr($raw, 0, 200));
        }
        return $j;
    }

    public function call(string $tool, array $args): array
    {
        if (!in_array($tool, READ_ONLY, true)) {
            throw new LogicException("Forbidden tool on Node: $tool");
        }
        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => (object) $args]);
    }
}

function php_rpc(string $method, array $params): array
{
    $r = McpServer::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    return json_decode(json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE), true); // normalize types (stdClass -> arrays)
}

/** Sorts keys recursively (the key order of JSON objects does not matter). */
function ksort_deep(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    foreach ($v as $k => $x) {
        $v[$k] = ksort_deep($x);
    }
    if (array_is_list($v)) {
        return $v;
    }
    ksort($v);
    return $v;
}

/** Normalization of a tool schema/definition for comparison. */
function norm_tool(array $t): array
{
    unset($t['inputSchema']['$schema']);
    array_walk_recursive($t, fn() => null);
    $strip = function (&$x) use (&$strip) {
        if (is_array($x)) {
            unset($x['$schema']); foreach ($x as &$y) {
                $strip($y);
            }
        }
    };
    $strip($t);
    return ksort_deep($t);
}

/** Normalizacja tekstu: czasy, tokeny, czasy wykonania — rzeczy z natury zmienne. */
function norm_text(string $s): string
{
    $s = preg_replace('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?/', '<TS>', $s);
    $s = preg_replace('/\b\d+\s?ms\b/', '<MS>', $s);
    $s = preg_replace('#(https?://[^\s)"]*[?&](?:token|t|sig|key)=)[A-Za-z0-9._~-]+#', '$1<TOKEN>', $s);
    return rtrim($s);
}

/** Recursively sorts lists (by JSON) — a comparison independent of order when Google does not guarantee ORDER BY. */
function sort_lists(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    $v = array_map('sort_lists', $v);
    if (array_is_list($v)) {
        usort($v, fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
    }
    return $v;
}

function result_view(array $res, string $tool, bool $loose = false): array
{
    $r = $res['result'] ?? null;
    if ($r === null) {
        return ['rpcError' => $res['error'] ?? $res];
    }
    $text = implode("\n", array_map(fn($c) => norm_text((string) ($c['text'] ?? '')), $r['content'] ?? []));
    $out = ['isError' => (bool) ($r['isError'] ?? false), 'text' => $text];
    if (isset($r['structuredContent'])) {
        $out['structured'] = ksort_deep($r['structuredContent']);
    }
    if ($loose) { // order of rows/ties does not matter: text lines and lists are sorted
        $lines = explode("\n", $text); sort($lines);
        $out['text'] = implode("\n", $lines);
        if (isset($out['structured'])) {
            $out['structured'] = sort_lists($out['structured']);
        }
    }
    if (in_array($tool, FORMAT_ONLY, true)) { // format only: the first line and the structure keys
        $out = ['isError' => $out['isError'], 'firstLine' => strtok($text, "\n"),
            'structuredKeys' => isset($out['structured']) ? array_keys($out['structured']) : null];
    }
    return $out;
}

function short_diff(string $a, string $b, int $max = 14): string
{
    $x = explode("\n", $a); $y = explode("\n", $b);
    $out = [];
    for ($i = 0, $n = max(count($x), count($y)); $i < $n && count($out) < $max * 2; $i++) {
        if (($x[$i] ?? null) !== ($y[$i] ?? null)) {
            $out[] = 'L' . ($i + 1) . ' Node: ' . mb_substr($x[$i] ?? '∅', 0, 220);
            $out[] = 'L' . ($i + 1) . ' PHP : ' . mb_substr($y[$i] ?? '∅', 0, 220);
        }
    }
    return implode("\n", $out);
}

function pretty(array $v): string
{
    return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Set of cases; {S}/{P}/{X} = ID of the Search / PMax / paused Search campaign. */
function cases(string $S, string $P, string $X): array
{
    $c = [];
    $add = function (string $tool, array $args = []) use (&$c) {
        $c[] = [$tool, $args];
    };
    foreach (['account_overview', 'list_campaigns', 'list_conversion_actions', 'get_conversion_tag', 'ga4_key_events',
        'ga4_data_streams', 'ga4_ads_links', 'server_events', 'recent_uploads', 'lp2_leads', 'verify_lp2'] as $t) {
        $add($t);
    }
    $add('list_campaigns', ['range' => 'LAST_7_DAYS']);
    $add('list_campaigns', ['range' => 'ALL_TIME']);
    $add('list_campaigns', ['range' => ['from' => '2026-11-01', 'to' => '2026-11-15']]);
    $add('recent_uploads', ['hours' => 48]);
    $add('recent_uploads', ['today' => true]);
    $add('server_events', ['limit' => 5, 'format' => 'json']);
    $add('lp2_leads', ['from' => '2026-11-01', 'to' => '2026-11-20']);
    $add('verify_lp2', ['testParams' => false, 'fetchScripts' => false]);
    $add('show_plan', ['planId' => 'nieistniejacy']);
    foreach ([$S, $P, $X] as $id) {
        $add('campaign_health_check', ['campaign' => $id]);
    }
    foreach ([$S, $P] as $id) {
        $add('campaign_details', ['campaign' => $id]);
        $add('campaign_details', ['campaign' => $id, 'range' => 'LAST_7_DAYS', 'daily' => true]);
        $add('campaign_details', ['campaign' => $id, 'range' => 'ALL_TIME']);
    }
    $add('campaign_details', ['campaign' => $X, 'range' => ['from' => '2026-10-01', 'to' => '2026-10-31']]);
    $add('search_terms', ['campaign' => $S]);
    $add('search_terms', ['campaign' => $S, 'range' => 'LAST_90_DAYS', 'limit' => 20, 'ngrams' => 2]);
    $add('search_terms', ['campaign' => $S, 'noConversions' => true, 'onlyWithCost' => true, 'ngrams' => 1]);
    $add('search_terms', ['campaign' => $S, 'minImpressions' => 5, 'minCost' => 1]);
    $add('change_history', ['source' => 'account', 'days' => 14, 'limit' => 10]);
    $add('change_history', ['source' => 'account', 'campaign' => $S, 'limit' => 5]);
    $add('change_history', ['source' => 'connector', 'limit' => 5]);
    $add('ads_report', ['view' => 'clicks', 'limit' => 500]);
    foreach (['conversions', 'impression_share', 'recommendations', 'landing_pages', 'segments', 'policy', 'audiences',
        'budget_simulation', 'billing', 'account_hygiene', 'offline_uploads'] as $v) {
        $add('ads_report', ['view' => $v]);
    }
    $add('ads_report', ['view' => 'conversions', 'byDay' => true, 'range' => 'LAST_7_DAYS']);
    $add('ads_report', ['view' => 'conversions', 'campaign' => $S]);
    $add('ads_report', ['view' => 'impression_share', 'campaign' => $S, 'range' => 'LAST_90_DAYS']);
    $add('ads_report', ['view' => 'landing_pages', 'expanded' => true]);
    $add('ads_report', ['view' => 'clicks', 'campaign' => $P, 'range' => 'LAST_7_DAYS', 'limit' => 500]);
    $add('ads_report', ['view' => 'recommendations', 'includeDismissed' => true]);
    foreach (['assets', 'channels', 'placements', 'locations', 'hours', 'search_categories'] as $v) {
        $add('pmax_report', ['campaign' => $P, 'view' => $v]);
    }
    $add('pmax_report', ['campaign' => $P, 'view' => 'channels', 'range' => 'LAST_7_DAYS', 'compareTo' => 'previous_period']);
    $add('pmax_report', ['campaign' => $P, 'view' => 'locations', 'range' => 'LAST_90_DAYS', 'compareTo' => 'previous_year']);
    $add('pmax_report', ['campaign' => $P, 'view' => 'assets', 'range' => ['from' => '2026-11-01', 'to' => '2026-11-15'], 'limit' => 5]);
    $add('pmax_report', ['campaign' => $S, 'view' => 'channels']); // Search in pmax_report: an error message
    $add('run_gaql', ['query' => 'SELECT campaign.id, campaign.name, campaign.status FROM campaign ORDER BY campaign.id']);
    $add('run_gaql', ['query' => 'SELECT campaign.id, metrics.clicks, metrics.cost_micros FROM campaign WHERE segments.date DURING LAST_30_DAYS ORDER BY campaign.id', 'format' => 'json']);
    $add('run_gaql', ['query' => 'SELECT campaign.id FROM campaign ORDER BY campaign.id', 'maxRows' => 1, 'offset' => 1]);
    $add('run_gaql', ['query' => 'SELECT nieistniejace FROM campaign']);
    foreach (['LAST_7_DAYS', ['from' => '2026-11-01', 'to' => '2026-11-15']] as $r) {
        $add('ga4_report', ['range' => $r]);
    }
    $add('ga4_report', ['dimensions' => ['sessionSourceMedium'], 'metrics' => ['sessions', 'totalUsers'], 'pathMatch' => 'any']);
    $add('ga4_report', ['eventName' => 'generate_lead', 'dimensions' => ['date'], 'range' => 'LAST_7_DAYS']);
    $add('ga4_report', ['dimensions' => ['deviceCategory'], 'metrics' => ['engagementRate'], 'pathMatch' => 'contains', 'path' => '/']);
    $add('keyword_ideas', ['keywords' => ['kurs angielskiego']]);
    return $c;
}

// ---------------------------------------------------------------- uruchomienie
$cid = getenv('GADS_CLIENT_ID') ?: '';
$secret = getenv('GADS_CLIENT_SECRET') ?: '';
if ($cid === '' || $secret === '') {
    fwrite(STDERR, "Ustaw GADS_CLIENT_ID i GADS_CLIENT_SECRET\n"); exit(2);
}
$nodeUrl = getenv('GADS_NODE_URL') ?: '';
if ($nodeUrl === '') {
    fwrite(STDERR, "Set GADS_NODE_URL (the full URL of the reference Node server's /mcp endpoint)\n"); exit(2);
}
$node = new NodeClient($nodeUrl, $cid, $secret);
$node->login();
$only = getenv('GADS_ONLY') ?: '';
$report = getenv('GADS_REPORT') ?: sys_get_temp_dir() . '/compare-results.md';
if (!is_dir(dirname($report))) {
    $report = dirname(__DIR__) . '/compare-results.md';
}

$rows = []; $diffs = [];
$ok = 0; $bad = 0; $known = 0;

// (a) tools/list
$nl = $node->rpc('tools/list', [])['result']['tools'] ?? [];
$pl = php_rpc('tools/list', [])['result']['tools'] ?? [];
$nm = array_column($nl, null, 'name'); $pm = array_column($pl, null, 'name');
$names = array_unique(array_merge(array_keys($nm), array_keys($pm)));
sort($names);
foreach ($names as $n) {
    if (!isset($nm[$n]) || !isset($pm[$n])) {
        $rows[] = ["list:$n", 'DIFFERENCE']; $diffs["list:$n"] = isset($nm[$n]) ? 'missing in PHP' : 'missing in Node'; $bad++; continue;
    }
    $a = pretty(norm_tool($nm[$n])); $b = pretty(norm_tool($pm[$n]));
    if ($a === $b) {
        $ok++; $rows[] = ["list:$n", 'OK'];
    } else {
        $bad++; $rows[] = ["list:$n", 'DIFFERENCE']; $diffs["list:$n"] = short_diff($a, $b);
    }
}

// (b) calls. Campaign IDs from list_campaigns (PHP; the same account on both).
$camps = php_rpc('tools/call', ['name' => 'list_campaigns', 'arguments' => []])['result']['structuredContent']['campaigns'] ?? [];
$S = $P = $X = '';
foreach ($camps as $k) {
    if ($k['type'] === 'PERFORMANCE_MAX' && $P === '') {
        $P = (string) $k['id'];
    } elseif ($k['type'] === 'SEARCH' && $k['status'] === 'ENABLED' && $S === '') {
        $S = (string) $k['id'];
    } elseif ($k['type'] === 'SEARCH' && $X === '') {
        $X = (string) $k['id'];
    }
}
foreach (cases($S, $P, $X) as $i => [$tool, $args]) {
    $label = sprintf('%02d %s %s', $i + 1, $tool, json_encode($args, JSON_UNESCAPED_UNICODE));
    if ($only !== '' && !str_contains($label, $only)) {
        continue;
    }
    unset($ra, $rb);
    try {
        $ra = $node->call($tool, $args);
        $rb = php_rpc('tools/call', ['name' => $tool, 'arguments' => $args]);
        $a = result_view($ra, $tool);
        $b = result_view($rb, $tool);
    } catch (Throwable $e) {
        $a = ['err' => $e->getMessage()]; $b = ['err' => 'exception'];
    }
    $sa = pretty($a); $sb = pretty($b);
    if ($tool === 'keyword_ideas' && ($a['isError'] ?? false) && ($b['isError'] ?? false)) {
        $ok++; $rows[] = [$label, 'OK (Explorer error on both)']; continue;
    }
    if ($sa === $sb) {
        $ok++; $rows[] = [$label, 'OK']; continue;
    }
    // GAQL queries without ORDER BY: Google does not guarantee the order (Node does not impose it either) — a second attempt ignoring order.
    if (isset($ra, $rb)) {
        $la = pretty(result_view($ra, $tool, true)); $lb = pretty(result_view($rb, $tool, true));
        if ($la === $lb) {
            $ok++; $rows[] = [$label, 'OK (tie order / no ORDER BY)']; continue;
        }
    }
    $diffs[$label] = short_diff($sa, $sb);
    if (($dump = getenv('GADS_DUMP')) && is_dir($dump)) {
        file_put_contents("$dump/case-" . ($i + 1) . '.node.json', $sa);
        file_put_contents("$dump/case-" . ($i + 1) . '.php.json', $sb);
    }
    foreach (KNOWN as $re => $why) {
        if (preg_match($re, $label)) {
            $known++; $rows[] = [$label, "JUSTIFIED DIFFERENCE: $why"]; continue 2;
        }
    }
    $bad++; $rows[] = [$label, 'DIFFERENCE'];
    echo "DIFFERENCE: $label\n";
}

$md = "# Node vs PHP comparison (dev)\n\nDate: " . date('Y-m-d H:i') . " | OK: $ok | JUSTIFIED DIFFERENCE: $known | DIFFERENCE: $bad\n\n| Case | Result |\n|---|---|\n";
foreach ($rows as [$l, $r]) {
    $md .= '| ' . str_replace('|', '\\|', $l) . " | $r |\n";
}
$md .= "\n## Differences (shortened diff)\n";
foreach ($diffs as $l => $d) {
    $md .= "\n### $l\n```\n$d\n```\n";
}
file_put_contents($report, $md);
echo "OK: $ok, justified: $known, DIFFERENCE: $bad. Report: $report\n";
exit($bad > 0 ? 1 : 0);
