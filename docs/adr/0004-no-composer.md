# 4. No Composer and no framework

Status: accepted (2026-11)

## Context
No shell on the hosting, so `composer install` cannot run there; uploading `vendor/` over FTP is slow and hard to audit.

## Decision
Plain PHP 8.4 with a small PSR-4-like autoloader (`App\X\Y` maps to `app/X/Y.php`), a simple router and thin layers
(controller, service, data). Dependencies are PHP extensions only (sodium, pdo_sqlite, curl).

## Consequences
- Small attack surface, nothing to update except PHP itself.
- Layering and naming are kept close to Laravel to ease a later migration.
- Development tools (linters, static analysis) run in CI or locally, never on the server.

## Alternatives considered
Laravel or Slim with a vendored `vendor/`: larger upload and a dependency audit burden for a small server.
