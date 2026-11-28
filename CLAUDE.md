# Google Ads MCP PHP — code conventions

Google Ads / GA4 MCP server in plain PHP 8.4 + SQLite. Target: shared cPanel hosting, deployed over FTP only —
no Composer, no framework, no background processes. Everything (code, comments, UI, tool output) is in English.

## Layout (public and private parts are separate; the release package uses one folder)
- `webroot/` — the ONLY part served over the web: `index.php` (front controller; finds the private directory), `.htaccess`,
  `.user.ini`, `assets/`. Dev: published to `/var/www/html/google-ads-php` by `bin/publish-public.sh <dir> --link`.
  Production: `domains/<domain>/public_html` (no sub-directory, the URL stays the domain root).
- Private directory = the whole repository. Dev: `/var/www/google-ads-php`. Production: `domains/<domain>/google-ads-mcp-php`
  (only `app/` and the data directory are uploaded; `tests/`, `tools/`, `bin/` stay out).
- Single-directory ("easy") layout (ADR 0008): `tools/build-release.sh` builds a flat zip/tar.gz (`index.php`, `.htaccess`, `.user.ini`, `assets/`, `app/`, `LICENSE`, docs,
  `MANIFEST.sha256`) that a non-technical person uploads into any folder. `webroot/index.php` tries `__DIR__` as the application directory LAST; `webroot/.htaccess`
  denies `app/ tools/ tests/ docs/ bin/ google-ads-mcp-php-data/` and `*.md|sql|sqlite|log|sha256|sh` (no effect in the separate layout). nginx needs equivalent rules (README, "Advanced").
- `app/`: `bootstrap.php` (constants APP_PATH, PRIVATE_PATH, PUBLIC_PATH, DATA_PATH; DATA_PATH is decided ONLY by `resolve_data_path()` there: a candidate that holds config.php/app.sqlite wins, local before outside; else an existing writable `../google-ads-mcp-php-data`; else `./google-ads-mcp-php-data`; autoload `App\X\Y` -> `app/X/Y.php`),
  `helpers.php` (config, e, url, view, send_security_headers, request_id, csrf_*, flash, json_out, admin session), `routes.php` (route table), `Controllers/` (thin; tabs Logs, Help, About = LogsController, HelpController, AboutController),
  `Auth/` (AdminAccount, LoginLinks, LinkToken), `Install/` (installer: Requirements, Manifest, ProtectionProbe, DataDir, PublicUrl, InstallCode, ConfigFile, Installer, Check, InstallError), `Lib/` (Db, Migrator, Settings, Http, Crypto, Events, Mcp, Clients, Router, McpServer,
  ToolRegistry, Mailer, RateLimiter, Health, Version, LogReader), `Google/` (Token, Ads, Ga4, Ga4Admin, GoogleApi), `Ads/` (static helpers: Money, PlNames, Status, Time,
  Slug, Errors, Table, Plural, Params; exceptions AdsApiError, MutationRefused, UserError), `Plans/` (plan building, guard,
  executor, undo), `Tools/*.php` (MCP tools), `views/`, `ui/preview.html`, `migrations/` (the only definition of the schema), `VERSION` (semver, read by `Lib\Version`).
- `google-ads-mcp-php-data/` (inside the private directory, git-ignored): `config.php` (secrets), `app.sqlite`, `logs/`, `uploads/`.
  Dev: `php tools/make-config.php`. Local, private notes: `CLAUDE.local.md` (git-ignored).

## Installer (`/setup`, ADR 0008)
`SetupController` is thin: step 1 (GET /setup, POST /setup/requirements) = `Install\Requirements` (PHP, extensions, files, MANIFEST.sha256, data directory) + `Install\ProtectionProbe`
(curls own `app/VERSION`, `app/bootstrap.php`, `tools/`, a `.probe` marker in the data directory; SSRF-guarded, only the status is shown); step 2 (POST /setup) validates, sends an SMTP test message when SMTP is
given, then `Install\Installer::run()`: config.php (0600, atomic, never overwritten) -> empty database via Db/Migrator -> admin e-mail + mail transport -> `installed.lock` (last). The admin is then logged in
(`login_admin()`); no e-mail link at install. `AdminAccount::isInstalled()` = installed.lock OR admin address in config.php/database (it does not open a missing database). `settings.mail_transport = php`
enables the PHP mail() fallback in `Lib\Mailer` (empty smtp_host). Optional `INSTALL_CODE.txt` next to index.php = code asked in step 1. Wizard routes are POST-limited by `RateLimiter` (`setup:<ip>`).

