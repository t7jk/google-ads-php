<?php
// Runs every offline test file, each in its own PHP process with its own throwaway data directory (GADS_DATA_DIR),
// prints a summary and exits non-zero when any file fails. Run: php tests/run.php [name-fragment ...]
// Convention: tests/<name>.php is picked up automatically; files starting with "_" are helpers, not tests.
// A test file prints one "OK   <name>" or "FAIL <name>" line per check and exits non-zero on failure.
declare(strict_types=1);
require __DIR__ . '/_env.php';

/** Files that need the network or the Google API (read-only, but not offline): run by hand, never by the runner or CI. */
const NETWORK_TESTS = ['compare.php', 'plans-d2.php'];

/** @return list<string> absolute paths of the offline test files, optionally filtered by name fragments */
function offline_tests(array $filters): array
{
    $files = glob(__DIR__ . '/*.php') ?: [];
    $files = array_filter($files, fn(string $f) => !str_starts_with(basename($f), '_')
        && basename($f) !== 'run.php' && !in_array(basename($f), NETWORK_TESTS, true));
    if ($filters) {
        $files = array_filter($files, fn(string $f) => array_filter($filters, fn(string $x) => str_contains(basename($f), $x)) !== []);
    }
    sort($files);
    return array_values($files);
}

/** Runs one file in a child process with a fresh data directory; returns [exit code, output]. The directory is always removed. */
function run_test_file(string $file): array
{
    $dataDir = make_data_dir();
    $process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__),
        ['GADS_DATA_DIR' => $dataDir, 'PATH' => (string) getenv('PATH')]);
    if (!is_resource($process)) {
        remove_dir($dataDir);
        return [1, "could not start PHP\n"];
    }
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    $code = proc_close($process);
    remove_dir($dataDir);
    return [$code, $output];
}

$files = offline_tests(array_slice($argv, 1));
if ($files === []) {
    fwrite(STDERR, "No test files matched.\n");
    exit(2);
}

$totalChecks = 0;
$failedChecks = 0;
$failedFiles = [];
foreach ($files as $file) {
    [$code, $output] = run_test_file($file);
    $checks = preg_match_all('/^(?:OK   |FAIL )/m', $output);
    $failures = preg_match_all('/^FAIL/m', $output);
    $passed = $code === 0 && $failures === 0 && $checks > 0; // a file that prints no checks proves nothing
    printf("%s %-24s %3d checks, %d failed\n", $passed ? 'PASS' : 'FAIL', basename($file), $checks, $failures);
    if (!$passed) {
        $failedFiles[] = basename($file);
        echo preg_replace('/^/m', '    ', rtrim($output)) . "\n"; // the whole output of a failing file, indented
    }
    $totalChecks += $checks;
    $failedChecks += $failures;
}

echo "\n" . ($failedFiles === [] ? 'All OK' : 'FAILED: ' . implode(', ', $failedFiles)) . " ($totalChecks checks in " . count($files) . " files)\n";
exit($failedFiles === [] ? 0 : 1);
