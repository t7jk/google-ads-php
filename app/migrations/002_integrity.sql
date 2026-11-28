-- Migration 002: referential integrity and CHECK constraints (audit finding 12).
-- SQLite cannot add a FOREIGN KEY or CHECK to an existing table, so each table is rebuilt: create the new table, copy
-- every row, drop the old one, rename. The runner (Lib\Migrator) turns foreign_keys OFF around the migration, runs it in
-- one transaction, verifies PRAGMA foreign_key_check before committing and takes a file backup beforehand.
-- Data policy: nothing is dropped except rows that point to a parent that no longer exists (orphans). Orphan tokens and
-- codes of a deleted client are unusable by design; orphan plan_id values in the offline log are set to NULL (the row stays).

-- ---- oauth_tokens: FK to the client, unique token hashes, indexes that cover revoked_at ----
CREATE TABLE oauth_tokens_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT NOT NULL REFERENCES mcp_clients (client_id) ON DELETE CASCADE,
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
INSERT INTO oauth_tokens_new (id, client_id, access_hash, refresh_hash, access_expires_at, refresh_expires_at, created_at, last_used_at, last_ip, user_agent, revoked_at)
    SELECT id, client_id, access_hash, refresh_hash, access_expires_at, refresh_expires_at, created_at, last_used_at, last_ip, user_agent, revoked_at
    FROM oauth_tokens WHERE client_id IN (SELECT client_id FROM mcp_clients);
DROP TABLE oauth_tokens;
ALTER TABLE oauth_tokens_new RENAME TO oauth_tokens;
CREATE UNIQUE INDEX idx_oauth_tokens_access ON oauth_tokens (access_hash);
CREATE UNIQUE INDEX idx_oauth_tokens_refresh ON oauth_tokens (refresh_hash);
CREATE INDEX idx_oauth_tokens_client ON oauth_tokens (client_id, revoked_at);
CREATE INDEX idx_oauth_tokens_expiry ON oauth_tokens (refresh_expires_at);
CREATE INDEX idx_oauth_tokens_revoked ON oauth_tokens (revoked_at);

-- ---- oauth_codes: FK to the client (codes live 5 minutes) ----
CREATE TABLE oauth_codes_new (
    code TEXT PRIMARY KEY,              -- sha256 of the code
    client_id TEXT NOT NULL REFERENCES mcp_clients (client_id) ON DELETE CASCADE,
    redirect_uri TEXT NOT NULL,
    code_challenge TEXT NOT NULL,
    expires_at TEXT NOT NULL
);
INSERT INTO oauth_codes_new (code, client_id, redirect_uri, code_challenge, expires_at)
    SELECT code, client_id, redirect_uri, code_challenge, expires_at
    FROM oauth_codes WHERE client_id IN (SELECT client_id FROM mcp_clients);
DROP TABLE oauth_codes;
ALTER TABLE oauth_codes_new RENAME TO oauth_codes;

-- ---- plans: CHECK on the status (the values the code uses: PlanStore, Executor) ----
CREATE TABLE plans_new (
    id TEXT PRIMARY KEY,
    tool TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('pending', 'executing', 'done', 'failed', 'refused')),
    payload_json TEXT NOT NULL,
    preview_json TEXT,
    result_json TEXT,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    executed_at TEXT
);
INSERT INTO plans_new (id, tool, status, payload_json, preview_json, result_json, created_at, expires_at, executed_at)
    SELECT id, tool, status, payload_json, preview_json, result_json, created_at, expires_at, executed_at FROM plans;
DROP TABLE plans;
ALTER TABLE plans_new RENAME TO plans;
CREATE INDEX idx_plans_created ON plans (created_at);
CREATE INDEX idx_plans_status ON plans (status);

-- ---- snapshots: belong to a plan and go away with it (PlanStore::prune deletes old plans) ----
CREATE TABLE snapshots_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plan_id TEXT NOT NULL REFERENCES plans (id) ON DELETE CASCADE,
    data_json TEXT NOT NULL,
    created_at TEXT NOT NULL
);
INSERT INTO snapshots_new (id, plan_id, data_json, created_at)
    SELECT id, plan_id, data_json, created_at FROM snapshots WHERE plan_id IN (SELECT id FROM plans);
DROP TABLE snapshots;
ALTER TABLE snapshots_new RENAME TO snapshots;
CREATE INDEX idx_snapshots_plan ON snapshots (plan_id);

-- ---- offline_log: CHECK on result, FK to the plan (SET NULL: this log is the idempotency record and must outlive pruned plans) ----
-- Before the unique index below: an order id logged as 'ok' more than once keeps its first 'ok'; later ones become
-- 'duplicate' (the code treats 'duplicate' as "already sent" as well, so behaviour does not change and no row is deleted).
UPDATE offline_log SET result = 'duplicate'
    WHERE result = 'ok' AND id NOT IN (SELECT MIN(id) FROM offline_log WHERE result = 'ok' GROUP BY order_id);
CREATE TABLE offline_log_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id TEXT NOT NULL,
    ts TEXT NOT NULL,
    result TEXT NOT NULL CHECK (result IN ('ok', 'duplicate', 'error')),
    code TEXT,                          -- Google error code when result != 'ok'
    plan_id TEXT REFERENCES plans (id) ON DELETE SET NULL
);
INSERT INTO offline_log_new (id, order_id, ts, result, code, plan_id)
    SELECT id, order_id, ts, result, code, CASE WHEN plan_id IN (SELECT id FROM plans) THEN plan_id ELSE NULL END FROM offline_log;
DROP TABLE offline_log;
ALTER TABLE offline_log_new RENAME TO offline_log;
CREATE INDEX idx_offline_log_order ON offline_log (order_id);
-- Partial unique index: one successful upload per order id, enforced by the database (two parallel sends cannot both win).
-- Retries after an 'error' stay possible. PostgreSQL supports this as is; MySQL needs a generated column instead.
CREATE UNIQUE INDEX idx_offline_log_order_ok ON offline_log (order_id) WHERE result = 'ok';

-- ---- uploads: CHECK on kind ----
CREATE TABLE uploads_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL CHECK (kind IN ('image', 'youtube')),
    path_or_url TEXT NOT NULL,
    sha256 TEXT,
    mime TEXT,
    width INTEGER,
    height INTEGER,
    created_at TEXT NOT NULL,
    meta_json TEXT
);
INSERT INTO uploads_new (id, kind, path_or_url, sha256, mime, width, height, created_at, meta_json)
    SELECT id, kind, path_or_url, sha256, mime, width, height, created_at, meta_json FROM uploads;
DROP TABLE uploads;
ALTER TABLE uploads_new RENAME TO uploads;

-- ---- login_links: CHECK on purpose ----
CREATE TABLE login_links_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash TEXT NOT NULL UNIQUE,
    purpose TEXT NOT NULL CHECK (purpose IN ('login', 'email_change')),
    email TEXT NOT NULL,
    ip TEXT,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT
);
INSERT INTO login_links_new (id, token_hash, purpose, email, ip, created_at, expires_at, used_at)
    SELECT id, token_hash, purpose, email, ip, created_at, expires_at, used_at FROM login_links;
DROP TABLE login_links;
ALTER TABLE login_links_new RENAME TO login_links;
CREATE INDEX idx_login_links_created ON login_links (created_at);
