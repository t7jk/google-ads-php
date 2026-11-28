<?php
// CLI for testing MCP tools without HTTP and OAuth: calls the handler through McpServer (the same path as /mcp).
// Usage: php tools/call.php <tool_name> '<arguments json>'   |   php tools/call.php --list
// Prints the result text; exit code 1 on isError. For dev: the config has auto_execute=false.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
} // CLI-only script, never over the web

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Lib\McpServer;

$name = $argv[1] ?? '';
if ($name === '' || $name === '--list') {
    $res = McpServer::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
    foreach ($res['result']['tools'] ?? [] as $t) {
        echo $t['name'], "\n";
    }
    exit(0);
}

$args = json_decode($argv[2] ?? '{}', true);
if (!is_array($args)) {
    fwrite(STDERR, "Arguments must be a JSON object\n");
    exit(2);
}

$res = McpServer::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
    'params' => ['name' => $name, 'arguments' => (object) $args]]);
if (isset($res['error'])) {
    fwrite(STDERR, json_encode($res['error'], JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
$result = $res['result'];
foreach ($result['content'] ?? [] as $c) {
    echo $c['text'] ?? '', "\n";
}
if (in_array('--structured', $argv, true) && isset($result['structuredContent'])) {
    echo json_encode($result['structuredContent'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
}
exit(!empty($result['isError']) ? 1 : 0);
