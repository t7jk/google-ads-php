<?php
// Offline tests of the login links (one-time use, TTL), the OAuth authorization-code flow with PKCE and the redirect_uri rules.
// A THROWAWAY data directory is used (GADS_DATA_DIR); controllers run in child processes (tests/_child.php).
// Refresh-token rotation and reuse detection are covered in tests/stage2-security.php (section I); this file adds the rest.
// Run: php tests/oauth-login.php
declare(strict_types=1);
require __DIR__ . '/_env.php';

putenv('GADS_DATA_DIR=' . ($dataDir = make_data_dir()));
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Auth\LoginLinks;
use App\Controllers\OAuthController;
use App\Lib\{Clients, Db};

$fail = 0;
$check = checker($fail);

// ---- A. Login links: single use, TTL, hashed at rest ----
/** Creates a login link and returns the plain token read from the emergency file (no SMTP in the test). */
$newLink = function (string $purpose = LoginLinks::LOGIN) use ($dataDir): string {
    @unlink($dataDir . '/' . LoginLinks::FALLBACK_FILE);
    LoginLinks::send($purpose, 'admin@example.org', '203.0.113.5');
    preg_match('/t=([0-9a-f]{64})/', (string) file_get_contents($dataDir . '/' . LoginLinks::FALLBACK_FILE), $m);
    return $m[1] ?? '';
};
$token = $newLink();
$check('link: a token was issued', strlen($token) === 64);
$check('link: only the hash is stored', Db::one('SELECT COUNT(*) AS n FROM login_links WHERE token_hash = ?', [$token])['n'] === 0
    && Db::one('SELECT COUNT(*) AS n FROM login_links WHERE token_hash = ?', [hash('sha256', $token)])['n'] === 1);
$check('link: peek does not consume (mail scanners open links)', LoginLinks::peek($token) !== null && LoginLinks::peek($token) !== null);
$check('link: first consume succeeds', LoginLinks::consume($token) !== null);
$check('link: the second consume of the same token fails (one time)', LoginLinks::consume($token) === null && LoginLinks::peek($token) === null);
$token = $newLink();
Db::exec('UPDATE login_links SET expires_at = ? WHERE token_hash = ?', [now_iso(-1), hash('sha256', $token)]);
$check('link: an expired token is refused', LoginLinks::peek($token) === null && LoginLinks::consume($token) === null);
$ttl = fn(string $purpose): int => (int) round((strtotime((string) Db::one('SELECT expires_at FROM login_links WHERE token_hash = ?', [hash('sha256', $newLink($purpose))])['expires_at']) - time()) / 60);
$check('link: login lives 15 minutes, address change 60', abs($ttl(LoginLinks::LOGIN) - 15) <= 1 && abs($ttl(LoginLinks::EMAIL_CHANGE) - 60) <= 1);
$check('link: malformed tokens are refused without a query', LoginLinks::peek('') === null && LoginLinks::peek(str_repeat('g', 64)) === null
    && LoginLinks::peek("' OR 1=1 --") === null && LoginLinks::peek(strtoupper(str_repeat('a', 64))) === null);
$check('link: a guessed token is refused', LoginLinks::consume(bin2hex(random_bytes(32))) === null);

// ---- B. redirect_uri rules (Clients::redirectAllowed) ----
$loopback = Clients::create('loop', 'claude-code');
$loop = Clients::find($loopback['client_id']);
$ok = ['http://localhost:8080/callback', 'http://127.0.0.1:53682/cb', 'http://localhost/cb?x=1'];
$bad = ['https://localhost/cb', 'http://localhost.evil.example/cb', 'http://127.0.0.1.evil.example/cb', 'http://evil.example@localhost/cb',
    'http://evil.example/cb', 'http://localhost/cb#frag', 'http://[::1]/cb', 'ftp://localhost/cb', 'localhost', '', 'javascript:alert(1)', '//localhost/cb'];
