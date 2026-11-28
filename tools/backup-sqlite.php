<?php
// CLI: consistent backup of the SQLite database (VACUUM INTO) into a directory OUTSIDE the web root, with retention.
// Usage: php tools/backup-sqlite.php [--dir=/path/to/backups] [--keep=14]
//   --dir   target directory (default: "<private dir>-backups" next to the private directory, e.g. next to google-ads-mcp-php)
//   --keep  how many newest backups to keep (default 14)
// Why VACUUM INTO and not a file copy: the database runs in WAL mode, so copying app.sqlite alone can miss committed data
// or catch a half-written state; VACUUM INTO writes a consistent snapshot while the application keeps running.
// The backup contains encrypted secrets (Google refresh token, SMTP password), MCP client hashes and plans: keep it private
// (the directory is created 0700, files 0600) and copy it off the server. config.php (with secret_key) is NOT included:
// without secret_key the encrypted secrets in a restored database cannot be read, so back that file up separately.
// Restore: stop traffic, copy a backup over google-ads-mcp-php-data/app.sqlite, delete app.sqlite-wal and app.sqlite-shm.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
} // CLI-only script, never over the web

const DEFAULT_KEEP = 14;

$privateDir = dirname(__DIR__);
$dataDir = $privateDir . '/google-ads-mcp-php-data';
$options = getopt('', ['dir::', 'keep::']);
$targetDir = rtrim((string) ($options['dir'] ?? $privateDir . '-backups'), '/');
$keep = max(1, (int) ($options['keep'] ?? DEFAULT_KEEP));

exit(runBackup($dataDir . '/app.sqlite', $targetDir, $keep));

function runBackup(string $source, string $targetDir, int $keep): int
{
    if (!is_file($source)) {
        fwrite(STDERR, "Database not found: $source\n");
        return 1;
    }
    if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true)) {
        fwrite(STDERR, "Cannot create the backup directory: $targetDir\n");
        return 1;
    }
    $target = $targetDir . '/app-' . gmdate('Ymd-His') . '.sqlite';
    if (file_exists($target)) { // two runs within one second: never touch (or later delete) the earlier backup
        fwrite(STDERR, "Backup file already exists: $target\n");
        return 1;
    }
    try {
        createSnapshot($source, $target);
        verifySnapshot($target);
    } catch (Throwable $e) {
        @unlink($target);
        fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
        return 1;
    }
    chmod($target, 0600);
    $removed = applyRetention($targetDir, $keep);
    echo "Backup written: $target (" . filesize($target) . " bytes); removed $removed old backup(s)\n";
    return 0;
}

function createSnapshot(string $source, string $target): void
{
    $pdo = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 10000');
    // VACUUM INTO takes a string expression, not a bound parameter: quote it ourselves (the path is ours, not user input).
    $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
}

/** A backup that cannot be opened or fails the integrity check is worse than none: it would replace an older good one. */
function verifySnapshot(string $file): void
{
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $result = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
    if ($result !== 'ok') {
        throw new RuntimeException("integrity_check on the backup returned: $result");
    }
}

/** Deletes all but the newest $keep backups (names sort chronologically); returns how many were removed. */
function applyRetention(string $dir, int $keep): int
{
    $files = glob($dir . '/app-*.sqlite') ?: [];
    sort($files);
    $old = array_slice($files, 0, max(0, count($files) - $keep));
    foreach ($old as $file) {
        unlink($file);
    }
    return count($old);
}
