# 1. Separate public and private directories

Status: accepted (2026-11)

## Context
The server runs on shared cPanel hosting. Anything inside the web root can be requested over HTTP, including a misconfigured
`.htaccess` or a missing PHP handler that would expose source, `config.php` or the SQLite file.

## Decision
Only `webroot/` (front controller, `.htaccess`, assets) is public. `app/`, the data directory (`config.php`, `app.sqlite`, logs,
uploads), `tests/` and `tools/` live in a private directory next to the web root. `webroot/index.php` finds the private directory.

## Consequences
- A web server failure cannot leak code or data; the data directory also has `Require all denied` as a second barrier.
- Dev and production use the same layout; dev publishes `webroot/` with `bin/publish-public.sh`.
- Deployment must upload two trees (`app/` and `webroot/`).

## Alternatives considered
Everything in the web root with `.htaccess` deny rules: one mistake exposes secrets.
