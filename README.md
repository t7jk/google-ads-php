# Google Ads MCP Server (PHP)

A self-hosted **Model Context Protocol (MCP) server for Google Ads and Google Analytics 4**, written in plain PHP 8.4 + SQLite.
Connect it to Claude (claude.ai or Claude Code) and manage your ad account in plain language: ask for reports, find wasted
spend, and prepare campaign changes that you review and approve before anything is written to Google Ads.

It is built to run on **cheap shared hosting** (cPanel, Apache, FTP only): no Composer, no framework, no background
processes, no build step. Upload the files, open the site, follow the installer.

## Features

- **Reports (read-only):** account overview, campaigns, campaign details and health check, search terms, Performance Max
  report, ads report, change history, conversion actions and tags, keyword ideas, raw GAQL (`run_gaql`), GA4 reports,
  key events, data streams and Ads links.
- **Safe changes via plans:** every `prepare_*` tool (campaign changes, new campaign, pause, settings, negative keywords,
  account exclusions, conversion goals/actions, asset groups, assets, audience signals, custom audiences, GA4 key events,
  offline conversions, undo) first builds a **plan**, validates it with Google Ads `validateOnly`, and shows a preview.
  Nothing changes until the plan is approved (or `auto_execute` is explicitly enabled).
- **Guard rails:** all writes go through a single guarded path (fail-closed), a maximum daily budget limit, allowed
  landing-page hosts, and an undo journal.
- **Uploads:** images and YouTube videos for campaigns, via one-time links or the admin panel.
- **Admin panel:** passwordless login (one-time e-mail link), settings (admin e-mail, SMTP), Google OAuth connection and
  MCP client management (OAuth 2.1 with PKCE, or a static Bearer token for Claude Code).
- **Logs, Help, About tabs:** *Logs* shows the newest 100 events of `logs/app-YYYY-MM-DD.log` (read-only, secrets masked, filters All / Connections / Failed /
  Logins via `?f=`); *Help* is a plain-language user guide; *About* shows the author, disclaimer, version and the licence text read from `LICENSE`
  (not uploaded by `tools/deploy.sh`, so on production a short note with a link to the repository is shown instead).

## Requirements

- PHP 8.4 with `sodium`, `pdo_sqlite`, `curl`, `mbstring`, `openssl`, `ctype`, `json` (standard on most hosts; the installer checks all of them)
- Apache with `.htaccess` (`mod_rewrite`); nginx works too with a few extra rules (see "Advanced" below)
- Recommended: an SMTP mailbox for login e-mails (without it PHP `mail()` is used, which we do not recommend)
- Later, for the actual work: a Google Cloud project with an OAuth client, and Google Ads API access for your account

## Installation (4 steps, no technical knowledge needed)

1. **Download** the release package (`google-ads-mcp-php-<version>.zip`) and unzip it on your computer, or upload the zip and use
   "Extract" in your hosting's file manager. (Maintainers build it with `tools/build-release.sh`.)
2. **Upload everything** into a folder of your choice on your web hosting, for example `public_html/ads`, with FTP or the file
   manager. Include the hidden files (`.htaccess`, `.user.ini`). With FTP choose **binary** transfer mode.
3. **Open the address** of that folder in your browser, e.g. `https://your-site.com/ads`. The installer starts by itself.
4. **Follow the installer.** *Step 1* checks your server and the uploaded files and tells you what to fix, in plain words.
   *Step 2* asks for your e-mail address (you become the administrator), confirms the public address of the site and, optionally,
   your mail server (SMTP). Press *Install*: the installer creates the settings file and an empty database and logs you in.
   Then open **Connections** to connect your Google account and add an MCP client (claude.ai or Claude Code); **Help** explains each step.

> **Finish the installation right away.** Until it is done, anyone who knows the address could register as the administrator
> (there is no e-mail check during installation). To make that impossible, upload a file `INSTALL_CODE.txt` containing one line
> (any secret code) next to `index.php` before you open the address: the installer then asks for that code before step 2.
> Without the file there is no code.

