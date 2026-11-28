<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Lib\{Crypto, Db, Mcp, McpServer, RateLimiter};

/** POST /mcp: Bearer (token from OAuthController) + JSON-RPC (Lib\McpServer). */
final class McpController
{
    // Limits against runaway or abusive clients. The body limit must stay above the largest legitimate request:
    // an image sent as base64 (5 MB file = ~6.7 MB), which is why it is 8 MiB and not smaller.
    private const MAX_BODY_BYTES = 8_388_608;
    private const MAX_BATCH_ITEMS = 20;
    private const REQUESTS_PER_MINUTE = 300;     // per client; a busy conversation makes a few calls per second at most
    private const LAST_USED_REFRESH_SECONDS = 60; // last_used_at is written at most this often (a write per request is wasteful)

    public function handle(): void
    {
        $token = $this->authenticate();
        Mcp::$clientId = $token['client_id'];
        Mcp::$scope = $token['scope'];
        RateLimiter::enforce('mcp:' . $token['client_id'], self::REQUESTS_PER_MINUTE, 60);

        $body = json_decode($this->readBody(), true);
        if (!is_array($body)) {
            $this->rpcError(-32700, 'Invalid JSON', 400);
        }
        $isBatch = array_is_list($body);
        if ($isBatch && count($body) > self::MAX_BATCH_ITEMS) {
            $this->rpcError(-32600, 'Batch too large: at most ' . self::MAX_BATCH_ITEMS . ' requests per call', 413);
        }
        \App\Lib\Events::log('mcp_request', ['client' => Mcp::$clientId,
            'methods' => implode(',', array_map(fn($m) => (string) ($m['method'] ?? '?'), $isBatch ? $body : [$body]))]);
        $responses = array_values(array_filter(array_map([McpServer::class, 'handle'], $isBatch ? $body : [$body])));
        if (!$responses) {
            http_response_code(202); // notifications only: no response body
            exit;
        }
        json_out($isBatch ? $responses : $responses[0]);
    }

    /** The request body, at most MAX_BODY_BYTES; a larger one ends the request with 413 (never read fully into memory). */
    private function readBody(): string
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
            $this->rpcError(-32600, 'Request body too large', 413);
        }
        $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1); // also covers chunked bodies
        if (strlen($body) > self::MAX_BODY_BYTES) {
            $this->rpcError(-32600, 'Request body too large', 413);
        }
        return $body;
    }

    private function rpcError(int $code, string $message, int $status): never
    {
        json_out(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]], $status);
    }

    public function methodNotAllowed(): void
    {
        json_out(['error' => 'Method not allowed: use POST'], 405, ['Allow' => 'POST']);
    }

    /** @return array<string, mixed> oauth_tokens row; missing/invalid Bearer -> 401 with WWW-Authenticate */
    private function authenticate(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $row = null;
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            // The join also yields the client's scope; a token whose client is gone does not authenticate.
            $row = Db::one(
                'SELECT t.*, c.scope FROM oauth_tokens t JOIN mcp_clients c ON c.client_id = t.client_id
                 WHERE t.access_hash = ? AND t.revoked_at IS NULL AND t.access_expires_at > ?',
                [Crypto::sha256($m[1]), now_iso()]
            );
        }
        if ($row === null) {
            \App\Lib\Events::log('mcp_request', ['ok' => false, 'result' => $header === '' ? 'missing_token' : 'invalid_or_expired_token']);
            $challenge = 'Bearer resource_metadata="' . base_url() . '/.well-known/oauth-protected-resource", scope="ads"';
            json_out(['error' => 'invalid_token'], 401, ['WWW-Authenticate' => $challenge]);
        }
        $this->touch($row);
        return $row;
    }

    /** Records the last use (shown in the Connections tab) at most once a minute: this is a write in the hot read path. */
    private function touch(array $row): void
    {
        if ($row['last_used_at'] !== null && $row['last_used_at'] > now_iso(-self::LAST_USED_REFRESH_SECONDS)) {
            return;
        }
        Db::exec('UPDATE oauth_tokens SET last_used_at = ?, last_ip = ?, user_agent = ? WHERE id = ?', [
            now_iso(), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $row['id'],
        ]);
    }
}
