<?php
declare(strict_types=1);

namespace App\Lib;

/** MCP clients (claude.ai, Claude Code): the mcp_clients table. The secret is shown once, only its sha256 is stored. */
final class Clients
{
    public const CLAUDE_AI_REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    public const LOOPBACK = 'loopback';
    /** Client without OAuth: a permanent Bearer token (e.g. Claude Code over SSH, no browser). No redirect_uri = /authorize rejects it. */
    public const BEARER = 'bearer';
    public const TYPES = ['claude-ai', 'claude-code', 'claude-code-token'];
    /** Scope per client (column mcp_clients.scope): read-only clients may call only tools annotated readOnlyHint. */
    public const SCOPE_READ = 'ads.read';
    public const SCOPE_WRITE = 'ads.write';
    public const SCOPES = [self::SCOPE_READ, self::SCOPE_WRITE];
    /** Default lifetime of a static Bearer token (config 'static_token_ttl_days' overrides it). */
    private const DEFAULT_STATIC_TOKEN_DAYS = 180;
    /** An expired static token row stays this long, so the admin can still renew it in the panel instead of re-creating the client. */
    private const EXPIRED_TOKEN_GRACE_DAYS = 30;
    /** A connection nobody has used for this long is flagged in the Connections tab (a forgotten token is a risk, not a convenience). */
    private const UNUSED_WARNING_DAYS = 30;

    /** @return array{client_id:string, secret:string} the plain secret only here */
    public static function create(string $name, string $type, string $scope = self::SCOPE_WRITE): array
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('Invalid client scope');
        }
        $clientId = $type . '-' . Crypto::randomHex(4);
        $secret = Crypto::randomHex(32); // 64 znaki hex
        Db::insert('INSERT INTO mcp_clients (client_id, name, secret_hash, redirect_uris, created_at, scope) VALUES (?, ?, ?, ?, ?, ?)', [
            $clientId, $name, Crypto::sha256($secret), self::redirectFor($type), now_iso(), $scope,
        ]);
        $result = ['client_id' => $clientId, 'secret' => $secret];
        if ($type === 'claude-code-token') {
            $result['token'] = self::createStaticToken($clientId);
        }
        return $result;
    }

    private static function redirectFor(string $type): string
    {
        return match ($type) {
            'claude-ai' => self::CLAUDE_AI_REDIRECT,
            'claude-code-token' => self::BEARER,
            default => self::LOOPBACK,
        };
    }

    /**
     * A long-lived oauth_tokens row: McpController::authenticate accepts it like a normal token, and the Connections tab
     * shows it under "Active connections" (it can be disconnected or renewed). It expires after staticTokenDays(); the refresh
     * token is random and unused (refresh_expires_at is only the point where the row is cleaned up).
     * @return string plain token (shown once; only its sha256 is stored)
     */
    private static function createStaticToken(string $clientId): string
    {
        $token = Crypto::randomHex(32);
        Db::exec(
            'INSERT INTO oauth_tokens (client_id, access_hash, refresh_hash, access_expires_at, refresh_expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$clientId, Crypto::sha256($token), Crypto::sha256(Crypto::randomHex(32)), ...array_values(self::staticTokenExpiry()), now_iso()]
        );
        return $token;
    }

    public static function staticTokenDays(): int
    {
        return max(1, (int) config('static_token_ttl_days', self::DEFAULT_STATIC_TOKEN_DAYS));
    }

    /** @return array{access: string, cleanup: string} expiry of a static token issued now, and when its row may be deleted */
    private static function staticTokenExpiry(): array
    {
        $days = self::staticTokenDays();
        return ['access' => now_iso($days * 86400), 'cleanup' => now_iso(($days + self::EXPIRED_TOKEN_GRACE_DAYS) * 86400)];
    }

    /** Renews a static token of a bearer client for another full lifetime (the token string stays the same). A revoked token stays revoked. Returns false for other rows. */
    public static function renewStaticToken(int $tokenRowId): bool
    {
        $expiry = self::staticTokenExpiry();
        return Db::exec(
            'UPDATE oauth_tokens SET access_expires_at = ?, refresh_expires_at = ? WHERE id = ? AND revoked_at IS NULL
             AND client_id IN (SELECT client_id FROM mcp_clients WHERE redirect_uris = ?)',
            [$expiry['access'], $expiry['cleanup'], $tokenRowId, self::BEARER]
        ) === 1;
    }

    /** Whether a token row (oauth_tokens: last_used_at, created_at) has not been used for UNUSED_WARNING_DAYS. */
    public static function isUnused(array $token): bool
    {
        return (string) ($token['last_used_at'] ?? $token['created_at']) < now_iso(-self::UNUSED_WARNING_DAYS * 86400);
    }

    /** Whether the row is a static Bearer token (it can be renewed; it has an expiry that matters to the admin). */
    public static function isStaticToken(array $token): bool
    {
        return ($token['redirect_uris'] ?? null) === self::BEARER;
    }

    public static function all(): array
    {
        return Db::all('SELECT * FROM mcp_clients ORDER BY id');
    }

    public static function find(string $clientId): ?array
    {
        return Db::one('SELECT * FROM mcp_clients WHERE client_id = ?', [$clientId]);
    }

    /** Deletes the client together with its tokens and codes (immediate cut-off). */
    public static function delete(string $clientId): void
    {
        Db::exec('DELETE FROM oauth_tokens WHERE client_id = ?', [$clientId]);
        Db::exec('DELETE FROM oauth_codes WHERE client_id = ?', [$clientId]);
        Db::exec('DELETE FROM mcp_clients WHERE client_id = ?', [$clientId]);
    }

    public static function secretMatches(array $client, string $secret): bool
    {
        return $secret !== '' && hash_equals($client['secret_hash'], Crypto::sha256($secret));
    }

    public static function redirectAllowed(array $client, string $uri): bool
    {
        if ($client['redirect_uris'] === self::BEARER) {
            return false; // no OAuth for this client; "bearer" is a marker, not a URL
        }
        if ($client['redirect_uris'] !== self::LOOPBACK) {
            return $uri === $client['redirect_uris'];
        }
        $parts = parse_url($uri);
        // Pitfall: isset($a, $b, $c) is true only when ALL are set, so each part is checked on its own.
        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    public static function typeOf(array $client): string
    {
        return match ($client['redirect_uris']) {
            self::LOOPBACK => 'claude-code',
            self::BEARER => 'claude-code-token',
            default => 'claude-ai',
        };
    }
}