## Admin tabs and routes
Menu (views/layout.php, `aria-current` per tab): Home, Connections, Upload, Settings, Logs, Help, About. All admin-only (`require_admin()`), GET only for the last three.
- `/logs` (`LogsController` + `Lib\LogReader`): last 100 events from `DATA_PATH/logs/app-*.log`, newest first, filter `?f=all|connections|failed|logins`; reads only the file tails,
  re-masks every row with `Events::clean()`. Never print raw log lines.
- `/help` (`views/help.php`): static user guide; tool names there must exist (tests/pages.php checks); update it with any user-visible tool change.
- `/about` (`AboutController`): version from `app/VERSION`, licence read from `PRIVATE_PATH/LICENSE` (absent on production: the view falls back to a link).

## MCP tool contract
`app/Tools/<Group>.php` returns an array of definitions (the registry loads every file; no shared file to edit):
`['name','description','inputSchema'(JSON Schema),'annotations'?,'_meta'?,'handler'=>fn(array $args): array]`.
Results: `Mcp::text($s, ?$structured)`, `Mcp::json($text, $structured)`, `Mcp::error($s)`. Exceptions thrown by a handler are
caught by `McpServer` (`Errors::describe`: AdsApiError/Reauth/UserError, read/write by `annotations.readOnlyHint`) — no
custom guard needed. Read numeric/bool arguments with `Ads\Params::int/bool` (clients sometimes send strings).
Reference implementation: `Tools/Read.php` (run_gaql). Current MCP client: `Mcp::$clientId`.

## Rules
- Plain, simple PHP; small functions; comments explain assumptions, pitfalls and reasons; escape with `e()` in views;
  prepared statements only (Db); CSRF on every POST (except /token, /mcp, /plan/approve, which use their own credentials).
- Portable SQL (nothing SQLite-specific except PRAGMA); dates as TEXT ISO 8601 UTC (`now_iso()`).
- Schema changes ONLY as a new `app/migrations/NNN_name.sql` (never edit an applied one; `Lib\Migrator` backs up and runs it in a transaction).
  Table rebuilds keep rows; orphans are the only thing a migration may drop (see 002).
- Writes to Google Ads ONLY via `Ads::mutate*` (guard `App\Plans\GuardOps` — fail closed if missing).
  Dev: `auto_execute=false`, always validateOnly. No changes to a live account or production without the owner's consent.
- Secrets never go to git or logs (`Events::log` masks token/secret/password keys).
- Per-installation settings live in the `settings` table or `config.php`, never in code. `public_base_url` is required outside the CLI
  (never build links from the Host header).
- HTML and JSON responses go through `view()` / `json_out()`, which send the security headers (CSP with a nonce: inline `<script>` needs
  `nonce="<?= e(csp_nonce()) ?>"`; `frame-ancestors 'none'`). The MCP Apps window is an MCP resource, not a page of this site.
- Log with `Events::log` / `log_exception`: `?t=` tokens are masked, every line carries `request_id`. Throttle abuse with `RateLimiter`.
- Approval of plans is human-only: `POST /plan/approve` needs an admin session + link token + plan hash; `approve_plan` exists only with
  `allow_tool_approve` (ADR 0005). A read-only client (`mcp_clients.scope = ads.read`) may call only tools with `annotations.readOnlyHint`
  (a new read tool MUST set it, a new write tool MUST NOT). Plans/uploads carry the creating `client_id`; use `Mcp::canSee()` when exposing them to a client.
- Tests: `tests/installer.php` starts `php -S` servers on temporary copies of the app (the CLI server ignores .htaccess: a router script emulates the deny rules).
  `php tests/run.php` runs every offline test file in its own process on a temporary data directory (`GADS_DATA_DIR`, CLI only); a new `tests/<topic>.php`
  is picked up automatically (helpers in `tests/_env.php`). `tests/compare.php` and `tests/plans-d2.php` need the network and stay outside the runner.
  Lint: `tools/get-dev-tools.sh` fetches Pint and PHPStan phars into `tools/bin/` (git-ignored); CI runs `pint --test` and `phpstan analyse` (level 3 + baseline). Never add Composer to the app (ADR 0004, 0007).
- Release hygiene: bump `app/VERSION`, update `CHANGELOG.md` (Keep a Changelog), tag `vX.Y.Z`, build the package with `tools/build-release.sh`; record architecture decisions in `docs/adr/`.
- Deploy only with `tools/deploy.sh` (lint + tests first, previous release kept as `app.prev`, `--rollback`); never `ssl:verify-certificate no`.
- Backups: `tools/backup-sqlite.php` (cron set up by hand, see README); `GET /health` for monitoring.
