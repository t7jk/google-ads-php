<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * Stateless signed links (upload page, plan preview/approval page): `v<keyVersion>.<expiry ms>[.<context>].<HMAC>`.
 *
 * - The HMAC covers key version, purpose ("upload", "plan:<id>"), expiry and context, so a token fits only the purpose it was
 *   made for. The optional context carries the MCP client that requested the link (uploads are attributed to it).
 * - The signing key is derived from secret_key by HKDF with its own info string per key version. It is NOT the encryption
 *   key of the stored secrets, so rotating link keys never touches them: raise `link_key_version` in config.php to sign new
 *   links with a new key; raise `link_key_min_version` as well to revoke every link signed with older keys.
 *   (Changing secret_key itself would invalidate the encrypted Google token and SMTP password: do not rotate it for links.)
 * - Legacy tokens (`<expiry ms>.<HMAC>`, signed with the pre-0.3 key) are still accepted until they expire: they cannot live
 *   longer than LEGACY_MAX_TTL_MS because every one of them was issued with a 24 h lifetime. Remove accepts_legacy() once that
 *   window has passed in every deployment.
 * - A link is not single-use and cannot be revoked individually; that is why upload links are short (UPLOAD_TTL_MS) and why a
 *   plan link alone can no longer approve a plan (see PlansController: an admin session is required as well).
 */
final class LinkToken
{
    public const TTL_MS = 24 * 3600 * 1000;
    public const UPLOAD_TTL_MS = 2 * 3600 * 1000;
    public const UPLOAD = 'upload';
    private const LEGACY_MAX_TTL_MS = 24 * 3600 * 1000;
    private const CONTEXT_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public static function make(string $purpose, int $ttlMs = self::TTL_MS, ?string $context = null, ?int $nowMs = null): string
    {
        if ($context !== null && !preg_match(self::CONTEXT_PATTERN, $context)) {
            throw new \InvalidArgumentException('Invalid link token context');
        }
        $version = self::currentKeyVersion();
        $exp = (string) (($nowMs ?? self::nowMs()) + $ttlMs);
        $mac = self::mac(self::key($version), "v$version", $purpose, $exp, $context);
        return implode('.', array_filter(["v$version", $exp, $context, $mac], fn(?string $part) => $part !== null));
    }

    public static function verify(string $purpose, ?string $token, ?int $nowMs = null): bool
    {
        return self::parse($purpose, $token, $nowMs ?? self::nowMs()) !== null;
    }

    /** The context a VALID token was made with (null when none). Call only after verify() succeeded. */
    public static function context(string $purpose, ?string $token, ?int $nowMs = null): ?string
    {
        return self::parse($purpose, $token, $nowMs ?? self::nowMs())['context'] ?? null;
    }

    /** @return array{context: ?string}|null the token's data when it is valid and not expired */
    private static function parse(string $purpose, ?string $token, int $nowMs): ?array
    {
        if (!is_string($token) || $token === '') {
            return null;
        }
        $parts = explode('.', $token);
        if (preg_match('/^v(\d{1,4})$/', $parts[0], $m) && in_array(count($parts), [3, 4], true)) {
            return self::parseVersioned((int) $m[1], $purpose, $parts, $nowMs);
        }
        return count($parts) === 2 ? self::parseLegacy($purpose, $parts, $nowMs) : null;
    }

    /** @param list<string> $parts [vN, expiry, (context,) mac] */
    private static function parseVersioned(int $version, string $purpose, array $parts, int $nowMs): ?array
    {
        $mac = array_pop($parts);
        [, $exp] = $parts;
        $context = $parts[2] ?? null;
        $minVersion = (int) config('link_key_min_version', 1);
        if ($version < $minVersion || $version > self::currentKeyVersion() || !self::validExpiry($exp, $nowMs)
            || ($context !== null && !preg_match(self::CONTEXT_PATTERN, $context))) {
            return null;
        }
        $expected = self::mac(self::key($version), "v$version", $purpose, $exp, $context);
        return hash_equals($expected, $mac) ? ['context' => $context] : null;
    }

    /** @param list<string> $parts [expiry, mac] */
    private static function parseLegacy(string $purpose, array $parts, int $nowMs): ?array
    {
        [$exp, $mac] = $parts;
        // Only tokens that could really have been issued under the old 24 h rule: a later expiry would be a forgery.
        if (!self::validExpiry($exp, $nowMs) || (int) $exp > $nowMs + self::LEGACY_MAX_TTL_MS + 60_000) {
            return null;
        }
        $expected = self::mac(self::legacyKey(), null, $purpose, $exp, null);
        return hash_equals($expected, $mac) ? ['context' => null] : null;
    }

    private static function validExpiry(string $exp, int $nowMs): bool
    {
        return preg_match('/^\d{1,16}$/', $exp) === 1 && (int) $exp > $nowMs;
    }

    private static function currentKeyVersion(): int
    {
        return max(1, (int) config('link_key_version', 1));
    }

    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private static function secret(): string
    {
        $secret = base64_decode((string) config('secret_key', ''), true);
        if ($secret === false || $secret === '') {
            throw new \RuntimeException('secret_key is missing in the configuration');
        }
        return $secret;
    }

    private static function key(int $version): string
    {
        return hash_hkdf('sha256', self::secret(), 32, 'gads-link-hmac-v' . $version);
    }

    /** The key of tokens issued before 0.3 (same HKDF input as the old code). */
    private static function legacyKey(): string
    {
        return hash_hkdf('sha256', self::secret(), 32, 'gads-link-token');
    }

    private static function mac(string $key, ?string $version, string $purpose, string $exp, ?string $context): string
    {
        $data = $version === null ? "$purpose\n$exp" : "$version\n$purpose\n$exp\n" . ($context ?? '');
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, $key, true)), '+/', '-_'), '=');
    }
}
