<?php
declare(strict_types=1);

namespace App\Lib;

/** Key-value store in the settings table. Save secrets (the Google refresh token) via setSecret(): encrypted with Crypto. */
final class Settings
{
    public static function get(string $key): ?string
    {
        $row = Db::one('SELECT value FROM settings WHERE key = ?', [$key]);
        return $row['value'] ?? null;
    }

    public static function set(string $key, string $value): void
    {
        // Portable "upsert": UPDATE first, and when nothing changed — INSERT.
        if (Db::exec('UPDATE settings SET value = ? WHERE key = ?', [$value, $key]) === 0) {
            Db::exec('INSERT INTO settings (key, value) VALUES (?, ?)', [$key, $value]);
        }
    }

    public static function delete(string $key): void
    {
        Db::exec('DELETE FROM settings WHERE key = ?', [$key]);
    }

    public static function getSecret(string $key): ?string
    {
        $value = self::get($key);
        return $value === null ? null : Crypto::decrypt($value);
    }

    public static function setSecret(string $key, string $plain): void
    {
        self::set($key, Crypto::encrypt($plain));
    }
}
