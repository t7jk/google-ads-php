<?php
// Small global functions used in controllers and views. Rule: only what is actually shared.
declare(strict_types=1);

/** Setting from DATA_PATH/config.php (see config.example.php). Missing key -> $default. */
function config(string $key, mixed $default = null): mixed
{
    return config_all()[$key] ?? $default;
}

/** The whole configuration, read once per request. $reload = true re-reads the file (the installer has just written it). */
function config_all(bool $reload = false): array
{
    static $cfg = null;
    if ($cfg === null || $reload) {
        $file = DATA_PATH . '/config.php';
        if ($reload && function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        $cfg = is_file($file) ? (array) require $file : [];
    }
    return $cfg;
}

/** HTML escaping — every dynamic value in a view goes through e(). */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Path of the subdirectory where index.php lives ('' at a domain root, '/google-ads-php' on dev). */
function base_path(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
}

/** Relative link to an app resource (for href/action/redirect). */
function url(string $path = '/'): string
{
    return base_path() . $path;
}

/**
 * Full public URL without a trailing "/" (OAuth, metadata, links in e-mails and MCP responses).
 * Outside the CLI `public_base_url` is mandatory: the Host header is attacker-controlled, so a link built from it
 * (e.g. a login link in an e-mail) could be redirected to a hostile server (host-header poisoning).
 * The CLI (tests, tools) has no Host header and falls back to localhost.
 */
function base_url(): string
{
    $configured = (string) config('public_base_url', '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    if (PHP_SAPI !== 'cli') {
        throw new \RuntimeException("Configuration error: 'public_base_url' is not set in config.php (see config.example.php).");
    }
    return 'http://localhost' . base_path();
}

function now_iso(int $offsetSeconds = 0): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() + $offsetSeconds);
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function json_out(mixed $data, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    send_security_headers(false);
    foreach ($headers as $name => $value) {
        header("$name: $value");
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Renders views/$name.php inside the layout (layout.php). Variables from $data are available in the view. */
function view(string $name, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    send_security_headers(true);
    $content = (function () use ($name, $data): string {
        extract($data, EXTR_SKIP);
        ob_start();
        require APP_PATH . '/views/' . $name . '.php';
        return (string) ob_get_clean();
    })();
    $title = $data['title'] ?? 'Google Ads MCP PHP';
    require APP_PATH . '/views/layout.php';
    exit;
}

// ---- Security headers ----

/** Whether a response header was already set in this request (a controller may set a stricter or more specific one). */
function header_is_set(string $name): bool
{
    foreach (headers_list() as $line) {
        if (strncasecmp($line, $name . ':', strlen($name) + 1) === 0) {
            return true;
        }
    }
    return false;
}

/** Per-request nonce for inline <script> blocks allowed by the Content-Security-Policy of HTML pages. */
function csp_nonce(): string
{
    static $nonce = null;
    return $nonce ??= bin2hex(random_bytes(16));
}

/** Content-Security-Policy: pages are self-contained (own CSS, nonce'd inline scripts); thumbnails come from our own host and YouTube. */
function csp_for_html(): string
{
    return "default-src 'self'; script-src 'nonce-" . csp_nonce() . "'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' https://i.ytimg.com; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'";
}

/**
 * The one place that sends security headers; called by view() (HTML) and json_out() (JSON).
 * A header that a controller already set (e.g. the stricter CSP of the plan page) is left alone.
 * Framing: the MCP Apps preview window is not a page of this site: it is delivered as an MCP resource
 * (Lib\McpServer, ui/preview.html) and rendered by the client, so every page we serve may forbid framing.
 * HSTS only over HTTPS: on plain HTTP (dev) browsers would ignore it anyway, and it must not be advertised on a host
 * that cannot serve HTTPS.
 */
function send_security_headers(bool $html): void
{
    $headers = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer', // tokens in URLs (/plan, /upload, /login/verify) do not leak via Referer
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => $html ? csp_for_html() : "default-src 'none'; frame-ancestors 'none'",
    ];
    if (request_is_https()) {
        $headers['Strict-Transport-Security'] = 'max-age=31536000';
    }
    foreach ($headers as $name => $value) {
        if (!header_is_set($name)) {
            header("$name: $value");
        }
    }
}

function request_is_https(): bool
{
    return ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
}

// ---- Admin session and CSRF ----

/** The session starts only when needed (not for /mcp, /token, etc.). */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = request_is_https();
    ini_set('session.use_strict_mode', '1'); // rejects session identifiers supplied by the client
    session_name('gadsmcp');
    session_set_cookie_params([
        'path' => base_path() . '/',
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Admin session limits: inactivity and maximum time since login (seconds). */
const ADMIN_IDLE_TTL = 2 * 3600;
const ADMIN_MAX_TTL = 12 * 3600;

/** The admin session is valid when the limits have not been exceeded; also refreshes the activity time. */
function is_admin(): bool
{
    session_boot();
    if (empty($_SESSION['admin'])) {
        return false;
    }
    $now = time();
    if ($now - (int) ($_SESSION['admin_seen'] ?? 0) > ADMIN_IDLE_TTL || $now - (int) ($_SESSION['admin_since'] ?? 0) > ADMIN_MAX_TTL) {
        logout_admin();
        flash('error', 'Your session has expired. Log in again — we will send a new link.');
        return false;
    }
    $_SESSION['admin_seen'] = $now;
    return true;
}

/** Establishes the admin session (a new session id protects against session fixation). */
function login_admin(): void
{
    session_boot();
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['admin_since'] = $_SESSION['admin_seen'] = time();
    unset($_SESSION['login_sent_to']);
}

/** Ends the admin session, leaving an empty session (for the flash message and a new CSRF token). */
function logout_admin(): void
{
    session_boot();
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Admin pages: a user who is not logged in goes back to the home page. */
function require_admin(): void
{
    if (!\App\Auth\AdminAccount::isInstalled()) {
        redirect('/setup'); // clean install: the wizard first
    }
    if (!is_admin()) {
        redirect('/');
    }
}

function csrf_token(): string
{
    session_boot();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    session_boot();
    $sent = (string) ($_POST['_csrf'] ?? '');
    return $sent !== '' && hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent);
}

/** A one-time message shown after a redirect: flash('ok', '...') / flash('error', '...'). */
function flash(string $type, string $message): void
{
    session_boot();
    $_SESSION['flash'][] = [$type, $message];
}

function flash_take(): array
{
    session_boot();
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** A POST field as a trimmed string. */
function post(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

/** Identifier of this request (16 hex chars) for correlating log lines, events and the X-Request-Id response header. */
function request_id(): string
{
    static $id = null;
    return $id ??= bin2hex(random_bytes(8));
}

/**
 * Masks one-time tokens in a URL or text before it is logged: the `t` (login, upload and plan links), `token` and `code`
 * query parameters stay valid for minutes to 24 h, so a log line must not carry them.
 */
function mask_url_secrets(string $text): string
{
    return (string) preg_replace('/([?&](?:t|token|code)=)[^&#\s]*/i', '$1***', $text);
}

/**
 * Makes sure the PHP error log exists with mode 0660 (the web server group may write, others may not read).
 * umask(0002) alone would create it as 0664 and PHP creates it lazily, so we create it up front.
 */
function secure_error_log(string $file): void
{
    if (!is_file($file) && is_dir(dirname($file))) {
        @touch($file);
    }
    if (is_file($file) && (fileperms($file) & 0777) !== 0660) {
        @chmod($file, 0660); // fails silently when the file belongs to another user; the permissions then stay as they are
    }
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Writes the exception to DATA_PATH/php-error.log (with location and a short trace) and to the events table (type 'error').
 * Used by the global handler (bootstrap) and McpServer. The error also lands in logs/app-*.log (via Events).
 */
function log_exception(\Throwable $e, string $context = ''): void
{
    $line = sprintf('[%s] [%s] %s%s: %s in %s:%d', gmdate('Y-m-d H:i:s'), request_id(),
        $context !== '' ? mask_url_secrets($context) . ' - ' : '', $e::class, mask_url_secrets($e->getMessage()), $e->getFile(), $e->getLine());
    error_log($line . "\n" . implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 8)));
    \App\Lib\Events::log('error', ['context' => $context, 'class' => $e::class, 'message' => $e->getMessage(),
        'where' => basename($e->getFile()) . ':' . $e->getLine(), 'path' => mask_url_secrets($_SERVER['REQUEST_URI'] ?? 'cli')]);
}
