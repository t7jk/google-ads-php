-- Migration 001: baseline schema (the structure the application had before migrations were introduced).
-- Idempotent on purpose: production databases already contain these tables, so every statement is IF NOT EXISTS and
-- an existing database only gets the version row. Never edit an applied migration: add a new one.
-- Portability: SQLite today; INTEGER/TEXT types, dates as ISO 8601 UTC TEXT. Moving to MySQL/PostgreSQL needs only the
-- auto-increment spelling (AUTOINCREMENT) of the id columns adjusted.

CREATE TABLE IF NOT EXISTS mcp_clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    secret_hash TEXT NOT NULL,          -- sha256 of the secret (the secret has 256 bits of entropy, so a fast hash is enough)
    redirect_uris TEXT NOT NULL,        -- exact URL (claude-ai) or the word "loopback" (claude-code: localhost/127.0.0.1, any port)
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS oauth_codes (
    code TEXT PRIMARY KEY,              -- sha256 of the code
    client_id TEXT NOT NULL,
    redirect_uri TEXT NOT NULL,
    code_challenge TEXT NOT NULL,
    expires_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS oauth_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT NOT NULL,
    access_hash TEXT NOT NULL,
    refresh_hash TEXT NOT NULL,
    access_expires_at TEXT NOT NULL,
    refresh_expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_used_at TEXT,
    last_ip TEXT,
    user_agent TEXT,
    revoked_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_oauth_tokens_access ON oauth_tokens (access_hash);
CREATE INDEX IF NOT EXISTS idx_oauth_tokens_refresh ON oauth_tokens (refresh_hash);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS uploads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,                 -- 'image' | 'youtube'
    path_or_url TEXT NOT NULL,
    sha256 TEXT,
    mime TEXT,
    width INTEGER,
    height INTEGER,
    created_at TEXT NOT NULL,
    meta_json TEXT                      -- batch, file name, PMax type description; for videos: title, channel, duration (added by Media\UploadLog)
);

CREATE TABLE IF NOT EXISTS plans (
    id TEXT PRIMARY KEY,
    tool TEXT NOT NULL,
    status TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    preview_json TEXT,
    result_json TEXT,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    executed_at TEXT
);

CREATE TABLE IF NOT EXISTS snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plan_id TEXT NOT NULL,
    data_json TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_snapshots_plan ON snapshots (plan_id);

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts TEXT NOT NULL,
    type TEXT NOT NULL,
    data_json TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_events_ts ON events (ts);

-- Change plans: queries by date (the undo list, cleanup) and status.
CREATE INDEX IF NOT EXISTS idx_plans_created ON plans (created_at);
CREATE INDEX IF NOT EXISTS idx_plans_status ON plans (status);

-- Log of offline conversions sent (so the same orderId is not sent twice). No click identifiers.
CREATE TABLE IF NOT EXISTS offline_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id TEXT NOT NULL,
    ts TEXT NOT NULL,
    result TEXT NOT NULL,               -- 'ok' | 'duplicate' | 'error'
    code TEXT,                          -- Google error code when result != 'ok'
    plan_id TEXT
);
CREATE INDEX IF NOT EXISTS idx_offline_log_order ON offline_log (order_id);

-- One-time e-mail links (passwordless login and confirmation of an admin address change).
-- Only the token's SHA-256 is stored: a database leak yields no working links. used_at != NULL = the link was used.
CREATE TABLE IF NOT EXISTS login_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash TEXT NOT NULL UNIQUE,
    purpose TEXT NOT NULL,              -- 'login' | 'email_change'
    email TEXT NOT NULL,                -- the address the link was sent to
    ip TEXT,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_login_links_created ON login_links (created_at);

-- Fixed-window request counters (Lib\RateLimiter): one row per bucket, e.g. "client_fail:203.0.113.5" or "mcp:claude-ai-1a2b".
-- Expired rows are deleted on request (no cron). Nothing here is personal data beyond an IP address, kept for minutes.
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket TEXT PRIMARY KEY,
    hits INTEGER NOT NULL,
    window_ends_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rate_limits_window ON rate_limits (window_ends_at);
