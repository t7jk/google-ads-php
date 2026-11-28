<?php
declare(strict_types=1);

namespace App\Install;

/**
 * Writes config.php for a new installation from app/config.example.php: the example (with all its comments and defaults) is
 * copied and only the values the installer knows are replaced. The result is checked by loading it before it is put in place.
 *
 * Safety: never overwrites an existing config.php (that would destroy the secret_key and with it every stored secret);
 * written to a temporary file, mode 0600, then renamed, so a half-written config.php can never be seen by another request.
 */
final class ConfigFile
{
    /** @return string 32 random bytes, base64 (the format Lib\Crypto expects) */
    public static function newSecretKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * @param array<string,string> $values keys of config.example.php with string values (public_base_url, secret_key, admin_email, allowed_email)
     * @throws InstallError
     */
    public static function write(string $dataDir, array $values): void
    {
        $target = $dataDir . '/config.php';
        if (file_exists($target)) {
            throw new InstallError('config.php already exists; the installer never overwrites it.');
        }
        $content = self::render((string) file_get_contents(APP_PATH . '/config.example.php'), $values);
        $temp = $dataDir . '/config.php.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temp, $content) === false || !chmod($temp, 0600)) {
            @unlink($temp);
            throw new InstallError('Could not write config.php into the data directory. Check that it is writable.');
        }
        if (!self::readsBack($temp, $values) || !rename($temp, $target)) {
            @unlink($temp);
            throw new InstallError('Could not save config.php (it did not read back correctly).');
        }
    }

    /** Replaces `'key' => '<anything>',` for each given key; keeps every other line (and comment) as it is. */
    public static function render(string $example, array $values): string
    {
        foreach ($values as $key => $value) {
            $pattern = "/^(\\s*'" . preg_quote($key, '/') . "'\\s*=>\\s*)'[^']*'(,.*)$/m";
            $count = 0;
            $example = (string) preg_replace_callback($pattern, fn(array $m): string => $m[1] . var_export($value, true) . $m[2], $example, 1, $count);
            if ($count !== 1) {
                throw new InstallError("config.example.php has no line for \"$key\" (installer and template are out of sync).");
            }
        }
        return $example;
    }

    private static function readsBack(string $file, array $values): bool
    {
        $loaded = self::load($file);
        foreach ($values as $key => $value) {
            if (($loaded[$key] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private static function load(string $file): array
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        return (array) require $file;
    }
}