$check('redirect: loopback client accepts http localhost/127.0.0.1 on any port', array_filter($ok, fn($u) => !Clients::redirectAllowed($loop, $u)) === []);
$check('redirect: loopback client refuses everything else', array_filter($bad, fn($u) => Clients::redirectAllowed($loop, $u)) === [], json_encode(array_filter($bad, fn($u) => Clients::redirectAllowed($loop, $u))));
$web = Clients::find(Clients::create('web', 'claude-ai')['client_id']);
$check('redirect: claude.ai client needs the exact callback URL', Clients::redirectAllowed($web, Clients::CLAUDE_AI_REDIRECT)
    && !Clients::redirectAllowed($web, Clients::CLAUDE_AI_REDIRECT . '/') && !Clients::redirectAllowed($web, Clients::CLAUDE_AI_REDIRECT . '?x=1')
    && !Clients::redirectAllowed($web, 'https://claude.ai.evil.example/api/mcp/auth_callback') && !Clients::redirectAllowed($web, 'http://localhost/cb'));
$static = Clients::find(Clients::create('static', 'claude-code-token')['client_id']);
$check('redirect: a Bearer-token client has no redirect_uri at all', !Clients::redirectAllowed($static, 'bearer') && !Clients::redirectAllowed($static, 'https://claude.ai/x'));

