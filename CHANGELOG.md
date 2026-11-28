# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Installer for non-technical people** (0.6.0, ADR 0008): upload the release package into any folder, open its address and `/setup` leads through
  *Requirements* (PHP 8.4, extensions, files verified against `MANIFEST.sha256`, data directory, a self-test that private files cannot be downloaded) and
  *Administrator* (e-mail, confirmed public address, optional SMTP with a test message). It writes `config.php` (0600, never overwritten), creates the empty
  database with all migrations, logs the administrator in at once and locks itself (`installed.lock`). Optional `INSTALL_CODE.txt`. Code: `app/Install/`.
- `tools/build-release.sh`: flat `.zip`/`.tar.gz` package (index.php, .htaccess, .user.ini, assets/, app/, docs, `MANIFEST.sha256`).
- Single-directory layout: `webroot/index.php` also looks for `app/` next to itself (tried last); `webroot/.htaccess` denies `app/`, `tools/`, `tests/`, `docs/`, `bin/`,
  `google-ads-mcp-php-data/` and `*.md|sql|sqlite|log|sha256|sh`. The data directory may live next to the installation folder (`../google-ads-mcp-php-data`, preferred when
  it is outside the document root): `resolve_data_path()` in `bootstrap.php` is the one place that decides.
- PHP `mail()` fallback in `Lib\Mailer` when no SMTP host is set (`settings.mail_transport = php`, enabled by the installer): UTF-8, RFC 2047 subject, header-injection checks.
  The home page warns that it is not trusted. `tests/installer.php` (84 checks, including real web flows against `php -S`).
- Admin tabs after Settings: **Logs** (`/logs`: newest 100 application log events, newest first, masked, filters All / Connections / Failed / Logins; `Lib\LogReader`),
  **Help** (`/help`: plain-language user guide with examples and scenarios) and **About** (`/about`: author, links, disclaimer, version, MIT licence text). `Events::clean()` is now public.
  Tests: `tests/logs.php`, `tests/pages.php`.
- Versioned schema migrations (`app/migrations/NNN_name.sql`, table `schema_migrations`, runner `Lib/Migrator`): applied once, each in
  a transaction, with a file backup (`data/backups/app-premigrate-*.sqlite`) first. `app/schema.sql` is gone: the migrations are the only definition.
  001 baseline (idempotent for existing databases), 002 foreign keys and CHECK constraints (plan status, upload kind, login purpose, offline log result)
  and a unique successful upload per order id, 003 owner `client_id` on plans/uploads and `scope` per MCP client, 004 refresh-token reuse tracking and login return path.
- Plan approval is a human act (ADR 0005): the `approve_plan` tool is off unless `allow_tool_approve` is true, and `POST /plan/approve`
  needs an admin session in addition to the link token and plan hash. The plan page offers an e-mail login that returns to the plan.
- Static (permanent) client tokens expire after `static_token_ttl_days` (default 180) and can be renewed in Connections; connections
  unused for 30+ days are flagged. Access per client: read-only (`ads.read`) or read and write (`ads.write`, the default for existing clients).
- Plans, uploads and events are attributed to the MCP client that created them; `show_plan`, `prepare_undo`, `recent_uploads` and `server_events`
  show a client its own records and legacy ones without an owner (ADR 0006 describes the path to real multi-tenancy, not implemented).
- Link keys: upload and plan links are signed with a key derived separately from `secret_key`, with a `v<N>` key version in the token
  (`link_key_version`, `link_key_min_version`); upload links carry the requesting client. Tests: `tests/migrations.php`, `tests/stage2-security.php`, run on temporary databases (`GADS_DATA_DIR`, CLI only).
- Refresh-token reuse detection: presenting a replaced refresh token revokes that connection (after a 30 s grace for honest retries).
- `GET /health` endpoint (database, writable data directory, `secret_key`, Google token, version).
- `tools/backup-sqlite.php`: consistent SQLite backup (`VACUUM INTO`) with retention.
- Rate limiting (`Lib/RateLimiter`) for `/mcp`, `/token` and `/authorize`; limits for MCP batch size and request body size.
- Shared security headers (CSP, `frame-ancestors`, `Permissions-Policy`, HSTS on HTTPS) for HTML and JSON responses.
- `app/VERSION` file (read by `McpServer` and `/health`), this changelog and architecture decision records in `docs/adr/`.
- Request id in logs and in the `X-Request-Id` response header.
- Deploy: pre-flight lint and tests, previous release kept as `app.prev`, `/health` smoke test, `--rollback`.

- Offline test runner `tests/run.php` (every test file in its own process on a temporary data directory) and new security tests: login
  links (one time, TTL), OAuth authorization code with PKCE, redirect_uri rules, SSRF guards (host allow-list, private, IPv4-mapped and
  link-local addresses, redirect targets), CSRF in the router, secret masking in the event log, rate limiter. `tests/plans-executor.php` no longer writes to the dev database.
- Pint (`pint.json`, PSR-12 with the repo's conventions) and PHPStan (`phpstan.neon`, level 3 with a baseline) configuration, `tools/get-dev-tools.sh`
  (phars in `tools/bin/`); CI runs syntax check, tests, PHPStan and Pint. ADR 0007.

### Changed
- `config()` can be reloaded (`config_all(true)`); `AdminAccount::isInstalled()` no longer creates the database when it does not exist yet.

### Fixed
- Loopback `redirect_uri` validation accepted a URL with only user-info or only a fragment (`isset()` with several arguments needs all of them);
  a Bearer-token client no longer matches the marker value `bearer` as a redirect URL.

### Changed
- Deploy pre-flight runs the whole offline suite (`tests/run.php`) instead of one file.
- Code style normalised with Pint (no behaviour change).
- Upload links are valid for 2 hours (was 24 h). Links issued by 0.2.x keep working until they expire (at most 24 h).
- Login throttling counts every attempt per IP (known or unknown address, same answer) with a higher global ceiling on e-mails sent, so
  distributed requests can no longer lock the administrator out as easily.
- `last_used_at` of a connection is written at most once a minute.
- `public_base_url` is required outside the CLI; the `Host` header is never used to build links.
- Deploy verifies the FTPS certificate by default (system CA, optional CA file or fingerprint pin).
- Query tokens (`?t=`) are masked in logs; `php-error.log` is trimmed; `login-link.txt` is deleted after the link is used.
- The one-time client secret is shown in the response of the form POST instead of being stored in the session.
- Outgoing Google API requests are HTTPS-only with a response size limit.
- The remaining Polish messages are now English.

## [0.1.0]

### Added
- Initial release: Google Ads and GA4 MCP server with plan-based changes, admin panel, OAuth 2.1 for MCP clients.
