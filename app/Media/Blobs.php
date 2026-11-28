<?php
declare(strict_types=1);

namespace App\Media;

/**
 * Image store addressed by sha256: DATA_PATH/uploads/<sha256>.<jpg|png>. uploadId = sha256 (64 hex characters).
 * Files expire after plan_ttl_hours (by mtime; re-uploading the same image refreshes the mtime).
 * The uploads directory is outside the web root — files are served only by the controller (thumbnails).
 */
final class Blobs
{
    private const SHA_RE = '/^[0-9a-f]{64}$/';
    private const EXTS = ['jpg', 'png'];

    public static function dir(): string
    {
        $dir = DATA_PATH . '/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function ttlHours(): int
    {
        return max(1, (int) config('plan_ttl_hours', 24));
    }

    /** Saves the image (atomically) and returns its sha256. Also cleans up expired files along the way. */
    public static function put(string $bytes, string $ext): string
    {
        self::sweep();
        $sha = hash('sha256', $bytes);
        $file = self::dir() . "/$sha.$ext";
        if (is_file($file)) {
            @touch($file);
        } else {
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, $bytes);
            @chmod($tmp, 0660);
            rename($tmp, $file);
        }
        return $sha;
    }

    /** Path of the file, or null (bad sha / no such file). */
    public static function path(string $sha): ?string
    {
        if (!preg_match(self::SHA_RE, $sha)) {
            return null;
        }
        foreach (self::EXTS as $ext) {
            $f = self::dir() . "/$sha.$ext";
            if (is_file($f)) {
                return $f;
            }
        }
        return null;
    }

    public static function has(string $sha): bool
    {
        return self::path($sha) !== null;
    }

    public static function get(string $sha): ?string
    {
        $f = self::path($sha);
        $data = $f ? @file_get_contents($f) : false;
        return $data === false ? null : $data;
    }

    /** Deletes files older than the TTL; returns the number deleted. Called on every upload (there is no background process). */
    public static function sweep(): int
    {
        $limit = time() - self::ttlHours() * 3600;
        $removed = 0;
        foreach (glob(self::dir() . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < $limit && @unlink($f)) {
                $removed++;
            }
        }
        return $removed;
    }
}
