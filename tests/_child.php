<?php
// Child process of tests/_env.php call_controller(): runs ONE controller method with the given request and prints the output.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}

$spec = json_decode((string) ($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$_SERVER['REQUEST_METHOD'] = $spec['server']['REQUEST_METHOD'] ?? 'POST';
$_SERVER['SCRIPT_NAME'] = '/index.php';
foreach (($spec['server'] ?? []) as $k => $v) {
    $_SERVER[$k] = $v;
}
$_POST = $spec['post'] ?? [];
$_GET = $spec['get'] ?? [];
require dirname(__DIR__) . '/app/bootstrap.php';

register_shutdown_function(function (): void {
    echo "\n--HEADERS--\n" . implode("\n", array_merge(['Status: ' . (http_response_code() ?: 200)], headers_list())) . "\n";
});
if (!empty($spec['admin'])) {
    login_admin();
}
foreach (($spec['stub_mutator'] ?? []) as $_) {
    \App\Plans\Executor::$mutatorForTests = fn(array $ops, bool $validateOnly): array => ['results' => [['campaignBudgetResult' => ['resourceName' => 'customers/1/campaignBudgets/9']]]];
}
if (!empty($spec['dispatch'])) { // the whole front-controller path: route table + CSRF check
    if (!empty($spec['csrf'])) {
        $_POST['_csrf'] = csrf_token(); // a valid token for this process's own session
    }
    \App\Lib\Router::dispatch(require APP_PATH . '/routes.php');
    exit;
}
$controller = new $spec['class']();
$controller->{$spec['method']}();
