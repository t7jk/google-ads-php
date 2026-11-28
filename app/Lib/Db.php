<?php
declare(strict_types=1);

namespace App\Lib;

use PDO;

/**
 * A thin layer over PDO (SQLite). Always prepared statements: values go through $params, never concatenated into SQL.
 * The connection is opened lazily; on opening: WAL + busy_timeout (concurrent requests on shared hosting) and pending schema
 * migrations (Lib\Migrator; app/migrations/*.sql is the only definition of the schema).
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            if (!is_dir(DATA_PATH)) {
                mkdir(DATA_PATH, 0775, true);
            }
            $file = DATA_PATH . '/app.sqlite';
            $isNew = !is_file($file);
            $pdo = new PDO('sqlite:' . $file, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA foreign_keys = ON');
            Migrator::run($pdo, APP_PATH . '/migrations', DATA_PATH . '/backups');
            if ($isNew) {
                // SQLite creates the file as 0644; the web server group must be able to write (CLI and Apache run as different users).
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @chmod($file . $suffix, 0660);
                }
            }
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /** @return list<array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        return self::all($sql, $params)[0] ?? null;
    }

    /** Returns the number of changed rows. */
    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Runs an INSERT and returns the id of the new row (for tables with an INTEGER PRIMARY KEY). */
    public static function insert(string $sql, array $params = []): int
    {
        self::exec($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }
}
