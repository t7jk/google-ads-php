<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Lib\{Clients, Crypto, Db, Events, RateLimiter};

/**
 * Authorization server for the MCP connector. No consent or login screen: the barrier is the client secret (checked at /token),
 * and /authorize issues a code only for a known client_id and an allowed redirect_uri. PKCE S256 is mandatory.
 */
final class OAuthController
{
    private const CODE_TTL = 300;               // 5 min
    private const ACCESS_TTL = 3600;            // 1 h
    private const REFRESH_TTL = 90 * 86400;     // 90 days
    private const REFRESH_RETRY_GRACE = 30;    // seconds after a rotation in which presenting the old refresh token is not treated as theft
    // Advertised OAuth scopes. What a client may DO is its server-side scope (mcp_clients.scope: ads.read / ads.write), not negotiated.
    private const SCOPES = ['ads', 'offline_access'];
    // Brute-force protection: failed client authentication (unknown client_id or wrong secret) per IP, shared by /authorize and /token.
    private const CLIENT_FAIL_LIMIT = 10;
    private const CLIENT_FAIL_WINDOW = 900;     // 15 min

    public function protectedResource(): void
    {
        json_out([
            'resource' => base_url() . '/mcp',
            'authorization_servers' => [base_url()],
            'scopes_supported' => self::SCOPES,
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function serverMetadata(): void
    {
        json_out([
            'issuer' => base_url(),
            'authorization_endpoint' => base_url() . '/authorize',
            'token_endpoint' => base_url() . '/token',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'scopes_supported' => self::SCOPES,
        ]);
    }

    public function authorize(): void
    {
        $this->prune();
        $this->rejectLockedOutIp();
        $client = Clients::find((string) ($_GET['client_id'] ?? ''));
        $redirect = (string) ($_GET['redirect_uri'] ?? '');
        if ($client === null) {
            $this->countClientFailure();
            view('error', ['title' => 'Error', 'message' => 'Unknown client.'], 400);
        }
        if (!Clients::redirectAllowed($client, $redirect)) {
            Events::log('authorize', ['client' => $client['client_id'], 'ok' => false, 'result' => 'redirect_not_allowed']);
            view('error', ['title' => 'Error', 'message' => 'Redirect URI not allowed.'], 400);
        }
        if (($_GET['response_type'] ?? '') !== 'code') {
            $this->back($redirect, ['error' => 'unsupported_response_type']);
        }
        $challenge = (string) ($_GET['code_challenge'] ?? '');
        if ($challenge === '' || ($_GET['code_challenge_method'] ?? '') !== 'S256') {
            $this->back($redirect, ['error' => 'invalid_request']);
        }
        if (!empty($_GET['resource']) && $_GET['resource'] !== base_url() . '/mcp') {
            json_out(['error' => 'invalid_target'], 400);
        }
        $code = Crypto::randomHex(32);
        Db::exec('INSERT INTO oauth_codes (code, client_id, redirect_uri, code_challenge, expires_at) VALUES (?, ?, ?, ?, ?)', [
            Crypto::sha256($code), $client['client_id'], $redirect, $challenge, now_iso(self::CODE_TTL),
        ]);
        Events::log('authorize', ['client' => $client['client_id'], 'ok' => true]);
        $this->back($redirect, ['code' => $code]);
    }

    public function token(): void
    {
        $this->prune();
        header('Cache-Control: no-store');
        $this->rejectLockedOutIp();
        $client = $this->authenticateClient();
        if ($client === null) {
            $this->countClientFailure();
            $this->tokenError('invalid_client', 401);
        }
        $grant = (string) ($_POST['grant_type'] ?? '');
        if ($grant === 'authorization_code') {
            $this->exchangeCode($client);
        }
        if ($grant === 'refresh_token') {
            $this->refresh($client);
        }
        $this->tokenError('unsupported_grant_type');
    }

    private function exchangeCode(array $client): never
    {
        $hash = Crypto::sha256((string) ($_POST['code'] ?? ''));
        $rec = Db::one('SELECT * FROM oauth_codes WHERE code = ?', [$hash]);
        Db::exec('DELETE FROM oauth_codes WHERE code = ?', [$hash]); // single-use, even after a failed attempt
        $verifier = (string) ($_POST['code_verifier'] ?? '');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $valid = $rec !== null && $rec['expires_at'] > now_iso() && $rec['client_id'] === $client['client_id']
            && $rec['redirect_uri'] === (string) ($_POST['redirect_uri'] ?? '')
            && $verifier !== '' && hash_equals($rec['code_challenge'], $challenge);
        if (!$valid) {
            $this->tokenError('invalid_grant');
        }
        $tokens = $this->newTokens();
        Db::exec(
            'INSERT INTO oauth_tokens (client_id, access_hash, refresh_hash, access_expires_at, refresh_expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$client['client_id'], Crypto::sha256($tokens['access_token']), Crypto::sha256($tokens['refresh_token']), now_iso(self::ACCESS_TTL), now_iso(self::REFRESH_TTL), now_iso()]
        );
        Events::log('token', ['ok' => true, 'grant' => 'authorization_code', 'client' => $client['client_id']]);
        json_out($tokens);
    }

    /**
     * Rotation: the same row (= one connection, a "token family") gets new tokens and the old refresh token stops working.
     * The replaced token is remembered (prev_refresh_hash) so that its reuse can be detected, see rejectRefresh().
     * The UPDATE is conditional on the presented hash: of two parallel refreshes with the same token exactly one rotates.
     */
    private function refresh(array $client): never
    {
        $presented = Crypto::sha256((string) ($_POST['refresh_token'] ?? ''));
        $row = Db::one(
            'SELECT id FROM oauth_tokens WHERE refresh_hash = ? AND client_id = ? AND revoked_at IS NULL AND refresh_expires_at > ?',
            [$presented, $client['client_id'], now_iso()]
        );
        if ($row === null) {
            $this->rejectRefresh($presented, $client['client_id']);
        }
        $tokens = $this->newTokens();
        $rotated = Db::exec(
            'UPDATE oauth_tokens SET access_hash = ?, refresh_hash = ?, access_expires_at = ?, refresh_expires_at = ?, prev_refresh_hash = ?, refresh_rotated_at = ?
             WHERE id = ? AND refresh_hash = ?',
            [Crypto::sha256($tokens['access_token']), Crypto::sha256($tokens['refresh_token']), now_iso(self::ACCESS_TTL), now_iso(self::REFRESH_TTL), $presented, now_iso(), $row['id'], $presented]
        );
        if ($rotated !== 1) {
            $this->tokenError('invalid_grant'); // lost a race with a parallel refresh using the same token
        }
        Events::log('token', ['ok' => true, 'grant' => 'refresh_token', 'client' => $client['client_id']]);
        json_out($tokens);
    }

    /**
     * An unknown or expired refresh token ends here. If it is the one that was replaced by the last rotation, somebody is
     * using a copy that the legitimate client no longer holds (theft), or the client missed our response. Within
     * REFRESH_RETRY_GRACE of the rotation that is treated as an honest retry (plain invalid_grant); later the whole connection is
     * revoked, so neither the thief nor the victim's newest token keeps working and the owner has to connect again.
     */
    private function rejectRefresh(string $presentedHash, string $clientId): never
    {
        $family = Db::one(
            'SELECT id, refresh_rotated_at FROM oauth_tokens WHERE prev_refresh_hash = ? AND client_id = ? AND revoked_at IS NULL',
            [$presentedHash, $clientId]
        );
        if ($family !== null && (string) $family['refresh_rotated_at'] <= now_iso(-self::REFRESH_RETRY_GRACE)) {
            Db::exec('UPDATE oauth_tokens SET revoked_at = ? WHERE id = ?', [now_iso(), $family['id']]);
            Events::log('token', ['ok' => false, 'result' => 'refresh_token_reuse_revoked', 'client' => $clientId]);
        }
        $this->tokenError('invalid_grant');
    }

    private function newTokens(): array
    {
        return [
            'access_token' => Crypto::randomHex(32),
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => Crypto::randomHex(32),
            'scope' => implode(' ', self::SCOPES),
        ];
    }

    /** client_secret_post or client_secret_basic. */
    private function authenticateClient(): ?array
    {
        $id = (string) ($_POST['client_id'] ?? '');
        $secret = (string) ($_POST['client_secret'] ?? '');
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            $id = rawurldecode($_SERVER['PHP_AUTH_USER']);
            $secret = rawurldecode($_SERVER['PHP_AUTH_PW'] ?? '');
        } elseif (preg_match('/^Basic\s+(\S+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
            [$id, $secret] = array_pad(array_map('rawurldecode', explode(':', (string) base64_decode($m[1]), 2)), 2, '');
        }
        $client = Clients::find($id);
        return $client !== null && Clients::secretMatches($client, $secret) ? $client : null;
    }

    private function clientFailBucket(): string
    {
        return 'client_fail:' . client_ip();
    }

    /** An IP that failed client authentication too often gets 429 before any secret is checked. */
    private function rejectLockedOutIp(): void
    {
        if (RateLimiter::isLimited($this->clientFailBucket(), self::CLIENT_FAIL_LIMIT)) {
            RateLimiter::reject($this->clientFailBucket());
        }
    }

    private function countClientFailure(): void
    {
        RateLimiter::hit($this->clientFailBucket(), self::CLIENT_FAIL_LIMIT, self::CLIENT_FAIL_WINDOW);
    }

    private function tokenError(string $error, int $status = 400): never
    {
        Events::log('token', ['ok' => false, 'result' => $error]);
        json_out(['error' => $error], $status);
    }

    private function back(string $redirect, array $params): never
    {
        if (!empty($_GET['state'])) {
            $params['state'] = (string) $_GET['state'];
        }
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . http_build_query($params));
        exit;
    }

    /** Cleanup on request (no background processes). */
    private function prune(): void
    {
        Db::exec('DELETE FROM oauth_codes WHERE expires_at < ?', [now_iso()]);
        Db::exec('DELETE FROM oauth_tokens WHERE refresh_expires_at < ? OR revoked_at < ?', [now_iso(), now_iso(-86400 * 30)]);
    }
}