// ---- C. Authorization code + PKCE through the controllers ----
$verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$redirect = 'http://127.0.0.1:5555/cb';
/** Calls a controller method; returns [HTTP status, decoded JSON body or null]. (The CLI keeps no response headers, so Location cannot be read.) */
$call = function (string $method, array $params, string $verb) use ($dataDir): array {
    $out = call_controller($dataDir, ['class' => OAuthController::class, 'method' => $method, 'server' => ['REQUEST_METHOD' => $verb],
        $verb === 'GET' ? 'get' : 'post' => $params]);
    [$body, $headers] = array_pad(explode("\n--HEADERS--\n", $out, 2), 2, '');
    preg_match('/^Status: (\d+)/m', $headers, $s);
    return [(int) ($s[1] ?? 0), json_decode(trim($body), true)];
};
$codeRows = fn(): int => (int) Db::one('SELECT COUNT(*) AS n FROM oauth_codes')['n'];
/** Runs /authorize; returns true when a code row was issued (the plain code only ever travels in the redirect, which the CLI cannot see). */
$authorizeIssues = function (array $override = []) use ($call, $codeRows, $loopback, $redirect, $challenge): bool {
    $before = $codeRows();
    $call('authorize', $override + ['client_id' => $loopback['client_id'], 'redirect_uri' => $redirect, 'response_type' => 'code',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256'], 'GET');
    return $codeRows() === $before + 1;
};
/** Plants a code exactly as /authorize would store it (hashed) and returns the plain value. */
$seedCode = function (array $override = []) use ($loopback, $redirect, $challenge): string {
    $code = bin2hex(random_bytes(32));
    $row = $override + ['client_id' => $loopback['client_id'], 'redirect_uri' => $redirect, 'code_challenge' => $challenge, 'expires_at' => now_iso(300)];
    Db::exec('INSERT INTO oauth_codes (code, client_id, redirect_uri, code_challenge, expires_at) VALUES (?, ?, ?, ?, ?)',
        [hash('sha256', $code), $row['client_id'], $row['redirect_uri'], $row['code_challenge'], $row['expires_at']]);
    return $code;
};
/** Runs /token with the authorization_code grant. */
$exchange = fn(string $code, array $override = []): array => $call('token', $override + ['grant_type' => 'authorization_code', 'code' => $code,
    'code_verifier' => $verifier, 'redirect_uri' => $redirect, 'client_id' => $loopback['client_id'], 'client_secret' => $loopback['secret']], 'POST');
$error = fn(array $result): string => (string) ($result[1]['error'] ?? '');
$other = Clients::create('other', 'claude-code');

$check('pkce: /authorize issues a code (stored with the challenge and redirect_uri)', $authorizeIssues()
    && Db::one('SELECT code_challenge, redirect_uri FROM oauth_codes')['code_challenge'] === $challenge);
$check('pkce: only the hash of the code is stored (64 hex chars, never the plain code)', preg_match('/^[0-9a-f]{64}$/', (string) Db::one('SELECT code FROM oauth_codes')['code']) === 1);
$check('pkce: a request without a challenge gets no code', !$authorizeIssues(['code_challenge' => '']));
$check('pkce: the plain method gets no code (S256 only)', !$authorizeIssues(['code_challenge_method' => 'plain']));
$check('pkce: a response_type other than code gets no code', !$authorizeIssues(['response_type' => 'token']));
$check('pkce: a disallowed redirect_uri gets no code and an error page, not a redirect', !$authorizeIssues(['redirect_uri' => 'https://evil.example/cb'])
    && $call('authorize', ['client_id' => $loopback['client_id'], 'redirect_uri' => 'https://evil.example/cb', 'response_type' => 'code',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256'], 'GET')[0] === 400);
$check('pkce: an unknown client gets no code', !$authorizeIssues(['client_id' => 'nope']));
$check('pkce: a Bearer-token client gets no code', !$authorizeIssues(['client_id' => Clients::create('static2', 'claude-code-token')['client_id'], 'redirect_uri' => 'bearer']));

$code = $seedCode();
$check('pkce: a wrong verifier is invalid_grant', $error($exchange($code, ['code_verifier' => 'x' . $verifier])) === 'invalid_grant');
$check('pkce: the code is burned by the failed attempt (even the right verifier fails afterwards)', $error($exchange($code)) === 'invalid_grant');
$good = $exchange($seedCode());
$check('pkce: the right verifier returns tokens', $good[0] === 200 && isset($good[1]['access_token'], $good[1]['refresh_token']) && ($good[1]['token_type'] ?? '') === 'Bearer', json_encode($good));
$check('pkce: only hashes of the issued tokens are stored', Db::one('SELECT COUNT(*) AS n FROM oauth_tokens WHERE access_hash = ?', [$good[1]['access_token'] ?? 'x'])['n'] === 0
    && Db::one('SELECT COUNT(*) AS n FROM oauth_tokens WHERE access_hash = ?', [hash('sha256', (string) ($good[1]['access_token'] ?? ''))])['n'] === 1);
$spent = $seedCode();
$exchange($spent);
$check('pkce: a used code cannot be replayed', $error($exchange($spent)) === 'invalid_grant');
$check('pkce: an empty verifier is refused', $error($exchange($seedCode(), ['code_verifier' => ''])) === 'invalid_grant');
$check('pkce: a different redirect_uri at /token is refused', $error($exchange($seedCode(), ['redirect_uri' => 'http://127.0.0.1:6666/cb'])) === 'invalid_grant');
$check('pkce: an expired code is refused', $error($exchange($seedCode(['expires_at' => now_iso(-1)]))) === 'invalid_grant');
$check('pkce: a code issued to another client cannot be redeemed by this one', $error($exchange($seedCode(['client_id' => $other['client_id']]))) === 'invalid_grant');
$check('pkce: a code cannot be redeemed with another client\'s credentials', $error($exchange($seedCode(), ['client_id' => $other['client_id'], 'client_secret' => $other['secret']])) === 'invalid_grant');
$bad = $exchange($seedCode(), ['client_secret' => 'wrong']);
$check('token: a wrong client secret is 401 invalid_client', $bad[0] === 401 && $error($bad) === 'invalid_client');
$check('token: an unknown grant type is refused', $error($call('token', ['grant_type' => 'password', 'client_id' => $loopback['client_id'], 'client_secret' => $loopback['secret']], 'POST')) === 'unsupported_grant_type');
$locked = false;
for ($i = 0; $i < 12 && !$locked; $i++) { // the failure limit is per IP (REMOTE_ADDR is empty in the CLI: one shared bucket)
    $locked = $call('token', ['grant_type' => 'authorization_code', 'client_id' => 'nope', 'client_secret' => 'x'], 'POST')[0] === 429;
}
$check('token: repeated client-authentication failures end in 429', $locked);

remove_dir($dataDir);
echo $fail === 0 ? "All OK\n" : "FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