**E-mail.** Login after the installation works with one-time links sent to your e-mail address, so e-mail must work. Enter your SMTP
details in step 2 (or later in **Settings**): the installer then sends a test message and refuses to continue if it fails.
If you leave SMTP empty, PHP's built-in `mail()` is used. **PHP `mail()` is not trusted** (messages are often lost or marked as
spam), so we recommend configuring SMTP in Settings. If a login e-mail cannot be sent, the link is written to
`google-ads-mcp-php-data/logs/login-link.txt` (reachable over FTP only).

**Where your data lives.** Settings, the database and logs go to a folder `google-ads-mcp-php-data`. If your hosting lets the installer create
it **above** the public folder (next to it), it uses that: the web server then cannot serve it at all. Otherwise the folder is inside the
installation folder and is protected by `.htaccess`; the installer tests that by trying to download its own private files, and stops
if they can be downloaded. **Back up this folder** (and keep `config.php` safe: it holds the key that encrypts your stored secrets).

**Starting over.** The installer closes itself for good by writing `google-ads-mcp-php-data/installed.lock`. To run it again on an
empty installation, delete that file (and the other files in the data folder if you want a clean start).

## Advanced: separate public/private layout

The installer above uses a single folder. For a hardened setup (and for development) keep the code out of the web root:

1. Upload the contents of `webroot/` to your domain's web root (`public_html/`).
2. Create a private directory **next to** the web root (e.g. `google-ads-mcp-php/`) and upload `app/` into it; the data directory
   `google-ads-mcp-php-data/` lives inside it. `webroot/index.php` finds the private directory: `APP_PRIVATE_DIR` (environment),
   `../google-ads-mcp-php`, `../../google-ads-php`, and finally its own directory (the single-folder layout, tried last).
3. Open the site: the same installer runs (`/setup`). Alternatively create `google-ads-mcp-php-data/config.php` by hand from
   `app/config.example.php` (at least `public_base_url`, `secret_key` = `openssl rand -base64 32`, `admin_email`): with an admin address in
   `config.php` the installer is skipped.
4. `DATA_PATH` is chosen in one place (`resolve_data_path()` in `app/bootstrap.php`): a data directory that already holds `config.php` or
   `app.sqlite` wins (local first), else an existing writable `../google-ads-mcp-php-data`, else the local one.

**nginx** ignores `.htaccess`. In the single-folder layout add (adjust the path prefix of your sub-folder): `location ~ ^/(app|tools|tests|docs|bin|google-ads-mcp-php-data)/ { deny all; }`,
`location ~* \.(md|sql|sqlite3?|log|sha256)$ { deny all; }` and `location = /INSTALL_CODE.txt { deny all; }`, plus the usual `try_files $uri /index.php?$query_string;`.
The installer checks this by trying to download the files, so you will see if a rule is missing.

## First run

After the installer you are logged in as the administrator. Later logins are passwordless: enter the admin e-mail, open the one-time link
(valid for 15 minutes) and confirm with the button. If the mail server is down, an emergency link is written to `logs/login-link.txt` in
the data directory (reachable only via FTP/SSH). The installer is closed after installation: `/setup` redirects to the home page.

## Connecting Google and Claude

1. **Connections → Google account:** in Google Cloud Console add the redirect URI
   `{public_base_url}/oauth/google/callback` to your OAuth client, then click *Connect with Google* and sign in with the
   account set in `allowed_email`. Grant all requested permissions (Google Ads and Analytics).
2. **Connections → MCP clients:** add a client for claude.ai or Claude Code. The page shows the exact steps
   (and a ready-to-paste `claude mcp add …` command). The MCP endpoint is `{public_base_url}/mcp`.

## Security

