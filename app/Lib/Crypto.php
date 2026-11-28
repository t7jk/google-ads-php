<?php
declare(strict_types=1);

namespace App\Lib;

/** Encryption of secrets in the database (sodium secretbox). Key: config 'secret_key' = base64 of 32 bytes. Changing the key invalidates stored secrets. */
final class Crypto
{
    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    /** Returns null when the ciphertext is corrupted or the key has changed. */
    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ($raw === false || strlen($raw) <= $n) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), self::key());
        return $plain === false ? null : $plain;
    }

    public static function randomHex(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function sha256(string $value): string
    {
        return hash('sha256', $value);
    }

    private static function key(): string
    {
        $key = base64_decode((string) config('secret_key', ''), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Missing or invalid secret_key in config.php (base64 of 32 bytes).');
        }
        return $key;
    }
}
