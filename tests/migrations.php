<?php
// Tests of the schema migrations (Lib\Migrator, app/migrations/*.sql) on THROWAWAY databases only.
// Run: php tests/migrations.php
declare(strict_types=1);
require __DIR__ . '/_env.php';

putenv('GADS_DATA_DIR=' . ($dataDir = make_data_dir()));
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Lib\Migrator;

$fail = 0;
$check = checker($fail);
$migrations = APP_PATH . '/migrations';
$open = function (string $file): PDO {
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
};
$versions = fn(PDO $pdo): array => array_map('intval', array_column($pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(), 'version'));
$expectedVersions = array_map(fn($f) => (int) substr(basename($f), 0, 3), glob($migrations . '/*.sql'));
sort($expectedVersions);

// 1. A clean database: every migration in order, no backup (nothing to protect), a second run does nothing.
$pdo = $open($dataDir . '/clean.sqlite');
Migrator::run($pdo, $migrations, $dataDir . '/backups-clean');
$check('clean: all versions applied in order', $versions($pdo) === $expectedVersions, json_encode($versions($pdo)));
$check('clean: no backup of an empty database', !is_dir($dataDir . '/backups-clean'));
$check('clean: integrity ok', $pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok');
$check('clean: no foreign key violations', $pdo->query('PRAGMA foreign_key_check')->fetchAll() === []);
$before = $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
$stamp = $pdo->query('SELECT MAX(applied_at) FROM schema_migrations')->fetchColumn();
Migrator::run($pdo, $migrations, $dataDir . '/backups-clean');
$check('clean: second run changes nothing', $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === $before && !is_dir($dataDir . '/backups-clean'));
foreach (['mcp_clients' => 'scope', 'plans' => 'client_id', 'uploads' => 'client_id', 'oauth_tokens' => 'prev_refresh_hash', 'login_links' => 'next_path'] as $table => $column) {
    $columns = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    $check("clean: $table.$column exists", in_array($column, $columns, true));
}

// 2. Constraints are enforced after migration 002.
$ins = fn(string $sql, array $p = []) => $pdo->prepare($sql)->execute($p);
$ins("INSERT INTO mcp_clients (client_id, name, secret_hash, redirect_uris, created_at) VALUES ('c1', 'n', 'h', 'loopback', 'now')");
$throws = function (callable $f): bool {
    try {
        $f(); return false;
    } catch (PDOException) {
        return true;
    }
};
$check('fk: token of an unknown client rejected', $throws(fn() => $ins("INSERT INTO oauth_tokens (client_id, access_hash, refresh_hash, access_expires_at, refresh_expires_at, created_at) VALUES ('nope', 'a', 'r', 'x', 'x', 'x')")));
$check('check: invalid plan status rejected', $throws(fn() => $ins("INSERT INTO plans (id, tool, status, payload_json, created_at, expires_at) VALUES ('p', 't', 'weird', '{}', 'x', 'x')")));
$check('check: invalid upload kind rejected', $throws(fn() => $ins("INSERT INTO uploads (kind, path_or_url, created_at) VALUES ('video', 'u', 'x')")));
$ins("INSERT INTO offline_log (order_id, ts, result) VALUES ('o1', 'x', 'ok')");
$check('unique: a second ok for the same order rejected', $throws(fn() => $ins("INSERT INTO offline_log (order_id, ts, result) VALUES ('o1', 'x', 'ok')")));
$ins("INSERT INTO offline_log (order_id, ts, result) VALUES ('o1', 'x', 'error')");
$ins("INSERT INTO offline_log (order_id, ts, result) VALUES ('o2', 'x', 'error')");
$ins("INSERT INTO offline_log (order_id, ts, result) VALUES ('o2', 'x', 'error')");
$check('unique: retries after errors stay possible', true);
$ins("INSERT INTO oauth_tokens (client_id, access_hash, refresh_hash, access_expires_at, refresh_expires_at, created_at) VALUES ('c1', 'a', 'r', 'x', 'x', 'x')");
$ins("DELETE FROM mcp_clients WHERE client_id = 'c1'");
$check('fk: deleting a client cascades to its tokens', (int) $pdo->query('SELECT COUNT(*) FROM oauth_tokens')->fetchColumn() === 0);

// 3. An existing database as the application had it before migrations (baseline schema + data, no schema_migrations).
$legacyFile = $dataDir . '/legacy.sqlite';
$legacy = $open($legacyFile);
$legacy->exec((string) file_get_contents($migrations . '/001_baseline.sql'));
$legacy->exec("INSERT INTO mcp_clients (client_id, name, secret_hash, redirect_uris, created_at) VALUES ('keep', 'Keep', 'h', 'bearer', 't')");
$legacy->exec("INSERT INTO oauth_tokens (client_id, access_hash, refresh_hash, access_expires_at, refresh_expires_at, created_at) VALUES ('keep', 'a1', 'r1', '2099-12-31T23:59:59Z', '2099-12-31T23:59:59Z', 't')");
$legacy->exec("INSERT INTO oauth_tokens (client_id, access_hash, refresh_hash, access_expires_at, refresh_expires_at, created_at) VALUES ('ghost', 'a2', 'r2', '2099-12-31T23:59:59Z', '2099-12-31T23:59:59Z', 't')"); // orphan
$legacy->exec("INSERT INTO plans (id, tool, status, payload_json, created_at, expires_at) VALUES ('plan-a', 'pause', 'done', '{\"x\":1}', 't', 't')");
$legacy->exec("INSERT INTO snapshots (plan_id, data_json, created_at) VALUES ('plan-a', '{}', 't')");
$legacy->exec("INSERT INTO offline_log (order_id, ts, result, plan_id) VALUES ('dup', 't1', 'ok', 'plan-a'), ('dup', 't2', 'ok', 'gone-plan'), ('solo', 't3', 'ok', NULL)");
$legacy->exec("INSERT INTO uploads (kind, path_or_url, created_at, meta_json) VALUES ('image', 'x.png', 't', '{\"batch\":\"b\"}')");
$legacy->exec("INSERT INTO login_links (token_hash, purpose, email, created_at, expires_at) VALUES ('th', 'login', 'a@b', 't', 't')");
$legacy = null;
Migrator::run($pdo = $open($legacyFile), $migrations, $dataDir . '/backups');
$count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
$check('legacy: all versions applied', $versions($pdo) === $expectedVersions);
$check('legacy: client and its token kept', $count("SELECT COUNT(*) FROM oauth_tokens WHERE client_id = 'keep' AND access_expires_at = '2099-12-31T23:59:59Z'") === 1);
$check('legacy: orphan token dropped (its client does not exist)', $count("SELECT COUNT(*) FROM oauth_tokens WHERE client_id = 'ghost'") === 0);
$check('legacy: plan and snapshot kept, plan payload identical', $count("SELECT COUNT(*) FROM plans WHERE id = 'plan-a' AND payload_json = '{\"x\":1}' AND client_id IS NULL") === 1 && $count('SELECT COUNT(*) FROM snapshots') === 1);
$check('legacy: offline log rows all kept', $count('SELECT COUNT(*) FROM offline_log') === 3);
$check('legacy: duplicate ok demoted to duplicate (earliest ok wins)', $count("SELECT COUNT(*) FROM offline_log WHERE order_id = 'dup' AND result = 'ok' AND ts = 't1'") === 1 && $count("SELECT COUNT(*) FROM offline_log WHERE order_id = 'dup' AND result = 'duplicate'") === 1);
$check('legacy: orphan plan_id in the offline log set to NULL, row kept', $count("SELECT COUNT(*) FROM offline_log WHERE order_id = 'dup' AND plan_id IS NULL") === 1 && $count("SELECT COUNT(*) FROM offline_log WHERE plan_id = 'plan-a'") === 1);
$check('legacy: upload and login link kept (meta_json too)', $count("SELECT COUNT(*) FROM uploads WHERE meta_json = '{\"batch\":\"b\"}'") === 1 && $count('SELECT COUNT(*) FROM login_links') === 1);
$check('legacy: existing client keeps full access (scope default)', $count("SELECT COUNT(*) FROM mcp_clients WHERE client_id = 'keep' AND scope = 'ads.write'") === 1);
$check('legacy: integrity and foreign keys ok', $pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok' && $pdo->query('PRAGMA foreign_key_check')->fetchAll() === []);
$backups = glob($dataDir . '/backups/app-premigrate-v0-*.sqlite') ?: [];
$check('legacy: a backup was taken before the change', count($backups) === 1);
if ($backups) {
    $b = $open($backups[0]);
    $check('legacy: the backup holds the ORIGINAL data (orphan token still there, no schema_migrations)',
        (int) $b->query('SELECT COUNT(*) FROM oauth_tokens')->fetchColumn() === 2
        && (int) $b->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'schema_migrations'")->fetchColumn() === 0);
}
Migrator::run($pdo, $migrations, $dataDir . '/backups');
$check('legacy: second run changes nothing and takes no new backup', count(glob($dataDir . '/backups/*.sqlite') ?: []) === 1 && $versions($pdo) === $expectedVersions);

// 4. A failing migration leaves the database exactly as it was.
$badDir = $dataDir . '/migrations-bad';
mkdir($badDir);
foreach (glob($migrations . '/*.sql') as $f) {
    copy($f, $badDir . '/' . basename($f));
}
file_put_contents($badDir . '/099_broken.sql', "CREATE TABLE should_not_survive (id INTEGER);\nINSERT INTO no_such_table VALUES (1);\n");
$threw = false;
try {
    Migrator::run($pdo, $badDir, $dataDir . '/backups');
} catch (RuntimeException) {
    $threw = true;
}
$check('broken migration: raises an error', $threw);
$check('broken migration: rolled back completely', $versions($pdo) === $expectedVersions && $count("SELECT COUNT(*) FROM sqlite_master WHERE name = 'should_not_survive'") === 0);
$check('broken migration: foreign keys back ON', (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1);

remove_dir($dataDir);
echo $fail === 0 ? "All OK\n" : "FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