- Writes to Google Ads go only through `Ads::mutate*`, guarded by `App\Plans\GuardOps` (fail-closed if missing).
- Keep `auto_execute => false` unless you really want plans executed without approval; always on dev.
- Approving a plan is a human act: it needs the administrator logged in (e-mail link) on the plan page; the `approve_plan` MCP tool is
  off unless `allow_tool_approve` is `true` (see `docs/adr/0005-zatwierdzanie-planow.md`).
- MCP clients have an access level (read-only or read and write); permanent tokens (client type `claude-code-token`) expire after
  `static_token_ttl_days` (180) and are renewed in Connections. A client sees its own plans, uploads and events (plus older ones without an owner).
- Upload links last 2 hours; link signing keys are rotated with `link_key_version` without touching encrypted secrets.
- Secrets in the database are encrypted with `secret_key`; the event log masks tokens and passwords.
- `config.php` and the data directory must never be in the web root or in git.
- Outgoing fetches (images, landing pages) are HTTPS-only, limited to allowed hosts, and reject private, link-local and IPv4-mapped IPv6 ranges.
- Every response carries security headers (CSP, `frame-ancestors`, `Permissions-Policy`, HSTS on HTTPS); see `send_security_headers()` in `app/helpers.php`.
- Rate limits (`app/Lib/RateLimiter.php`, table `rate_limits`): `/mcp` 300 requests per minute per client, at most 20 requests in a batch,
  body up to 8 MiB; an IP is locked out for 15 minutes after 10 failed client authentications at `/token` or `/authorize` (HTTP 429).
- Logs never contain `?t=` link tokens; each request has an id (`X-Request-Id` header, `request_id` in `logs/app-*.log` and `php-error.log`).
  `php-error.log` is trimmed automatically above 1 MiB and `logs/login-link.txt` is deleted once a login link is used.

Found a vulnerability? Please open a private security advisory on GitHub instead of a public issue.

## Database migrations

The schema is defined only by `app/migrations/NNN_name.sql`, applied automatically (once) by the first request after a deploy, in order,
each in a transaction. Before the first pending migration the database is copied to `google-ads-mcp-php-data/backups/` (the newest 5
copies are kept); if anything fails the migration is rolled back and the error is logged. Never edit an applied migration: add a new one.
To try a migration on a copy of a real database, point the CLI at a temporary directory: `GADS_DATA_DIR=/tmp/copy php -r 'require "app/bootstrap.php"; App\\Lib\\Db::pdo();'` (the variable is honoured in the CLI only). `php tests/migrations.php` checks the migrations on throwaway databases.

## Health check and monitoring

`GET /health` returns JSON (`status`: `ok`, `degraded` or `error`, `version`, and the checks `database`, `data_dir_writable`,
`secret_key`, `google_token`). HTTP 503 means the server cannot work; `degraded` (HTTP 200) means it runs but the Google account is
not connected. Point an external uptime monitor (e.g. UptimeRobot) at it and alert on non-200 and on the word `error`/`degraded`.

## Backups

`tools/backup-sqlite.php` writes a consistent copy of `app.sqlite` (`VACUUM INTO`, integrity-checked) to a directory outside the web
root and keeps the newest N copies: `php tools/backup-sqlite.php [--dir=/path] [--keep=14]`. The default directory is
`<private dir>-backups`, next to the private directory. Deploys upload the script to `<private dir>/tools/`.
The backup holds encrypted secrets; `config.php` (with `secret_key`) is not included, so back it up separately and keep both off the server too.

Example cron job (cPanel, Cron Jobs; set up by hand, the repository does not install it), daily at 03:15:

    15 3 * * * /usr/local/bin/php /home/USER/domains/EXAMPLE.COM/google-ads-mcp-php/tools/backup-sqlite.php --keep=14

Download the backups over FTP/SFTP regularly and test a restore now and then: stop traffic, copy a backup over
`google-ads-mcp-php-data/app.sqlite` and delete `app.sqlite-wal` and `app.sqlite-shm`.

## Releases

