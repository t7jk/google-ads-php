<?php
declare(strict_types=1);

namespace App\Lib;

use PDO;

/**
 * Versioned schema migrations: app/migrations/NNN_name.sql, applied in order, each in its own transaction.
 * The only source of truth for the schema (there is no schema.sql); never edit an applied migration, add a new one.
 *
 * Called once when Db opens the connection. The cost of a normal request is one `SELECT MAX(version)` plus one glob():
 * nothing is parsed or executed unless a migration is pending.
 *
 * Safety (data must never be lost):
 *  - before the first pending migration the database is copied with VACUUM INTO to DATA_PATH/backups/ (a failed backup
 *    aborts the migration; the newest KEEP_BACKUPS copies are kept);
 *  - each migration runs in BEGIN IMMEDIATE ... COMMIT, and the version row is written in the same transaction, so a failure
 *    leaves the database exactly as it was; two parallel requests cannot both apply the same migration (the second one
 *    re-checks the version after it gets the write lock);
 *  - foreign_keys is OFF while a migration runs (SQLite needs that to rebuild a table that others reference) and
 *    PRAGMA foreign_key_check must come back empty before COMMIT.
 * Pitfall: backup() and the PRAGMAs are SQLite-specific; a move to MySQL/PostgreSQL replaces this class's backup step
 * (mysqldump / pg_dump) and nothing else, because the .sql files use portable types.
 */
final class Migrator
{
    private const KEEP_BACKUPS = 5;

    /** Applies every pending migration. Throws (and leaves the database unchanged) when one fails. */
    public static function run(PDO $pdo, string $dir, string $backupDir): void
    {
        $pending = self::pending($pdo, $dir);
        if (!$pending) {
            return;
        }
        $from = self::currentVersion($pdo);
        if (self::hasTables($pdo)) {
            self::backup($pdo, $backupDir, $from);
        }
        self::addMissingLegacyColumns($pdo);
        foreach ($pending as $version => $file) {
            self::apply($pdo, $version, basename($file, '.sql'), (string) file_get_contents($file));
        }
    }

    /** @return array<int, string> version => file path, only versions above the current one, ascending */
    private static function pending(PDO $pdo, string $dir): array
    {
        $current = self::currentVersion($pdo);
        $files = [];
        foreach (glob($dir . '/*.sql') ?: [] as $file) {
            if (preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', basename($file), $m) && (int) $m[1] > $current) {
                $files[(int) $m[1]] = $file;
            }
        }
        ksort($files);
        return $files;
    }

    /** 0 when nothing has been applied yet (also when the table does not exist: a brand-new or pre-migration database). */
    private static function currentVersion(PDO $pdo): int
    {
        try {
            return (int) $pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    private static function hasTables(PDO $pdo): bool
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn() > 0;
    }

    /** A consistent file copy of the database taken before changing it (VACUUM INTO also works in WAL mode). */
    private static function backup(PDO $pdo, string $dir, int $fromVersion): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the migration backup directory; migration aborted.');
        }
        $target = $dir . '/app-premigrate-v' . $fromVersion . '-' . gmdate('Ymd-His') . '.sqlite';
        if (is_file($target)) {
            return; // a parallel request took the backup in the same second
        }
        // VACUUM INTO takes a string expression, not a bound parameter: the path is ours (not user input), quoted by PDO.
        $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
        @chmod($target, 0660);
        $old = glob($dir . '/app-premigrate-*.sqlite') ?: [];
        sort($old); // names sort chronologically
        foreach (array_slice($old, 0, max(0, count($old) - self::KEEP_BACKUPS)) as $file) {
            @unlink($file);
        }
    }

    /**
     * Databases created before migrations existed may lack a column that the old code used to add lazily at runtime
     * (uploads.meta_json). SQLite has no "ADD COLUMN IF NOT EXISTS", so this is checked here, before migration 002
     * rebuilds uploads and would otherwise lose that column.
     */
    private static function addMissingLegacyColumns(PDO $pdo): void
    {
        $columns = array_column($pdo->query('PRAGMA table_info(uploads)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if ($columns && !in_array('meta_json', $columns, true)) {
            $pdo->exec('ALTER TABLE uploads ADD COLUMN meta_json TEXT');
        }
    }

    private static function apply(PDO $pdo, int $version, string $name, string $sql): void
    {
        $pdo->exec('PRAGMA foreign_keys = OFF'); // a no-op inside a transaction, so it must come first
        $began = false;
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $began = true;
            $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL)');
            if (self::currentVersion($pdo) >= $version) {
                $pdo->exec('ROLLBACK'); // another request applied it while we waited for the lock
                return;
            }
            $pdo->exec($sql);
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, name, applied_at) VALUES (?, ?, ?)');
            $stmt->execute([$version, $name, now_iso()]);
            if ($pdo->query('PRAGMA foreign_key_check')->fetchAll()) {
                throw new \RuntimeException('foreign_key_check found violations');
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($began) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (\PDOException) {
                    // The transaction was already closed by SQLite itself.
                }
            }
            throw new \RuntimeException("Migration $name failed and was rolled back: " . $e->getMessage(), 0, $e);
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}