Versions follow [Semantic Versioning](https://semver.org/). The version lives in `app/VERSION` (shown by `/health` and the MCP
`initialize` response); changes are listed in [CHANGELOG.md](CHANGELOG.md). To release: move the `[Unreleased]` entries under the new
version in the changelog, set `app/VERSION`, commit, `git tag vX.Y.Z`, then deploy. For people who install by themselves,
`tools/build-release.sh` builds `dist/google-ads-mcp-php-<version>.zip` / `.tar.gz` (single-folder layout, with `MANIFEST.sha256` that the installer verifies). Architecture decisions: [docs/adr/](docs/adr/).

## Development

- Keep the repository outside the web root and publish only `webroot/`: `bin/publish-public.sh /var/www/html/google-ads-php --link`.
- `php tools/make-config.php` creates a dev config, `php tools/call.php <tool> '<json>'` calls a tool without HTTP,
  `php tests/run.php` runs the offline tests (see "Tests and code quality" below).
- Deployment over FTP: `tools/deploy.sh` (settings in `tools/deploy.env`, see `tools/deploy.env.example`; needs `lftp`, `php`, `curl`, `openssl`).
  It checks syntax and tests first, uploads the new `app/` as `app.new`, keeps the previous release as `app.prev` on the server, mirrors
  `webroot/` and smoke-tests `/` and `/health`. The FTPS certificate is verified; configure a CA file or a pinned fingerprint in
  `deploy.env` when the host's certificate is not signed by a public CA. The whole `app/` is replaced on each deploy, so files deleted from
  the repository disappear from the server (`webroot/` is mirrored without deleting).
- **Rollback:** `tools/deploy.sh --rollback` swaps `app/` and `app.prev/` back (run it again to undo the rollback). The database is not
  touched by deploys or rollbacks; `webroot/` is not rolled back (it rarely changes; if it did, deploy the previous tag).
  Manual rollback over FTP: rename `app` to `app.bad` and `app.prev` to `app`.
- Code conventions and architecture: [CLAUDE.md](CLAUDE.md).

### Tests and code quality

The application has no Composer dependencies (ADR 0004); the tools below run on a developer machine and in CI only.

- `php tests/run.php` runs every offline test (`tests/installer.php` also starts `php -S` servers on copies of the app): each file `tests/<name>.php` in its own PHP process with a throwaway data
  directory (`GADS_DATA_DIR`, removed afterwards), so the dev database is never touched. It prints one line per file and a
  total, and exits non-zero on any failure or when a file prints no checks. `php tests/run.php oauth ssrf` runs only the
  files whose names contain one of the words. A single file also runs on its own: `php tests/ssrf.php`.
- Not part of the runner (they need the network or the Google API, read-only): `tests/compare.php` (compares results with the old
  Node server) and `tests/plans-d2.php` (reads a real PMax snapshot). Run them by hand when you have a dev connection to Google.
- New tests: add `tests/<topic>.php` that requires `tests/_env.php`, calls `make_data_dir()`, prints `OK`/`FAIL` lines through
  `checker()` and exits non-zero on failure. Controllers and the router run in child processes (`call_controller()`, `tests/_child.php`).
  CLI limitation: PHP keeps no response headers in the CLI, so tests assert the status code, the body and the database.
- `tools/get-dev-tools.sh` downloads `pint.phar` and `phpstan.phar` into `tools/bin/` (git-ignored). Then:
  `php tools/bin/pint.phar --test` checks the style (without `--test` it fixes files; `pint.json` = PSR-12 minus the repo's own habits),
  `php tools/bin/phpstan.phar analyse` runs static analysis (level 3, `phpstan.neon`; known older findings are in `phpstan-baseline.neon`).
- CI (`.github/workflows/ci.yml`) runs `php -l`, `tests/run.php`, PHPStan and Pint on every push; `tools/deploy.sh` runs `php -l` and `tests/run.php` first.

## License

[MIT](LICENSE)
